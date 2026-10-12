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
        packageId: row.package_id,
        description: row.description,
        img: row.image,
        data: JSON.parse(row.data),
      },
    };
  }
  async updatePackage(id, body) {
    if (
      !['silver', 'gold', 'platinum', 'exclusive'].includes(body?.packageId)
    ) {
      throw new HttpError(422, 'Paket template tidak valid.');
    }
    if (!(await this.model.find(id)))
      throw new HttpError(404, 'Template tidak ditemukan.');
    await this.model.updatePackage(id, body.packageId);
    return this.find(id);
  }
  async list(category) {
    const rows = await this.model.list(category);
    return {
      status: 200,
      data: rows.map((row) => ({
        id: row.id,
        name: row.name,
        category: row.category,
        packageId: row.package_id,
        description: row.description,
        img: row.image,
      })),
    };
  }
}
