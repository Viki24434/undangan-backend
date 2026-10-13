import { HttpError } from '../validation.js';

const socialHosts = {
  instagram: new Set(['instagram.com', 'www.instagram.com']),
  tiktok: new Set(['tiktok.com', 'www.tiktok.com']),
};

function profileUrl(value, field) {
  if (typeof value !== 'string' || value.length > 500)
    throw new HttpError(422, `${field} harus berupa URL HTTPS.`);
  if (!value.trim()) return '';
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new HttpError(422, `${field} harus berupa URL HTTPS.`);
  }
  if (
    url.protocol !== 'https:' ||
    !socialHosts[field].has(url.hostname) ||
    !url.pathname.split('/').filter(Boolean).length ||
    url.username ||
    url.password
  ) {
    throw new HttpError(422, `${field} harus berupa tautan profil HTTPS yang valid.`);
  }
  return url.href;
}

function socialLinksInput(body) {
  const whatsapp = body?.whatsapp;
  if (
    typeof whatsapp !== 'string' ||
    (whatsapp !== '' && !/^[1-9]\d{7,14}$/.test(whatsapp))
  ) {
    throw new HttpError(
      422,
      'Nomor WhatsApp harus berupa 8–15 digit dengan kode negara, tanpa tanda +.',
    );
  }
  return {
    instagram: profileUrl(body?.instagram, 'instagram'),
    tiktok: profileUrl(body?.tiktok, 'tiktok'),
    whatsapp,
  };
}

export default class SocialLinksController {
  constructor(model) {
    this.model = model;
  }

  async get() {
    const row = await this.model.find();
    if (!row) throw new Error('Pengaturan tautan sosial belum diinisialisasi.');
    return { status: 200, data: JSON.parse(row.data) };
  }

  async update(body) {
    await this.model.update(socialLinksInput(body));
    return this.get();
  }
}
