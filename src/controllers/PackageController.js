import { HttpError } from '../validation.js';

function packageRecord(row) {
  return {
    id: row.id,
    name: row.name,
    price: row.price === null ? null : Number(row.price),
    priceMode: row.id === 'exclusive' ? 'custom' : 'fixed',
    currency: 'IDR',
  };
}

export default class PackageController {
  constructor(model) {
    this.model = model;
  }

  async list() {
    return { status: 200, data: (await this.model.list()).map(packageRecord) };
  }

  async updatePrice(id, body) {
    const row = await this.model.find(id);
    if (!row) throw new HttpError(404, 'Paket tidak ditemukan.');
    if (id === 'exclusive') {
      throw new HttpError(422, 'Harga Exclusive ditentukan secara custom.');
    }
    const price = body?.price;
    if (
      price !== null &&
      (!Number.isSafeInteger(price) || price < 0 || price > 1000000000000)
    ) {
      throw new HttpError(
        422,
        'Harga harus berupa rupiah bulat antara 0 dan 1.000.000.000.000.',
      );
    }
    await this.model.updatePrice(id, price);
    return { status: 200, data: packageRecord(await this.model.find(id)) };
  }
}
