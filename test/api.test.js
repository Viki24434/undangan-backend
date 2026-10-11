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
  data: { groom: { name: 'Aditya' }, bride: { name: 'Sarah' } },
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
    { method = 'GET', body, admin = false, headers = {} } = {},
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
        body: body === undefined ? undefined : JSON.stringify(body),
      },
    );
    return {
      status: response.status,
      headers: response.headers,
      body: response.status === 204 ? null : await response.json(),
    };
  }
  return { request, db, database };
}

test('templates, authenticated CRUD, draft privacy, wishes, RSVP, moderation, cascade deletion', async (t) => {
  const { request, db } = await fixture(t);
  assert.equal((await request('/health')).status, 200);
  assert.equal((await request('/templates')).body.data.length, 10);
  assert.equal(
    (await request('/templates?category=Ulang%20tahun')).body.data.length,
    2,
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
  assert.deepEqual((await request(`/templates/${id}`)).body.data.data, saved);
});
