import { createHash, randomUUID, timingSafeEqual } from 'node:crypto';
import {
  copyFile,
  mkdir,
  readFile,
  rename,
  rm,
  stat,
  unlink,
  writeFile,
} from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { HttpError, invitationInput, pagination, text } from '../validation.js';
import { imageFormats, readImage } from '../imageUpload.js';
import { mapSubmissionData, submissionInput } from '../submissionValidation.js';
import InvitationModel from '../models/InvitationModel.js';
import GuestListModel from '../models/GuestListModel.js';

const digest = (value) => createHash('sha256').update(value).digest('hex');
const lifetime = 24 * 60 * 60 * 1000;

export default class SubmissionController {
  constructor(model, templates, options = {}) {
    this.model = model;
    this.templates = templates;
    this.directory =
      options.directory ||
      fileURLToPath(
        new URL('../../data/customer-submissions/', import.meta.url),
      );
    this.mediaDirectory =
      options.mediaDirectory ||
      fileURLToPath(new URL('../../data/uploads/', import.meta.url));
  }

  async findRow(id) {
    if (!/^[a-f0-9-]{36}$/.test(id))
      throw new HttpError(404, 'Kiriman tidak ditemukan.');
    const row = await this.model.find(id);
    if (!row) throw new HttpError(404, 'Kiriman tidak ditemukan.');
    return row;
  }

  receipt(row) {
    return {
      id: row.id,
      reference: row.id.toUpperCase(),
      status: row.status,
      createdAt: row.created_at,
    };
  }

  async authorized(req, id) {
    const row = await this.findRow(id);
    const header = req.headers.authorization || '';
    const token = header.startsWith('Bearer ') ? header.slice(7) : '';
    if (
      !/^[a-f0-9]{64}$/.test(token) ||
      !timingSafeEqual(Buffer.from(digest(token)), Buffer.from(row.token_hash))
    )
      throw new HttpError(403, 'Token kiriman tidak valid.');
    if (
      row.status === 'uploading' &&
      Date.now() - Date.parse(row.created_at) > lifetime
    )
      throw new HttpError(
        410,
        'Sesi pengiriman kedaluwarsa. Silakan kirim kembali.',
      );
    return row;
  }

  file(id, asset) {
    return join(
      this.directory,
      id,
      `${asset.hash}.${imageFormats[asset.mime]}`,
    );
  }

  async cleanDirectory(id) {
    const target = resolve(this.directory, id);
    if (
      !/^[a-f0-9-]{36}$/.test(id) ||
      dirname(target) !== resolve(this.directory)
    )
      throw new Error('Invalid submission directory');
    await rm(target, { recursive: true, force: true });
  }

  async cleanup() {
    const cutoff = new Date(Date.now() - lifetime).toISOString();
    for (const row of await this.model.expired(cutoff)) {
      const result = await this.model.removeExpired(row.id, cutoff);
      if (result.changes) await this.cleanDirectory(row.id);
    }
  }

  async create(body) {
    const { id, token, data } = submissionInput(body);
    if (!(await this.templates.find(data.form.templateId)))
      throw new HttpError(422, 'Template tidak tersedia.');
    const expired = await this.model.removeExpired(
      id,
      new Date(Date.now() - lifetime).toISOString(),
    );
    if (expired.changes) await this.cleanDirectory(id);
    await this.cleanup();
    const payloadHash = digest(JSON.stringify(data));
    await this.model.create(
      id,
      digest(token),
      payloadHash,
      data,
      new Date().toISOString(),
    );
    const row = await this.findRow(id);
    if (row.token_hash !== digest(token) || row.payload_hash !== payloadHash)
      throw new HttpError(
        409,
        'Identitas kiriman sudah digunakan untuk data berbeda.',
      );
    return { status: 201, data: this.receipt(row) };
  }

  async status(req, id) {
    const row = await this.authorized(req, id);
    const uploaded = [];
    if (row.status === 'uploading') {
      for (const asset of JSON.parse(row.data).assets) {
        try {
          const info = await stat(this.file(id, asset));
          if (info.isFile() && info.size === asset.size)
            uploaded.push(asset.hash);
        } catch (error) {
          if (error.code !== 'ENOENT') throw error;
        }
      }
    }
    return { status: 200, data: { ...this.receipt(row), uploaded } };
  }

  async image(req, id, hash) {
    const row = await this.authorized(req, id);
    if (row.status !== 'uploading')
      throw new HttpError(409, 'Kiriman sudah selesai diproses.');
    const asset = JSON.parse(row.data).assets.find(
      (item) => item.hash === hash,
    );
    if (!asset) throw new HttpError(404, 'Gambar tidak terdaftar.');
    const { buffer, mime } = await readImage(req);
    if (
      buffer.length !== asset.size ||
      mime !== asset.mime ||
      digest(buffer) !== hash
    )
      throw new HttpError(422, 'Isi gambar tidak sesuai dengan kiriman.');
    await this.model.locked(id, async (current) => {
      if (current.status !== 'uploading')
        throw new HttpError(409, 'Kiriman sudah selesai diproses.');
      const filename = this.file(id, asset);
      await mkdir(dirname(filename), { recursive: true });
      const temporary = `${filename}.${randomUUID()}.tmp`;
      await writeFile(temporary, buffer, { flag: 'wx' });
      try {
        await rename(temporary, filename);
      } catch (error) {
        await unlink(temporary).catch(() => {});
        if (
          !['EEXIST', 'EPERM'].includes(error.code) ||
          digest(await readFile(filename)) !== hash
        )
          throw error;
      }
    });
    return { status: 200, data: { hash } };
  }

