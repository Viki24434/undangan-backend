import { HttpError, invitationInput, text } from './validation.js';
import { validateGuests } from './controllers/GuestListController.js';

export function mapSubmissionData(value, replace, depth = 0) {
  if (depth > 15) throw new HttpError(422, 'Struktur data terlalu dalam.');
  if (typeof value === 'string') return replace(value);
  if (Array.isArray(value))
    return value.map((item) => mapSubmissionData(item, replace, depth + 1));
  if (value && typeof value === 'object') {
    const result = {};
    for (const [key, item] of Object.entries(value)) {
      if (['__proto__', 'prototype', 'constructor'].includes(key))
        throw new HttpError(422, 'Nama field tidak valid.');
      result[key] = mapSubmissionData(item, replace, depth + 1);
    }
    return result;
  }
  return value;
}

export function submissionInput(body) {
  const id = text(body.id, 'ID kiriman', 36);
  const token = text(body.token, 'Token kiriman', 64);
  if (!/^[a-f0-9-]{36}$/.test(id) || !/^[a-f0-9]{64}$/.test(token))
    throw new HttpError(422, 'Identitas kiriman tidak valid.');
  const contact = {
    name: text(body.contact?.name, 'Nama pengirim', 80),
    phone: text(body.contact?.phone, 'WhatsApp pengirim', 30),
    note: text(body.contact?.note ?? '', 'Catatan untuk admin', 1000, true),
  };
  if (
    !/^\+?[\d\s()-]{8,30}$/.test(contact.phone) ||
    !/^\d{8,15}$/.test(contact.phone.replace(/\D/g, ''))
  )
    throw new HttpError(422, 'Nomor WhatsApp pengirim tidak valid.');
  if (!Array.isArray(body.assets) || body.assets.length > 80)
    throw new HttpError(422, 'Maksimal 80 gambar per kiriman.');
  const hashes = new Set();
  let total = 0;
  const assets = body.assets.map((asset) => {
    if (
      !asset ||
      !/^[a-f0-9]{64}$/.test(asset.hash) ||
      hashes.has(asset.hash) ||
      !['image/jpeg', 'image/png', 'image/webp'].includes(asset.mime) ||
      !Number.isInteger(asset.size) ||
      asset.size < 1 ||
      asset.size > 5 * 1024 * 1024
    )
      throw new HttpError(422, 'Daftar gambar tidak valid.');
    hashes.add(asset.hash);
    total += asset.size;
    return { hash: asset.hash, size: asset.size, mime: asset.mime };
  });
  if (total > 40 * 1024 * 1024)
    throw new HttpError(422, 'Total gambar maksimal 40 MB.');
  const used = new Set();
  let nodes = 0;
  if (
    !body.form?.data ||
    typeof body.form.data !== 'object' ||
    Array.isArray(body.form.data)
  )
    throw new HttpError(422, 'Isi undangan harus berupa objek.');
  const cleanData = mapSubmissionData(body.form?.data, (value) => {
    nodes += 1;
    if (nodes > 15000 || value.length > 10000)
      throw new HttpError(422, 'Isi undangan terlalu besar.');
    if (/^(data|blob|javascript|vbscript):/i.test(value))
      throw new HttpError(
        422,
        'Gambar lokal harus dikirim melalui unggahan gambar.',
      );
    if (value.startsWith('customer-image:')) {
      const hash = value.slice('customer-image:'.length);
      if (!hashes.has(hash))
        throw new HttpError(422, 'Referensi gambar tidak valid.');
      used.add(hash);
    }
    return value;
  });
  if (used.size !== hashes.size)
    throw new HttpError(422, 'Ada gambar yang tidak digunakan dalam undangan.');
  const validationData = mapSubmissionData(cleanData, (value) =>
    value.startsWith('customer-image:')
      ? `https://submission.invalid/${value.slice(15)}.jpg`
      : value,
  );
  const validated = invitationInput({
    ...body.form,
    data: validationData,
    slug: 'customer-submission',
    status: 'draft',
  });
  const guests = validateGuests({
    guests: body.guestList?.guests,
    revision: 0,
  });
  const messageTemplate = text(
    body.guestList?.messageTemplate,
    'Pesan WhatsApp',
    6000,
  );
  if (!messageTemplate.includes('{link_undangan}'))
    throw new HttpError(
      422,
      'Pesan WhatsApp harus memiliki tag {link_undangan}.',
    );
  const data = {
    contact,
    form: {
      templateId: validated.templateId,
      title: validated.title,
      eventDate: validated.eventDate,
      data: cleanData,
    },
    guestList: {
      guests,
      messageTemplate,
      invitationUrl: text(
        body.guestList?.invitationUrl ?? '',
        'Link referensi customer',
        2000,
        true,
      ),
    },
    assets,
  };
  if (Buffer.byteLength(JSON.stringify(data.guestList)) > 2097152)
    throw new HttpError(413, 'Daftar tamu maksimal 2 MB.');
  return { id, token, data };
}
