import { HttpError } from '../validation.js';

export default class TemplateController {
  constructor(model) {
    this.model = model;
  }
  async find(id) {
    const row = await this.model.find(id);
    if (!row) throw new HttpError(404, 'Template tidak ditemukan.');
    return {
      status: 200,
      data: {
        id: row.id,
        name: row.name,
        category: row.category,
        description: row.description,
        img: row.image,
        data: JSON.parse(row.data),
      },
    };
  }
  async list(category) {
    const rows = await this.model.list(category);
    return {
      status: 200,
      data: rows.map((row) => ({
        id: row.id,
        name: row.name,
        category: row.category,
        description: row.description,
        img: row.image,
      })),
    };
  }
}
