import { randomUUID } from 'node:crypto';
import { HttpError, pagination, text } from '../validation.js';
import { createHash } from 'node:crypto';

export default class WishController {
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
    const message = text(body.message, 'message', 600);
    const id = randomUUID();
    const createdAt = new Date().toISOString();
    const guestKey = createHash('sha256')
      .update(name.normalize('NFKC').toLowerCase().replace(/\s+/g, ' '))
      .digest('hex');
    const { wish, updated } = await this.model.save(
      id,
      invitationId,
      guestKey,
      name,
      message,
      createdAt,
    );
    return { status: updated ? 200 : 201, data: wish, meta: { updated } };
  }
  async remove(id) {
    if (!(await this.model.remove(id)).changes)
      throw new HttpError(404, 'Ucapan tidak ditemukan.');
    return { status: 200, data: { message: 'Ucapan telah dihapus.' } };
  }
}
