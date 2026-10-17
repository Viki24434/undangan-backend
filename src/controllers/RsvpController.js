import { HttpError, pagination, text } from '../validation.js';
import { createHash } from 'node:crypto';

export default class RsvpController {
  constructor(model) {
    this.model = model;
  }
  async list(invitationId, searchParams) {
    const { limit, offset } = pagination(searchParams);
    return {
      status: 200,
      data: await this.model.list(invitationId, limit, offset),
      meta: {
        total: (await this.model.count(invitationId)).count,
        limit,
        offset,
      },
    };
  }
  async create(invitationId, body) {
    const name = text(body.name, 'name', 80);
    const attendance = body.attendance;
    if (!['Hadir', 'Belum pasti', 'Tidak hadir'].includes(attendance))
      throw new HttpError(
        422,
        'attendance harus Hadir, Belum pasti, atau Tidak hadir.',
      );
    const guestCount =
      body.guestCount ?? (attendance === 'Tidak hadir' ? 0 : 1);
    if (
      !Number.isInteger(guestCount) ||
      guestCount < 0 ||
      guestCount > 20 ||
      (attendance === 'Tidak hadir' && guestCount !== 0) ||
      (attendance === 'Hadir' && guestCount < 1)
    )
      throw new HttpError(
        422,
        'guestCount tidak sesuai dengan kehadiran atau di luar 0–20.',
      );
    const note = text(body.note, 'note', 600, true);
    const guestKey = createHash('sha256')
      .update(
        `${invitationId}:${name.normalize('NFKC').toLowerCase().replace(/\s+/g, ' ')}`,
      )
      .digest('hex');
    const id = [
      guestKey.slice(0, 8),
      guestKey.slice(8, 12),
      guestKey.slice(12, 16),
      guestKey.slice(16, 20),
      guestKey.slice(20, 32),
    ].join('-');
    await this.model.create(
      id,
      invitationId,
      name,
      attendance,
      guestCount,
      note,
      new Date().toISOString(),
    );
    return {
      status: 201,
      data: { id, message: 'Konfirmasi kehadiran berhasil disimpan.' },
    };
  }
}
