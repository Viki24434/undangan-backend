import { HttpError } from '../validation.js';

const templateFonts = ['default', 'classic', 'modern'];

function templateDataInput(data) {
  if (
    typeof data !== 'object' ||
    data === null ||
    Array.isArray(data) ||
    Buffer.byteLength(JSON.stringify(data), 'utf8') > 500_000
  ) {
    throw new HttpError(422, 'Data template tidak valid atau terlalu besar.');
  }
  const theme = data.theme ?? {};
  if (typeof theme !== 'object' || theme === null || Array.isArray(theme))
    throw new HttpError(422, 'Tema template tidak valid.');
  for (const key of ['background', 'accent']) {
    if (
      theme[key] !== undefined &&
      (typeof theme[key] !== 'string' ||
        !/^#[\da-f]{6}$/i.test(theme[key]))
    ) {
      throw new HttpError(422, `Warna tema ${key} harus berupa kode hex.`);
    }
  }
  if (
    theme.font !== undefined &&
    !templateFonts.includes(theme.font)
  )
    throw new HttpError(422, 'Font template tidak valid.');
  return {
    ...data,
    theme: {
      ...(theme.background && { background: theme.background }),
      ...(theme.accent && { accent: theme.accent }),
      ...(theme.font && { font: theme.font }),
    },
  };
}

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
  async update(id, body) {
    const current = await this.model.find(id);
    if (!current) throw new HttpError(404, 'Template tidak ditemukan.');
    const data =
      body?.data === undefined ? undefined : templateDataInput(body.data);
    if (body?.packageId !== undefined) {
      if (
        !['silver', 'gold', 'platinum', 'exclusive'].includes(body.packageId)
      ) {
        throw new HttpError(422, 'Paket template tidak valid.');
      }
    }
    if (body?.packageId === undefined && body?.data === undefined)
      throw new HttpError(422, 'Tidak ada perubahan template yang dikirim.');
    if (body?.packageId !== undefined)
      await this.model.updatePackage(id, body.packageId);
    if (data !== undefined) await this.model.updateData(id, data);
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
