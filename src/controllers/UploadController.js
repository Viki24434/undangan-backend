import { randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { HttpError } from '../validation.js';

const formats = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
};
const mediaDirectory = fileURLToPath(
  new URL('../../data/uploads/', import.meta.url),
);

export default class UploadController {
  async create(req) {
    const mime = req.headers['content-type']?.split(';')[0];
    const extension = formats[mime];
    if (!extension) throw new HttpError(415, 'Gunakan JPG, PNG, atau WebP.');
    let size = 0;
    const chunks = [];
    for await (const chunk of req) {
      size += chunk.length;
      if (size > 5 * 1024 * 1024)
        throw new HttpError(413, 'File upload maksimal 5 MB setelah diproses.');
      chunks.push(chunk);
    }
    const buffer = Buffer.concat(chunks);
    const valid =
      extension === 'jpg'
        ? buffer.subarray(0, 3).equals(Buffer.from([255, 216, 255]))
        : extension === 'png'
          ? buffer
              .subarray(0, 8)
              .equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))
          : buffer.toString('ascii', 0, 4) === 'RIFF' &&
            buffer.toString('ascii', 8, 12) === 'WEBP';
    if (!valid) throw new HttpError(422, 'Isi file bukan gambar yang valid.');
    const filename = `${randomUUID()}.${extension}`;
    await mkdir(mediaDirectory, { recursive: true });
    await writeFile(join(mediaDirectory, filename), buffer, { flag: 'wx' });
    return { status: 201, data: { url: `/api/media/${filename}` } };
  }

  async image(filename) {
    if (!/^[a-f0-9-]{36}\.(jpg|png|webp)$/.test(filename))
      throw new HttpError(404, 'Gambar tidak ditemukan.');
    try {
      const buffer = await readFile(join(mediaDirectory, filename));
      const mime = Object.keys(formats).find(
        (key) => formats[key] === filename.split('.').pop(),
      );
      return { status: 200, binary: buffer, mime };
    } catch (error) {
      if (error.code === 'ENOENT')
        throw new HttpError(404, 'Gambar tidak ditemukan.');
      throw error;
    }
  }
}
