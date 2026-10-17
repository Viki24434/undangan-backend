import { randomUUID } from 'node:crypto';
import { HttpError, invitationInput, pagination } from '../validation.js';
import { invitationRecord } from '../models/InvitationModel.js';

export default class InvitationController {
  constructor(model, templates, guestPhotos) {
    this.model = model;
    this.templates = templates;
    this.guestPhotos = guestPhotos;
  }
  async withTemplateData(invitation) {
    const template = await this.templates.find(invitation.templateId);
    const defaults = template?.data ? JSON.parse(template.data) : {};
    const overrides = invitation.data;
    return {
      ...invitation,
      packageId: template?.package_id,
      data: {
        ...defaults,
        banks: [],
        wishes: [],
        ...overrides,
        agenda: overrides.agenda ?? defaults.agenda ?? [],
        videoUrl: overrides.videoUrl ?? defaults.videoUrl ?? '',
        prayers: overrides.prayers ?? defaults.prayers ?? [],
        events:
          overrides.events ||
          (defaults.events || []).map((event) => ({
            ...event,
            date: undefined,
          })),
        ...(defaults.groom && {
          groom: { ...defaults.groom, ...overrides.groom },
        }),
        ...(defaults.bride && {
          bride: { ...defaults.bride, ...overrides.bride },
        }),
      },
    };
  }
  async published(slug) {
    const row = await this.model.published(slug);
    if (!row) throw new HttpError(404, 'Undangan tidak ditemukan.');
    return this.withTemplateData(invitationRecord(row));
  }
  async find(id) {
    const row = await this.model.find(id);
    if (!row) throw new HttpError(404, 'Undangan tidak ditemukan.');
    return this.withTemplateData(invitationRecord(row));
  }
  async list(searchParams) {
    const { limit, offset } = pagination(searchParams);
    return {
      status: 200,
      data: (await this.model.list(limit, offset)).map(invitationRecord),
      meta: { total: (await this.model.count()).count, limit, offset },
    };
  }
  async validate(input, exceptId) {
    if (!(await this.templates.find(input.templateId)))
      throw new HttpError(422, 'Template tidak tersedia.');
    if (await this.model.findSlug(input.slug, exceptId))
      throw new HttpError(409, 'slug sudah digunakan.');
  }
  async create(body) {
    const input = invitationInput(body);
    await this.validate(input);
    const id = randomUUID();
    await this.model.create(id, input, new Date().toISOString());
    return { status: 201, data: await this.find(id) };
  }
  async update(id, body) {
    const input = invitationInput(body, await this.find(id));
    await this.validate(input, id);
    await this.model.update(id, input);
    return { status: 200, data: await this.find(id) };
  }
  async remove(id) {
    await this.find(id);
    const files = await this.guestPhotos.model.files(id);
    await this.model.remove(id);
    await this.guestPhotos.cleanFiles(files);
    return {
      status: 200,
      data: { message: 'Undangan dan data tamu terkait telah dihapus.' },
    };
  }
}
