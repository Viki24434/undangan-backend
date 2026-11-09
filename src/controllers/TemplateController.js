import { randomUUID } from 'node:crypto';
import { HttpError, text, themeInput } from '../validation.js';
import { designInput } from '../designValidation.js';

function templateDataInput(data) {
  if (
    typeof data !== 'object' ||
    data === null ||
    Array.isArray(data) ||
    Buffer.byteLength(JSON.stringify(data), 'utf8') > 500_000
  ) {
    throw new HttpError(422, 'Data template tidak valid atau terlalu besar.');
  }
  return {
    ...data,
    ...(data.design !== undefined && { design: designInput(data.design) }),
    theme: themeInput(data.theme ?? {}),
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
        rendererId: row.renderer_id || row.id,
        isCustom: Boolean(row.is_custom),
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
  async create(body) {
    const base = await this.model.find(
      text(body.baseTemplateId, 'Template dasar', 80),
    );
    if (!base) throw new HttpError(422, 'Template dasar tidak ditemukan.');
    const name = text(body.name, 'Nama template', 160);
    const category = text(body.category ?? base.category, 'Kategori', 80);
    const packageId = body.packageId ?? base.package_id;
    if (!['silver', 'gold', 'platinum', 'exclusive'].includes(packageId))
      throw new HttpError(422, 'Paket tidak valid.');
    const id = `custom-${randomUUID()}`;
    const baseData = JSON.parse(base.data || '{}');
    if (
      body.data !== undefined &&
      (!body.data || typeof body.data !== 'object' || Array.isArray(body.data))
    )
      throw new HttpError(422, 'Isi template harus berupa objek.');
    const overrides = body.data ? templateDataInput(body.data) : {};
    const data = templateDataInput({
      ...baseData,
      ...overrides,
      theme: { ...baseData.theme, ...overrides.theme },
      ...(baseData.groom && {
        groom: { ...baseData.groom, ...overrides.groom },
      }),
      ...(baseData.bride && {
        bride: { ...baseData.bride, ...overrides.bride },
      }),
    });
    await this.model.create({
      id,
      name,
      category,
      packageId,
      description: text(
        body.description ?? base.description,
        'Deskripsi',
        1000,
        true,
      ),
      img: base.image,
      rendererId: base.renderer_id || base.id,
      data,
    });
    return { status: 201, data: (await this.find(id)).data };
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
        rendererId: row.renderer_id || row.id,
        isCustom: Boolean(row.is_custom),
        theme: JSON.parse(row.theme || '{}') || {},
      })),
    };
  }
}
