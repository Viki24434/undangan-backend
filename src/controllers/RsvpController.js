import { randomUUID } from 'node:crypto';
import { HttpError, pagination, text } from '../validation.js';

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
    const id = randomUUID();
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
