export class HttpError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

export function text(value, field, max, optional = false) {
  if (optional && value === undefined) return '';
  if (
    typeof value !== 'string' ||
    (!optional && !value.trim()) ||
    value.trim().length > max
  ) {
    throw new HttpError(
      422,
      `${field} harus berupa teks ${optional ? 'maksimal' : '1–'}${max} karakter.`,
    );
  }
  return value.trim();
}

export function invitationInput(body, current = {}) {
  const value = { ...current, ...body };
  const slug = text(value.slug, 'slug', 80);
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug))
    throw new HttpError(
      422,
      'slug hanya boleh berisi huruf kecil, angka, dan tanda hubung.',
    );
  const eventDate = text(value.eventDate, 'eventDate', 40);
  if (
    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/.test(
      eventDate,
    ) ||
    !Number.isFinite(Date.parse(eventDate))
  ) {
    throw new HttpError(
      422,
      'eventDate harus berupa ISO 8601 dengan zona waktu.',
    );
  }
  const datePart = eventDate.slice(0, 10);
  const calendarDate = new Date(`${datePart}T00:00:00Z`);
  if (calendarDate.toISOString().slice(0, 10) !== datePart)
    throw new HttpError(422, 'Tanggal acara tidak valid.');
  const data = value.data ?? {};
  if (typeof data !== 'object' || data === null || Array.isArray(data))
    throw new HttpError(422, 'data harus berupa objek JSON.');
  const status = value.status ?? 'draft';
  if (!['draft', 'published'].includes(status))
    throw new HttpError(422, 'status harus draft atau published.');
  return {
    slug,
    templateId: text(value.templateId, 'templateId', 80),
    title: text(value.title, 'title', 160),
    eventDate,
    data,
    status,
  };
}

export function pagination(searchParams) {
  const limit = Number(searchParams.get('limit') ?? 20);
  const offset = Number(searchParams.get('offset') ?? 0);
  if (
    !Number.isInteger(limit) ||
    limit < 1 ||
    limit > 100 ||
    !Number.isInteger(offset) ||
    offset < 0
  ) {
    throw new HttpError(
      422,
      'limit harus 1–100 dan offset tidak boleh negatif.',
    );
  }
  return { limit, offset };
}
