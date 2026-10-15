import { unlink } from 'node:fs/promises';
import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { createApp } from '../src/app.js';
import { openDatabase } from '../src/database.js';
import { databaseConfig } from '../src/config.js';
import mysql from 'mysql2/promise';
import { randomBytes } from 'node:crypto';

const token = 'a-test-admin-token-with-at-least-32-characters';
const input = {
  slug: 'aditya-sarah',
  templateId: 'elegant-gold-01',
  title: 'Aditya & Sarah',
  eventDate: '2027-01-24T09:00:00+07:00',
  data: {
    groom: { name: 'Aditya' },
    bride: { name: 'Sarah' },
    photos: [
      'https://images.example.test/hero.jpg',
      'https://images.example.test/gallery.jpg',
    ],
    sunlightEnabled: false,
    agenda: [
      { time: '09.00', title: 'Akad', description: 'Prosesi akad nikah' },
    ],
    videoUrl: 'https://youtu.be/abcdefghijk',
    prayers: [
      {
        title: 'Doa untuk kedua mempelai',
        text: 'Semoga menjadi keluarga yang sakinah.',
        arabic: '',
        source: 'Doa bersama',
      },
    ],
  },
};

async function fixture(t) {
  const database = `undangan_test_${randomBytes(6).toString('hex')}`;
  const db = await openDatabase({ ...databaseConfig, database });
  const server = createServer(
    createApp({
      db,
      adminToken: token,
      origins: ['http://localhost:5173'],
      onError: (error) => {
        throw error;
      },
    }),
  );
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  t.after(async () => {
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
    await db.close();
    const { database: ignored, connectionLimit, ...config } = databaseConfig;
    const connection = await mysql.createConnection(config);
    try {
      await connection.query('DROP DATABASE ' + database);
    } finally {
      await connection.end();
    }
  });
  async function request(
    path,
    {
      method = 'GET',
      body,
      rawBody,
      binary = false,
      admin = false,
      headers = {},
    } = {},
  ) {
    const response = await fetch(
      `http://127.0.0.1:${server.address().port}/api${path}`,
      {
        method,
        headers: {
          ...(body !== undefined && { 'Content-Type': 'application/json' }),
          ...(admin && { Authorization: `Bearer ${token}` }),
          ...headers,
        },
        body:
          rawBody ?? (body === undefined ? undefined : JSON.stringify(body)),
      },
    );
    return {
      status: response.status,
      headers: response.headers,
      body:
        response.status === 204
          ? null
          : binary
            ? Buffer.from(await response.arrayBuffer())
            : await response.json(),
    };
  }
  return { request, db, database };
}

