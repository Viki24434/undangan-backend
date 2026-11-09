import { HttpError } from './validation.js';

const sections = [
  'cover',
  'hero',
  'intro',
  'couple',
  'story',
  'events',
  'gallery',
  'wishes',
  'gift',
  'closing',
  'photos',
  'agenda',
  'video',
  'prayers',
  'guide',
];
const entries = [
  'inherit',
  'none',
  'fade',
  'left',
  'right',
  'rise',
  'zoom',
  'type',
];
const motions = [
  'inherit',
  'none',
  'sway',
  'float',
  'pulse',
  'spin',
  'flutter',
];

function number(value, min, max, fallback, label) {
  const result = value ?? fallback;
  if (
    typeof result !== 'number' ||
    !Number.isFinite(result) ||
    result < min ||
    result > max
  )
    throw new HttpError(422, `${label} harus di antara ${min} dan ${max}.`);
  return result;
}

function layer(item, native = false) {
  if (!item || typeof item !== 'object' || Array.isArray(item))
    throw new HttpError(422, 'Lapisan desain tidak valid.');
  const result = {};
  for (const [key, min, max, fallback] of [
    ['x', -200, 300, 0],
    ['y', -200, 300, 0],
    ['width', 1, 400, 30],
    ['rotate', -360, 360, 0],
    ['opacity', 0, 1, 1],
    ['zIndex', -5, 50, 3],
    ['duration', 0.2, 30, 6],
    ['delay', 0, 10, 0],
    ['entryDuration', 0.2, 8, 1],
  ])
    result[key] = number(item[key], min, max, fallback, key);
  if (item.height !== undefined && item.height !== null)
    result.height = number(item.height, 1, 400, 30, 'Tinggi');
  for (const key of ['flipX', 'flipY', 'hidden']) {
    if (item[key] !== undefined && typeof item[key] !== 'boolean')
      throw new HttpError(422, `${key} harus berupa boolean.`);
    result[key] = item[key] ?? false;
  }
  result.entry = item.entry ?? (native ? 'inherit' : 'fade');
  result.motion = item.motion ?? (native ? 'inherit' : 'none');
  if (!entries.includes(result.entry) || !motions.includes(result.motion))
    throw new HttpError(422, 'Pilihan animasi tidak tersedia.');
  if (!native) {
    if (typeof item.id !== 'string' || !/^[\w-]{1,60}$/.test(item.id))
      throw new HttpError(422, 'ID lapisan tidak valid.');
    result.id = item.id;
    result.label =
      typeof item.label === 'string' ? item.label.slice(0, 100) : 'Ornamen';
  }
  if (!native || item.asset !== undefined || item.src !== undefined) {
    if (item.asset !== undefined) {
      if (
        typeof item.asset !== 'string' ||
        !/^[a-z0-9-]{1,120}$/.test(item.asset)
      )
        throw new HttpError(422, 'Aset ornamen tidak valid.');
      result.asset = item.asset;
    } else {
      if (
        typeof item.src !== 'string' ||
        item.src.length > 2000 ||
        !(
          /^\/api\/media\/[a-f0-9-]{36}\.(png|webp|jpg)$/.test(item.src) ||
          /^\/assets\/[a-z0-9_./% -]+$/i.test(item.src)
        )
      )
        throw new HttpError(
          422,
          'Gunakan aset perpustakaan atau gambar yang diunggah.',
        );
      if (item.src.includes('..'))
        throw new HttpError(422, 'Alamat gambar tidak valid.');
      result.src = item.src;
    }
  }
  return result;
}

export function designInput(input) {
  if (input === undefined) return undefined;
  if (
    !input ||
    typeof input !== 'object' ||
    Array.isArray(input) ||
    input.version !== 1
  )
    throw new HttpError(422, 'Format desain tidak didukung.');
  const result = { version: 1, sections: {}, native: {} };
  if (input.components !== undefined) {
    if (
      !input.components ||
      typeof input.components !== 'object' ||
      Array.isArray(input.components)
    )
      throw new HttpError(422, 'Variasi komponen tidak valid.');
    const allowed = {
      cover: [1, 2, 3],
      hero: [1, 2, 3],
      couple: [1, 2, 3],
      story: [1, 2],
      countdown: [1, 2, 3],
      gallery: [0, 1, 2, 3, 4],
      gift: [1, 2],
      wishes: [1, 2],
    };
    result.components = {};
    for (const [key, value] of Object.entries(input.components)) {
      if (!Object.hasOwn(allowed, key) || !allowed[key].includes(value))
        throw new HttpError(422, 'Pilihan variasi komponen tidak tersedia.');
      result.components[key] = value;
    }
  }
  let count = 0;
  for (const [key, section] of Object.entries(input.sections ?? {})) {
    if (
      !sections.includes(key) ||
      !section ||
      typeof section !== 'object' ||
      Array.isArray(section)
    )
      throw new HttpError(422, 'Bagian desain tidak valid.');
    const items = section.items ?? [];
    if (!Array.isArray(items) || items.length > 40)
      throw new HttpError(422, 'Maksimal 40 ornamen tambahan per bagian.');
    const ids = new Set();
    const validated = items.map((item) => {
      const value = layer(item);
      if (ids.has(value.id)) throw new HttpError(422, 'ID ornamen harus unik.');
      ids.add(value.id);
      return value;
    });
    const reveal = section.reveal ?? 'inherit';
    if (!entries.includes(reveal))
      throw new HttpError(422, 'Animasi bagian tidak tersedia.');
    result.sections[key] = {
      items: validated,
      reveal,
      duration: number(section.duration, 0.2, 8, 1, 'Durasi bagian'),
    };
    count += items.length;
  }
  for (const [key, item] of Object.entries(input.native ?? {})) {
    if (
      !/^(cover|hero|intro|couple|story|events|gallery|wishes|gift|closing|photos|agenda|video|prayers|guide):(?:[a-z0-9-]{1,150}:)?\d{1,3}$/.test(
        key,
      )
    )
      throw new HttpError(422, 'Referensi ornamen bawaan tidak valid.');
    result.native[key] = layer(item, true);
    count += 1;
  }
  if (count > 250)
    throw new HttpError(422, 'Maksimal 250 pengaturan ornamen per template.');
  return result;
}