  async complete(req, id) {
    await this.authorized(req, id);
    await this.model.locked(id, async (row, tx) => {
      if (row.status !== 'uploading') return;
      for (const asset of JSON.parse(row.data).assets) {
        let buffer;
        try {
          buffer = await readFile(this.file(id, asset));
        } catch (error) {
          if (error.code === 'ENOENT')
            throw new HttpError(
              422,
              'Masih ada gambar yang belum terkirim. Coba kirim kembali.',
            );
          throw error;
        }
        if (buffer.length !== asset.size || digest(buffer) !== asset.hash)
          throw new HttpError(422, 'Ada gambar yang belum selesai diunggah.');
      }
      await tx
        .prepare(
          "UPDATE customer_submissions SET status='pending', updated_at=? WHERE id=?",
        )
        .run(new Date().toISOString(), id);
    });
    return { status: 200, data: this.receipt(await this.findRow(id)) };
  }

  async list(params) {
    const { limit, offset } = pagination(params);
    const status = params.get('status') || '';
    if (status && !['pending', 'accepted', 'rejected'].includes(status))
      throw new HttpError(422, 'Filter status tidak valid.');
    return {
      status: 200,
      data: await this.model.list(status, limit, offset),
      meta: { total: (await this.model.count(status)).count, limit, offset },
    };
  }

  async detail(id) {
    const row = await this.findRow(id);
    return {
      status: 200,
      data: {
        ...this.receipt(row),
        ...JSON.parse(row.data),
        invitationId: row.invitation_id,
        reviewNote: row.review_note,
      },
    };
  }

  async downloadImage(id, hash) {
    const row = await this.findRow(id);
    const asset = JSON.parse(row.data).assets.find(
      (item) => item.hash === hash,
    );
    if (!asset) throw new HttpError(404, 'Gambar tidak ditemukan.');
    try {
      return {
        status: 200,
        binary: await readFile(this.file(id, asset)),
        mime: asset.mime,
        private: true,
      };
    } catch (error) {
      if (error.code === 'ENOENT')
        throw new HttpError(404, 'Gambar tidak ditemukan.');
      throw error;
    }
  }

  async accept(id, body) {
    const copied = [];
    let result;
    try {
      result = await this.model.locked(id, async (row, tx) => {
        if (row.status === 'accepted') {
          if (!row.invitation_id)
            throw new HttpError(409, 'Draft dari kiriman ini sudah dihapus.');
          return { invitationId: row.invitation_id };
        }
        if (row.status !== 'pending')
          throw new HttpError(
            409,
            'Kiriman ini tidak sedang menunggu tinjauan.',
          );
        const data = JSON.parse(row.data);
        const validationData = mapSubmissionData(data.form.data, (value) =>
          value.startsWith('customer-image:')
            ? `https://submission.invalid/${value.slice(15)}.jpg`
            : value,
        );
        invitationInput({
          ...data.form,
          data: validationData,
          slug: body.slug,
          status: 'draft',
        });
        if (await new InvitationModel(tx).findSlug(body.slug))
          throw new HttpError(
            409,
            'Tautan undangan sudah digunakan. Pilih tautan lain.',
          );
        const urls = new Map();
        await mkdir(this.mediaDirectory, { recursive: true });
        for (const asset of data.assets) {
          const filename = `${randomUUID()}.${imageFormats[asset.mime]}`;
          const target = join(this.mediaDirectory, filename);
          await copyFile(this.file(id, asset), target);
          copied.push(target);
          urls.set(`customer-image:${asset.hash}`, `/api/media/${filename}`);
        }
        const form = {
          ...data.form,
          data: mapSubmissionData(
            data.form.data,
            (value) => urls.get(value) || value,
          ),
        };
        const input = invitationInput({
          ...form,
          slug: body.slug,
          status: 'draft',
        });
        const invitationId = randomUUID();
        const now = new Date().toISOString();
        await new InvitationModel(tx).create(invitationId, input, now);
        await new GuestListModel(tx).save(
          invitationId,
          data.guestList.guests,
          0,
          now,
          data.guestList.messageTemplate,
        );
        await tx
          .prepare(
            "UPDATE customer_submissions SET status='accepted', invitation_id=?, data=?, updated_at=? WHERE id=?",
          )
          .run(invitationId, JSON.stringify({ ...data, form }), now, id);
        return { invitationId };
      });
    } catch (error) {
      await Promise.all(copied.map((file) => unlink(file).catch(() => {})));
      throw error;
    }
    await this.cleanDirectory(id).catch(() => {});
    return { status: 200, data: result };
  }

  async reject(id, body) {
    const note = text(body.note ?? '', 'Catatan admin', 1000, true);
    await this.model.locked(id, async (row, tx) => {
      if (!['pending', 'rejected'].includes(row.status))
        throw new HttpError(409, 'Status kiriman tidak dapat diubah.');
      await tx
        .prepare(
          "UPDATE customer_submissions SET status='rejected', review_note=?, updated_at=? WHERE id=?",
        )
        .run(note, new Date().toISOString(), id);
    });
    return { status: 200, data: { status: 'rejected' } };
  }

  async remove(id) {
    await this.model.locked(id, async (_row, tx) => {
      await tx.prepare('DELETE FROM customer_submissions WHERE id=?').run(id);
    });
    await this.cleanDirectory(id);
    return {
      status: 200,
      data: {
        message: 'Kiriman dihapus. Undangan yang telah dibuat tetap tersimpan.',
      },
    };
  }
}