test('templates, authenticated CRUD, draft privacy, wishes, RSVP, moderation, cascade deletion', async (t) => {
  const { request, db } = await fixture(t);
  assert.equal((await request('/health')).status, 200);
  const templates = (await request('/templates')).body.data;
  assert.equal(templates.length, 33);
  assert.ok(templates.some(({ id }) => id === 'burgundy-vow-01'));
  assert.ok(templates.some(({ id }) => id === 'pink-blush-01'));
  assert.ok(templates.some(({ id }) => id === 'sunda-siger-01'));
  assert.ok(templates.some(({ id }) => id === 'jawa-paes-01'));
  assert.equal(
    (await request('/templates?category=Ulang%20tahun')).body.data.length,
    8,
  );
  assert.equal((await request('/admin/invitations')).status, 401);
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: { ...input, templateId: 'unknown' },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: {
          ...input,
          slug: 'invalid-video',
          data: { ...input.data, videoUrl: 'javascript:alert(1)' },
        },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: {
          ...input,
          slug: 'invalid-agenda',
          data: { ...input.data, agenda: [{ time: '', title: '' }] },
        },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: {
          ...input,
          slug: 'invalid-photo-url',
          data: {
            ...input.data,
            photos: ['http://images.example.test/photo.jpg'],
          },
        },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: {
          ...input,
          slug: 'too-many-photos',
          data: {
            ...input.data,
            photos: Array.from(
              { length: 31 },
              (_, index) => `https://images.example.test/${index}.jpg`,
            ),
          },
        },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: {
          ...input,
          slug: 'invalid-sunlight-setting',
          data: { ...input.data, sunlightEnabled: 'off' },
        },
      })
    ).status,
    422,
  );
  const created = await request('/admin/invitations', {
    admin: true,
    method: 'POST',
    body: input,
  });
  assert.equal(created.status, 201);
  const id = created.body.data.id;
  assert.equal((await request('/invitations/aditya-sarah')).status, 404);
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: input,
      })
    ).status,
    409,
  );
  assert.equal(
    (
      await request(`/admin/invitations/${id}`, {
        admin: true,
        method: 'PATCH',
        body: { status: 'published' },
      })
    ).status,
    200,
  );
  const publicInvitation = await request('/invitations/aditya-sarah');
  assert.equal(publicInvitation.body.data.data.groom.name, 'Aditya');
  assert.deepEqual(publicInvitation.body.data.data.agenda, input.data.agenda);
  assert.equal(publicInvitation.body.data.data.videoUrl, input.data.videoUrl);
  assert.deepEqual(publicInvitation.body.data.data.prayers, input.data.prayers);
  assert.deepEqual(publicInvitation.body.data.data.photos, input.data.photos);
  assert.equal(publicInvitation.body.data.data.sunlightEnabled, false);
  const legacy = await request('/admin/invitations', {
    admin: true,
    method: 'POST',
    body: {
      ...input,
      slug: 'legacy-no-optional-sections',
      data: { groom: input.data.groom, bride: input.data.bride },
    },
  });
  assert.equal(legacy.status, 201);
  assert.deepEqual(legacy.body.data.data.agenda, []);
  assert.equal(legacy.body.data.data.videoUrl, '');
  assert.deepEqual(legacy.body.data.data.prayers, []);
  assert.deepEqual(legacy.body.data.data.photos, []);
  assert.equal(legacy.body.data.data.sunlightEnabled, true);
  const wish = await request('/invitations/aditya-sarah/wishes', {
    method: 'POST',
    body: { name: 'Rina', message: 'Semoga bahagia!' },
  });
  assert.equal(wish.status, 201);
  assert.equal(
    (await request('/invitations/aditya-sarah/wishes?limit=1')).body.meta.total,
    1,
  );
  assert.equal(
    (
      await request('/invitations/aditya-sarah/rsvps', {
        method: 'POST',
        body: { name: 'Rina', attendance: 'Hadir', guestCount: 2 },
      })
    ).status,
    201,
  );
  assert.equal((await request('/invitations/aditya-sarah/rsvps')).status, 404);
  const rsvps = await request(`/admin/invitations/${id}/rsvps`, {
    admin: true,
  });
  assert.equal(rsvps.body.data[0].guestCount, 2);
  assert.equal(
    (
      await request(`/admin/wishes/${wish.body.data.id}`, {
        admin: true,
        method: 'DELETE',
      })
    ).status,
    200,
  );
  assert.equal(
    (await request('/invitations/aditya-sarah/wishes')).body.meta.total,
    0,
  );
  assert.equal(
    (
      await request(`/admin/invitations/${id}`, {
        admin: true,
        method: 'DELETE',
      })
    ).status,
    200,
  );
  assert.equal(
    (await db.prepare('SELECT COUNT(*) AS count FROM rsvps').get()).count,
    0,
  );
});

test('CORS, validation, payload limits, privacy and request throttling', async (t) => {
  const { request } = await fixture(t);
  assert.equal(
    (
      await request('/health', {
        headers: { Origin: 'https://unknown.example' },
      })
    ).status,
    403,
  );
  const preflight = await request('/health', {
    method: 'OPTIONS',
    headers: { Origin: 'http://localhost:5173' },
  });
  assert.equal(preflight.status, 204);
  assert.equal(
    preflight.headers.get('access-control-allow-origin'),
    'http://localhost:5173',
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: { ...input, eventDate: '2027-02-30T09:00:00+07:00' },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: { ...input, slug: '../bad' },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        admin: true,
        method: 'POST',
        body: { ...input, data: { content: 'a'.repeat(33000) } },
      })
    ).status,
    413,
  );
  const created = await request('/admin/invitations', {
    admin: true,
    method: 'POST',
    body: { ...input, status: 'published' },
  });
  assert.equal(created.status, 201);
  assert.equal(
    (await request('/invitations/aditya-sarah/wishes?limit=999')).status,
    422,
  );
  assert.equal(
    (
      await request('/invitations/aditya-sarah/wishes', {
        method: 'POST',
        body: { name: '  ', message: 'hello' },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/invitations/aditya-sarah/rsvps', {
        method: 'POST',
        body: { name: 'Rina', attendance: 'Tidak hadir', guestCount: 2 },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/invitations/aditya-sarah/wishes', {
        method: 'POST',
        body: { name: 'Rina', message: 'hello' },
        headers: { 'Content-Type': 'text/plain' },
      })
    ).status,
    415,
  );
  for (let count = 0; count < 17; count++)
    assert.equal(
      (
        await request('/invitations/aditya-sarah/wishes', {
          method: 'POST',
          body: { name: 'Rina', message: 'hello' },
        })
      ).status,
      201,
    );
  const limited = await request('/invitations/aditya-sarah/wishes', {
    method: 'POST',
    body: { name: 'Rina', message: 'hello' },
  });
  assert.equal(limited.status, 429);
  assert.equal(limited.headers.get('retry-after'), '60');
});

