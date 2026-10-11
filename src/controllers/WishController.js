import { randomUUID } from 'node:crypto';
import { HttpError, pagination, text } from '../validation.js';

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
    await this.model.create(id, invitationId, name, message, createdAt);
    return { status: 201, data: { id, name, message, createdAt } };
  }
  async remove(id) {
    if (!(await this.model.remove(id)).changes)
      throw new HttpError(404, 'Ucapan tidak ditemukan.');
    return { status: 200, data: { message: 'Ucapan telah dihapus.' } };
  }
}
