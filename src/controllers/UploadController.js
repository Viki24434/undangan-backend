import { randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { HttpError } from '../validation.js';
import { imageFormats, readImage } from '../imageUpload.js';

const mediaDirectory = fileURLToPath(
  new URL('../../data/uploads/', import.meta.url),
);

export default class UploadController {
  constructor(directory = mediaDirectory) {
    this.directory = directory;
  }
  async create(req) {
    const { buffer, extension } = await readImage(req);
    const filename = `${randomUUID()}.${extension}`;
    await mkdir(this.directory, { recursive: true });
    await writeFile(join(this.directory, filename), buffer, { flag: 'wx' });
    return { status: 201, data: { url: `/api/media/${filename}` } };
  }

  async image(filename) {
    if (!/^[a-f0-9-]{36}\.(jpg|png|webp)$/.test(filename))
      throw new HttpError(404, 'Gambar tidak ditemukan.');
    try {
      const buffer = await readFile(join(this.directory, filename));
      const mime = Object.keys(imageFormats).find(
        (key) => imageFormats[key] === filename.split('.').pop(),
      );
      return { status: 200, binary: buffer, mime };
    } catch (error) {
      if (error.code === 'ENOENT')
        throw new HttpError(404, 'Gambar tidak ditemukan.');
      throw error;
    }
  }
}
