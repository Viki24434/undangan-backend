import { randomUUID } from 'node:crypto';
import { mkdir, readFile, unlink, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { HttpError, pagination, text } from '../validation.js';
import { readImage } from '../imageUpload.js';

const directory = fileURLToPath(
  new URL('../../data/guest-photos/', import.meta.url),
);

export default class GuestPhotoController {
  constructor(model) {
    this.model = model;
  }

  async create(req, invitation, name) {
    if (invitation.packageId !== 'platinum')
      throw new HttpError(403, 'Kirim foto tersedia untuk paket Platinum.');
    const start = Date.parse(invitation.eventDate);
    if (!Number.isFinite(start) || Date.now() < start)
      throw new HttpError(403, 'Kirim foto dibuka setelah acara dimulai.');
    const guest = text(name, 'Nama tamu', 80);
    const safeName =
      guest
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 80) || 'tamu';
    const { buffer, extension, mime } = await readImage(req);
    const id = randomUUID();
    const now = new Date().toISOString();
    const filename = `${safeName}-${now.slice(0, 10)}-${id}.${extension}`;
    await mkdir(directory, { recursive: true });
    await writeFile(join(directory, filename), buffer, { flag: 'wx' });
    try {
      await this.model.create(
        id,
        invitation.id,
        filename,
        mime,
        buffer.length,
        now,
      );
    } catch (error) {
      await unlink(join(directory, filename));
      throw error;
    }
    return { status: 201, data: { id, filename } };
  }

  async list(invitationId, params) {
    const { limit, offset } = pagination(params);
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

  async download(invitationId, id) {
    const photo = await this.model.find(invitationId, id);
    if (!photo) throw new HttpError(404, 'Foto tidak ditemukan.');
    try {
      return {
        status: 200,
        binary: await readFile(join(directory, photo.filename)),
        mime: photo.mime,
        download: photo.filename,
      };
    } catch (error) {
      if (error.code === 'ENOENT')
        throw new HttpError(404, 'Foto tidak ditemukan.');
      throw error;
    }
  }

  async cleanFiles(files) {
    await Promise.all(
      files.map(async ({ filename }) => {
        try {
          await unlink(join(directory, filename));
        } catch (error) {
          if (error.code !== 'ENOENT') throw error;
        }
      }),
    );
  }
}
