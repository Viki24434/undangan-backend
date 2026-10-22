import UploadController from './controllers/UploadController.js';
import GuestPhotoController from './controllers/GuestPhotoController.js';
import GuestPhotoModel from './models/GuestPhotoModel.js';
import PackageModel from './models/PackageModel.js';
import PackageController from './controllers/PackageController.js';
import AdminModel from './models/AdminModel.js';
import AuthController from './controllers/AuthController.js';
import { createHash, timingSafeEqual } from 'node:crypto';
import { HttpError } from './validation.js';
import { parseOrigins } from './cors.js';
import { createRouter } from './routes.js';
import TemplateModel from './models/TemplateModel.js';
import TemplateController from './controllers/TemplateController.js';
import InvitationModel from './models/InvitationModel.js';
import InvitationController from './controllers/InvitationController.js';
import WishModel from './models/WishModel.js';
import WishController from './controllers/WishController.js';
import RsvpModel from './models/RsvpModel.js';
import RsvpController from './controllers/RsvpController.js';
import SocialLinksModel from './models/SocialLinksModel.js';
import SocialLinksController from './controllers/SocialLinksController.js';
import GuestListModel from './models/GuestListModel.js';
import GuestListController from './controllers/GuestListController.js';

async function readBody(req, maximum = 32768) {
  if (
    !req.headers['content-type']
      ?.split(';')[0]
      .trim()
      .toLowerCase()
      .startsWith('application/json')
  )
    throw new HttpError(415, 'Gunakan Content-Type application/json.');
  let size = 0;
  const chunks = [];
  for await (const chunk of req) {
    size += chunk.length;
    if (size > maximum)
      throw new HttpError(413, `Payload maksimal ${maximum / 1024} KB.`);
    chunks.push(chunk);
  }
  try {
    const body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    if (!body || typeof body !== 'object' || Array.isArray(body))
      throw new Error();
    return body;
  } catch {
    throw new HttpError(400, 'Body harus berupa objek JSON yang valid.');
  }
}

export function createApp({
  db,
  adminToken,
  origins,
  onError = console.error,
  secureCookie = false,
}) {
  const tokenHash = createHash('sha256').update(adminToken).digest();
  const allowedOrigins = new Set(parseOrigins(origins));
  const requests = new Map();

  const auth = new AuthController(new AdminModel(db), { secureCookie });

  async function admin(req) {
    if (!req.headers.authorization) return auth.authorize(req);
    const header = req.headers.authorization ?? '';
    const token = header.startsWith('Bearer ') ? header.slice(7) : '';
    if (
      !token ||
      !timingSafeEqual(tokenHash, createHash('sha256').update(token).digest())
    ) {
      throw new HttpError(401, 'Token admin tidak valid.');
    }
  }

  function rateLimit(req) {
    const now = Date.now();
    for (const [key, value] of requests)
      if (value.expires <= now) requests.delete(key);
    const key = req.socket.remoteAddress;
    const value = requests.get(key) ?? { count: 0, expires: now + 60000 };
    value.count += 1;
    if (value.count > 20)
      throw new HttpError(
        429,
        'Terlalu banyak kiriman. Coba lagi dalam satu menit.',
      );
    if (requests.size >= 10000 && !requests.has(key))
      throw new HttpError(429, 'Server sedang sibuk. Coba lagi sebentar.');
    requests.set(key, value);
  }

  const templateModel = new TemplateModel(db);
  const guestPhotos = new GuestPhotoController(new GuestPhotoModel(db));
  const route = createRouter({
    guestPhotos,
    guestLists: new GuestListController(new GuestListModel(db)),
    uploads: new UploadController(),
    packages: new PackageController(new PackageModel(db)),
    templates: new TemplateController(templateModel),
    socialLinks: new SocialLinksController(new SocialLinksModel(db)),
    invitations: new InvitationController(
      new InvitationModel(db),
      templateModel,
      guestPhotos,
    ),
    wishes: new WishController(new WishModel(db)),
    rsvps: new RsvpController(new RsvpModel(db)),
    auth,
    admin,
    rateLimit,
    readBody,
    db,
  });

  return async function handler(req, res) {
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('Vary', 'Origin');
    const send = (status, data, meta) => {
      res.writeHead(status);
      res.end(JSON.stringify({ success: true, data, ...(meta && { meta }) }));
    };
    try {
      const origin = req.headers.origin;
      if (origin && !allowedOrigins.has(origin))
        throw new HttpError(
          403,
          `Origin "${origin}" tidak diizinkan. Periksa CORS_ORIGINS pada .env backend dan mulai ulang backend.`,
        );
      if (origin) {
        res.setHeader('Access-Control-Allow-Origin', origin);
        res.setHeader('Access-Control-Allow-Credentials', 'true');
      }
      if (req.method === 'OPTIONS') {
        res.setHeader(
          'Access-Control-Allow-Methods',
          'GET, POST, PATCH, DELETE, OPTIONS',
        );
        res.setHeader(
          'Access-Control-Allow-Headers',
          'Content-Type, Authorization, X-CSRF-Token',
        );
        res.writeHead(204);
        return res.end();
      }
      const url = new URL(req.url, 'http://localhost');
      const segments = url.pathname
        .split('/')
        .filter(Boolean)
        .map((part) => {
          try {
            return decodeURIComponent(part);
          } catch {
            throw new HttpError(400, 'URL tidak valid.');
          }
        });
      const result = await route(req, url, segments);
      if (result.binary) {
        res.setHeader('Content-Type', result.mime);
        res.setHeader(
          'Cache-Control',
          result.download
            ? 'private, no-store'
            : 'public, max-age=31536000, immutable',
        );
        if (result.download)
          res.setHeader(
            'Content-Disposition',
            `attachment; filename="${result.download}"`,
          );
        res.writeHead(result.status);
        return res.end(result.binary);
      }
      if (result.cookie) res.setHeader('Set-Cookie', result.cookie);
      return send(result.status, result.data, result.meta);
    } catch (error) {
      const status =
        error instanceof HttpError
          ? error.status
          : error.code === 'ER_DUP_ENTRY'
            ? 409
            : 500;
      if (status === 500) onError(error);
      if (!res.headersSent) {
        if (status === 429) res.setHeader('Retry-After', '60');
        res.writeHead(status);
        res.end(
          JSON.stringify({
            success: false,
            message:
              status === 500
                ? 'Terjadi kesalahan pada server.'
                : error.code === 'ER_DUP_ENTRY'
                  ? 'Data unik sudah digunakan.'
                  : error.message,
          }),
        );
      } else res.end();
    }
  };
}
