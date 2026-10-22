import { HttpError, text } from '../validation.js';

function validateGuests(body) {
  if (!Number.isSafeInteger(body.revision) || body.revision < 0)
    throw new HttpError(422, 'Versi daftar tamu tidak valid.');
  if (!Array.isArray(body.guests) || body.guests.length > 5000)
    throw new HttpError(422, 'Daftar tamu maksimal 5.000 nama per undangan.');
  const ids = new Set();
  const names = new Set();
  return body.guests.map((guest, index) => {
    const field = `Tamu baris ${index + 1}`;
    if (!guest || typeof guest !== 'object' || Array.isArray(guest))
      throw new HttpError(422, `${field} tidak valid.`);
    const id = text(guest.id, `${field}: ID`, 36);
    if (!/^[a-zA-Z0-9-]{1,36}$/.test(id) || ids.has(id))
      throw new HttpError(422, `${field}: ID harus unik dan valid.`);
    ids.add(id);
    const name = text(guest.name, `${field}: nama`, 80).replace(/\s+/g, ' ');
    const key = name.normalize('NFKC').toLowerCase();
    if (names.has(key))
      throw new HttpError(
        422,
        `${field}: nama "${name}" sudah ada. Tambahkan pembeda jika orangnya berbeda.`,
      );
    names.add(key);
    const phone = text(guest.phone ?? '', `${field}: WhatsApp`, 30, true);
    if (phone && !/^\+?[\d\s()-]{6,30}$/.test(phone))
      throw new HttpError(422, `${field}: nomor WhatsApp tidak valid.`);
    if (guest.sent !== undefined && typeof guest.sent !== 'boolean')
      throw new HttpError(422, `${field}: status kirim tidak valid.`);
    return {
      id,
      name,
      phone,
      group: text(guest.group ?? '', `${field}: kelompok`, 60, true),
      note: text(guest.note ?? '', `${field}: catatan`, 200, true),
      sent: guest.sent ?? false,
    };
  });
}

export default class GuestListController {
  constructor(model) {
    this.model = model;
  }

  async invitation(id) {
    const invitation = await this.model.invitation(id);
    if (!invitation) throw new HttpError(404, 'Undangan tidak ditemukan.');
    return invitation;
  }

  async find(id) {
    const invitation = await this.invitation(id);
    const row = await this.model.find(id);
    return {
      status: 200,
      data: {
        invitation,
        guests: row ? JSON.parse(row.data) : [],
        revision: row?.revision ?? 0,
        updatedAt: row?.updated_at ?? null,
      },
    };
  }

  async update(id, body) {
    await this.invitation(id);
    const guests = validateGuests(body);
    const updatedAt = new Date().toISOString();
    if (!(await this.model.save(id, guests, body.revision, updatedAt)))
      throw new HttpError(
        409,
        'Daftar tamu berubah di tab lain. Ekspor perubahan Anda sebagai cadangan, lalu muat ulang dan impor kembali.',
      );
    return {
      status: 200,
      data: { revision: body.revision + 1, updatedAt },
    };
  }
}
