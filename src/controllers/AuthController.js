import { createHash, randomBytes, scrypt, timingSafeEqual } from 'node:crypto';
import { promisify } from 'node:util';
import { HttpError, text } from '../validation.js';

const derive = promisify(scrypt);
const hashToken = (token) => createHash('sha256').update(token).digest('hex');
const publicUser = (row) => ({ name: row.name, email: row.email });

export default class AuthController {
  constructor(model, { secureCookie = false } = {}) {
    this.model = model;
    this.secureCookie = secureCookie;
  }
  cookie(token, maxAge = 28800) {
    return `inviteku_session=${token}; Path=/api; HttpOnly; SameSite=Lax; Max-Age=${maxAge}${this.secureCookie ? '; Secure' : ''}`;
  }
  token(req) {
    return (
      (req.headers.cookie || '')
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith('inviteku_session='))
        ?.slice('inviteku_session='.length) || ''
    );
  }
  async session(req) {
    const token = this.token(req);
    return /^[a-f0-9]{64}$/.test(token)
      ? this.model.session(hashToken(token))
      : null;
  }
  async authorize(req) {
    const session = await this.session(req);
    if (!session) throw new HttpError(401, 'Silakan masuk ke dashboard admin.');
    if (!['GET', 'HEAD'].includes(req.method)) {
      const supplied = req.headers['x-csrf-token'] || '';
      if (
        typeof supplied !== 'string' ||
        !/^[a-f0-9]{64}$/.test(supplied) ||
        supplied.length !== session.csrf_token.length ||
        !timingSafeEqual(Buffer.from(supplied), Buffer.from(session.csrf_token))
      )
        throw new HttpError(
          403,
          'Session keamanan tidak valid. Muat ulang halaman.',
        );
    }
    return session;
  }
  async info(req) {
    const session = await this.session(req);
    return {
      status: 200,
      data: {
        user: session ? publicUser(session) : null,
        csrfToken: session?.csrf_token || null,
        setupRequired: !(await this.model.find()),
      },
    };
  }
  async establish(req, admin) {
    await this.model.cleanup();
    const old = this.token(req);
    if (old) await this.model.removeSession(hashToken(old));
    const token = randomBytes(32).toString('hex');
    const csrfToken = randomBytes(32).toString('hex');
    await this.model.createSession(
      hashToken(token),
      csrfToken,
      Date.now() + 28800000,
    );
    return {
      status: 200,
      cookie: this.cookie(token),
      data: { user: publicUser(admin), csrfToken },
    };
  }
  credentials(body) {
    const email = text(body.email, 'email', 160).toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))
      throw new HttpError(422, 'Alamat email tidak valid.');
    if (
      typeof body.password !== 'string' ||
      body.password.length < 12 ||
      body.password.length > 128
    )
      throw new HttpError(422, 'Password harus 12–128 karakter.');
    return { email, password: body.password };
  }
  async setup(req, body) {
    if (await this.model.find())
      throw new HttpError(409, 'Admin sudah tersedia. Silakan login.');
    const name = text(body.name, 'name', 80);
    const { email, password } = this.credentials(body);
    const salt = randomBytes(16).toString('hex');
    const key = await derive(password, salt, 64);
    await this.model.create(name, email, `${salt}:${key.toString('hex')}`);
    return this.establish(req, { name, email });
  }
  async login(req, body) {
    const { email, password } = this.credentials(body);
    const admin = await this.model.find();
    const [salt, stored] = admin?.password_hash.split(':') || [
      'invalid-login-salt',
      '0'.repeat(128),
    ];
    const key = await derive(password, salt, 64);
    if (
      !admin ||
      email !== admin.email ||
      !timingSafeEqual(key, Buffer.from(stored, 'hex'))
    )
      throw new HttpError(401, 'Email atau password tidak sesuai.');
    return this.establish(req, admin);
  }
  async logout(req) {
    await this.authorize(req);
    await this.model.removeSession(hashToken(this.token(req)));
    return {
      status: 200,
      cookie: this.cookie('', 0),
      data: { message: 'Berhasil keluar.' },
    };
  }
}