test('MySQL records remain available through a new connection pool', async (t) => {
  const { request, database } = await fixture(t);
  const created = await request('/admin/invitations', {
    admin: true,
    method: 'POST',
    body: input,
  });
  assert.equal(created.status, 201);
  const reopened = await openDatabase({ ...databaseConfig, database });
  try {
    const row = await reopened
      .prepare('SELECT title FROM invitations WHERE id=?')
      .get(created.body.data.id);
    assert.equal(row.title, input.title);
  } finally {
    await reopened.close();
  }
});

test('first admin setup, session cookie, login, CSRF, expiry and logout', async (t) => {
  const { request, db } = await fixture(t);
  const info = await request('/auth/session');
  assert.equal(info.body.data.setupRequired, true);
  assert.equal(info.body.data.user, null);
  const body = {
    name: 'Admin Test',
    email: 'admin@example.test',
    password: 'very-strong-test-password',
  };
  const setup = await request('/auth/setup', { method: 'POST', body });
  assert.equal(setup.status, 200);
  const cookie = setup.headers.get('set-cookie');
  assert.ok(cookie.includes('HttpOnly'));
  assert.ok(cookie.includes('SameSite=Lax'));
  assert.ok(cookie.includes('Path=/api'));
  const sessionCookie = cookie.split(';')[0];
  const csrf = setup.body.data.csrfToken;
  assert.equal(
    (await request('/auth/setup', { method: 'POST', body })).status,
    409,
  );
  const stored = await db
    .prepare("SELECT password_hash FROM admins WHERE id='primary-admin'")
    .get();
  assert.ok(!stored.password_hash.includes(body.password));
  assert.equal(
    (
      await request('/admin/invitations', {
        headers: { Cookie: sessionCookie },
      })
    ).status,
    200,
  );
  assert.equal(
    (await request('/auth/session', { headers: { Cookie: sessionCookie } }))
      .body.data.user.email,
    body.email,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        method: 'POST',
        body: input,
        headers: { Cookie: sessionCookie },
      })
    ).status,
    403,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        method: 'POST',
        body: input,
        headers: { Cookie: sessionCookie, 'X-CSRF-Token': 'bad-token' },
      })
    ).status,
    403,
  );
  assert.equal(
    (
      await request('/admin/invitations', {
        method: 'POST',
        body: input,
        headers: { Cookie: sessionCookie, 'X-CSRF-Token': csrf },
      })
    ).status,
    201,
  );
  assert.equal(
    (
      await request('/auth/login', {
        method: 'POST',
        body: { ...body, password: 'incorrect-password' },
      })
    ).status,
    401,
  );
  const login = await request('/auth/login', { method: 'POST', body });
  assert.equal(login.status, 200);
  const loginCookie = login.headers.get('set-cookie').split(';')[0];
  assert.notEqual(loginCookie, sessionCookie);
  const logout = await request('/auth/logout', {
    method: 'POST',
    headers: { Cookie: loginCookie, 'X-CSRF-Token': login.body.data.csrfToken },
  });
  assert.equal(logout.status, 200);
  assert.ok(logout.headers.get('set-cookie').includes('Max-Age=0'));
  assert.equal(
    (await request('/admin/invitations', { headers: { Cookie: loginCookie } }))
      .status,
    401,
  );
  await db.prepare('UPDATE admin_sessions SET expires_at=0').run();
  assert.equal(
    (
      await request('/admin/invitations', {
        headers: { Cookie: sessionCookie },
      })
    ).status,
    401,
  );
  assert.equal(
    (
      await request('/auth/login', {
        method: 'POST',
        body,
        headers: { Origin: 'https://unknown.example' },
      })
    ).status,
    403,
  );
});

