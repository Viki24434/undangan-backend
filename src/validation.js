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

function optionalContent(data) {
  const sunlightEnabled = data.sunlightEnabled ?? true;
  if (typeof sunlightEnabled !== 'boolean')
    throw new HttpError(422, 'sunlightEnabled harus berupa boolean.');

  const photos = data.photos ?? [];
  if (!Array.isArray(photos) || photos.length > 30)
    throw new HttpError(422, 'photos harus berupa daftar maksimal 30 foto.');
  const validatedPhotos = photos.map((photo, index) => {
    const photoUrl = text(photo, `URL foto ke-${index + 1}`, 2000);
    if (/^\/api\/media\/[a-f0-9-]{36}\.(jpg|png|webp)$/.test(photoUrl))
      return photoUrl;
    let url;
    try {
      url = new URL(photoUrl);
    } catch {
      throw new HttpError(422, `URL foto ke-${index + 1} tidak valid.`);
    }
    if (url.protocol !== 'https:')
      throw new HttpError(
        422,
        `URL foto ke-${index + 1} harus menggunakan HTTPS.`,
      );
    return photoUrl;
  });

  const agenda = data.agenda ?? [];
  if (!Array.isArray(agenda) || agenda.length > 30)
    throw new HttpError(
      422,
      'agenda harus berupa daftar maksimal 30 kegiatan.',
    );
  const validatedAgenda = agenda.map((item, index) => {
    if (typeof item !== 'object' || item === null || Array.isArray(item))
      throw new HttpError(422, `agenda ke-${index + 1} harus berupa objek.`);
    return {
      time: text(item.time, `Waktu agenda ke-${index + 1}`, 60),
      title: text(item.title, `Nama agenda ke-${index + 1}`, 160),
      description: text(
        item.description ?? '',
        `Keterangan agenda ke-${index + 1}`,
        1000,
        true,
      ),
    };
  });

  const prayers = data.prayers ?? [];
  if (!Array.isArray(prayers) || prayers.length > 20)
    throw new HttpError(422, 'prayers harus berupa daftar maksimal 20 doa.');
  const validatedPrayers = prayers.map((item, index) => {
    if (typeof item !== 'object' || item === null || Array.isArray(item))
      throw new HttpError(422, `Doa ke-${index + 1} harus berupa objek.`);
    return {
      title: text(item.title, `Judul doa ke-${index + 1}`, 160),
      text: text(item.text, `Isi doa ke-${index + 1}`, 3000),
      arabic: text(
        item.arabic ?? '',
        `Teks Arab doa ke-${index + 1}`,
        3000,
        true,
      ),
      source: text(item.source ?? '', `Sumber doa ke-${index + 1}`, 300, true),
    };
  });

  const videoUrl = text(data.videoUrl ?? '', 'videoUrl', 1000, true);
  if (videoUrl) {
    let url;
    try {
      url = new URL(videoUrl);
    } catch {
      throw new HttpError(422, 'videoUrl harus berupa URL video yang valid.');
    }
    if (url.protocol !== 'https:')
      throw new HttpError(422, 'videoUrl harus menggunakan HTTPS.');

    const youtubeHosts = new Set([
      'youtu.be',
      'youtube.com',
      'www.youtube.com',
      'm.youtube.com',
      'youtube-nocookie.com',
      'www.youtube-nocookie.com',
    ]);
    const vimeoHosts = new Set([
      'vimeo.com',
      'www.vimeo.com',
      'player.vimeo.com',
    ]);
    const directVideo = /\.(mp4|webm|ogg)$/i.test(url.pathname);
    let supportedEmbed = false;
    if (youtubeHosts.has(url.hostname)) {
      const videoId =
        url.hostname === 'youtu.be'
          ? url.pathname.split('/').filter(Boolean)[0]
          : url.pathname === '/watch'
            ? url.searchParams.get('v')
            : url.pathname.split('/').filter(Boolean).at(-1);
      supportedEmbed = /^[\w-]{11}$/.test(videoId || '');
    } else if (vimeoHosts.has(url.hostname)) {
      supportedEmbed = /^\d+$/.test(
        url.pathname.split('/').filter(Boolean).at(-1) || '',
      );
    }
    if (!directVideo && !supportedEmbed)
      throw new HttpError(
        422,
        'videoUrl harus berupa tautan YouTube, Vimeo, atau file MP4/WebM/OGG.',
      );
  }

  return {
    sunlightEnabled,
    photos: validatedPhotos,
    agenda: validatedAgenda,
    videoUrl,
    prayers: validatedPrayers,
  };
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
  const optional = optionalContent(data);
  const status = value.status ?? 'draft';
  if (!['draft', 'published'].includes(status))
    throw new HttpError(422, 'status harus draft atau published.');
  return {
    slug,
    templateId: text(value.templateId, 'templateId', 80),
    title: text(value.title, 'title', 160),
    eventDate,
    data: { ...data, ...optional },
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