test('template details come from MySQL and preserve saved data on initialization', async (t) => {
  const { request, db, database } = await fixture(t);
  const list = (await request('/templates')).body.data;
  for (const template of list) {
    const result = await request(`/templates/${template.id}`);
    assert.equal(result.status, 200);
    assert.ok(result.body.data.data.targetDate);
    assert.ok(result.body.data.data.events.length);
  }
  assert.equal((await request('/templates/missing')).status, 404);
  const id = list[0].id;
  const saved = {
    title: 'Template dari database',
    targetDate: '2027-01-01T00:00:00Z',
  };
  await db
    .prepare('UPDATE templates SET data=? WHERE id=?')
    .run(JSON.stringify(saved), id);
  const reopened = await openDatabase({ ...databaseConfig, database });
  await reopened.close();
  const reopenedData = (await request(`/templates/${id}`)).body.data.data;
  assert.equal(reopenedData.title, saved.title);
  assert.equal(reopenedData.targetDate, saved.targetDate);
  assert.deepEqual(reopenedData.agenda, []);
  assert.equal(reopenedData.videoUrl, '');
  assert.deepEqual(reopenedData.prayers, []);
});

test('template packages require admin access and persist after database initialization', async (t) => {
  const { request, database } = await fixture(t);
  const list = (await request('/templates')).body.data;
  for (const template of list) {
    assert.equal(
      template.packageId,
      template.category === 'Pernikahan' ? 'gold' : 'silver',
    );
  }
  const path = '/admin/templates/emerald-khitan-01';
  assert.equal(
    (await request(path, { method: 'PATCH', body: { packageId: 'gold' } }))
      .status,
    401,
  );
  assert.equal(
    (
      await request(path, {
        method: 'PATCH',
        admin: true,
        body: { packageId: 'unknown' },
      })
    ).status,
    422,
  );
  for (const packageId of ['gold', 'platinum', 'exclusive', 'silver']) {
    const result = await request(path, {
      method: 'PATCH',
      admin: true,
      body: { packageId },
    });
    assert.equal(result.status, 200);
    assert.equal(result.body.data.packageId, packageId);
  }
  const existingTemplate = (
    await request('/templates/emerald-khitan-01')
  ).body.data;
  assert.equal(
    (
      await request(path, {
        method: 'PATCH',
        body: { data: existingTemplate.data },
      })
    ).status,
    401,
  );
  assert.equal(
    (
      await request(path, {
        method: 'PATCH',
        admin: true,
        body: {
          data: { ...existingTemplate.data, theme: { background: 'red' } },
        },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request(path, {
        method: 'PATCH',
        admin: true,
        body: {
          data: { ...existingTemplate.data, theme: { font: 'custom-css' } },
        },
      })
    ).status,
    422,
  );
  const updatedData = {
    ...existingTemplate.data,
    honoree: 'Nama default yang diperbarui',
    theme: {
      background: '#123456',
      accent: '#abcdef',
      font: 'classic',
    },
  };
  const update = await request(path, {
    method: 'PATCH',
    admin: true,
    body: { data: updatedData },
  });
  assert.equal(update.status, 200);
  assert.equal(update.body.data.data.honoree, updatedData.honoree);
  assert.deepEqual(update.body.data.data.theme, updatedData.theme);
  await request(path, {
    method: 'PATCH',
    admin: true,
    body: { packageId: 'gold' },
  });
  const reopened = await openDatabase({ ...databaseConfig, database });
  await reopened.close();
  assert.equal(
    (await request('/templates/emerald-khitan-01')).body.data.packageId,
    'gold',
  );
  const persisted = (await request('/templates/emerald-khitan-01')).body.data;
  assert.equal(persisted.data.honoree, updatedData.honoree);
  assert.deepEqual(persisted.data.theme, updatedData.theme);
  assert.equal(
    (await request('/templates')).body.data.find(
      (item) => item.id === 'emerald-khitan-01',
    ).packageId,
    'gold',
  );
});

test('social links are public to read, admin-only to update, validated and persistent', async (t) => {
  const { request, database } = await fixture(t);
  const initial = await request('/social-links');
  assert.equal(initial.status, 200);
  assert.deepEqual(initial.body.data, {
    instagram: '',
    tiktok: '',
    whatsapp: '',
  });
  const path = '/admin/social-links';
  const links = {
    instagram: 'https://www.instagram.com/ciptain',
    tiktok: 'https://www.tiktok.com/@ciptain',
    whatsapp: '6281234567890',
  };
  assert.equal(
    (await request(path, { method: 'PATCH', body: links })).status,
    401,
  );
  assert.equal(
    (
      await request(path, {
        method: 'PATCH',
        admin: true,
        body: { ...links, instagram: 'https://evil.example/ciptain' },
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request(path, {
        method: 'PATCH',
        admin: true,
        body: { ...links, whatsapp: '+6281234567890' },
      })
    ).status,
    422,
  );
  const saved = await request(path, {
    method: 'PATCH',
    admin: true,
    body: links,
  });
  assert.equal(saved.status, 200);
  assert.deepEqual(saved.body.data, links);
  const reopened = await openDatabase({ ...databaseConfig, database });
  await reopened.close();
  assert.deepEqual((await request('/social-links')).body.data, links);
});

test('package prices validate rupiah, require admin and keep Exclusive custom', async (t) => {
  const { request, database } = await fixture(t);
  const initial = (await request('/packages')).body.data;
  assert.equal(initial.length, 4);
  assert.ok(initial.every((item) => item.price === null));
  assert.equal(
    initial.find((item) => item.id === 'exclusive').priceMode,
    'custom',
  );
  const path = '/admin/packages/silver';
  assert.equal(
    (await request(path, { method: 'PATCH', body: { price: 125000 } })).status,
    401,
  );
  for (const price of [-1, 2.5, '125000', 1000000000001]) {
    assert.equal(
      (await request(path, { method: 'PATCH', admin: true, body: { price } }))
        .status,
      422,
    );
  }
  assert.equal(
    (await request(path, { method: 'PATCH', admin: true, body: {} })).status,
    422,
  );
  assert.equal(
    (
      await request('/admin/packages/missing', {
        method: 'PATCH',
        admin: true,
        body: { price: 100 },
      })
    ).status,
    404,
  );
  assert.equal(
    (
      await request('/admin/packages/exclusive', {
        method: 'PATCH',
        admin: true,
        body: { price: 100 },
      })
    ).status,
    422,
  );
  for (const id of ['silver', 'gold', 'platinum']) {
    const result = await request('/admin/packages/' + id, {
      method: 'PATCH',
      admin: true,
      body: { price: 125000 },
    });
    assert.equal(result.status, 200);
    assert.equal(result.body.data.price, 125000);
  }
  const reopened = await openDatabase({ ...databaseConfig, database });
  await reopened.close();
  const saved = (await request('/packages')).body.data;
  assert.equal(saved.find((item) => item.id === 'silver').price, 125000);
  assert.equal(saved.find((item) => item.id === 'exclusive').price, null);
  assert.equal(
    (await request(path, { method: 'PATCH', admin: true, body: { price: 0 } }))
      .body.data.price,
    0,
  );
  assert.equal(
    (
      await request(path, {
        method: 'PATCH',
        admin: true,
        body: { price: null },
      })
    ).body.data.price,
    null,
  );
});

test('image files upload separately from invitation save and are served without authentication', async (t) => {
  const { request } = await fixture(t);
  const image = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK1sAAAAASUVORK5CYII=',
    'base64',
  );
  const options = {
    method: 'POST',
    rawBody: image,
    headers: { 'Content-Type': 'image/png' },
  };
  assert.equal((await request('/admin/uploads', options)).status, 401);
  assert.equal(
    (
      await request('/admin/uploads', {
        ...options,
        admin: true,
        rawBody: Buffer.from('not an image'),
      })
    ).status,
    422,
  );
  assert.equal(
    (
      await request('/admin/uploads', {
        ...options,
        admin: true,
        headers: { 'Content-Type': 'image/svg+xml' },
      })
    ).status,
    415,
  );
  const upload = await request('/admin/uploads', { ...options, admin: true });
  assert.equal(upload.status, 201);
  const path = upload.body.data.url;
  const filename = path.split('/').pop();
  t.after(() =>
    unlink(new URL('../data/uploads/' + filename, import.meta.url)),
  );
  const stored = await request(path.replace('/api', ''), { binary: true });
  assert.equal(stored.status, 200);
  assert.equal(stored.headers.get('content-type'), 'image/png');
  assert.deepEqual(stored.body, image);
  const created = await request('/admin/invitations', {
    method: 'POST',
    admin: true,
    body: { ...input, data: { ...input.data, image: path, photos: [path] } },
  });
  assert.equal(created.status, 201);
  assert.equal(created.body.data.data.photos[0], path);
  const saved = await request('/admin/invitations/' + created.body.data.id, {
    method: 'PATCH',
    admin: true,
    body: { title: 'Sesudah upload' },
  });
  assert.equal(saved.status, 200);
  assert.equal(saved.body.data.data.photos[0], path);
  assert.deepEqual(
    (await request(path.replace('/api', ''), { binary: true })).body,
    image,
  );
  assert.equal((await request('/media/not-a-file.jpg')).status, 404);
});
