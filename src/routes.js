import { HttpError } from './validation.js';

export function createRouter({
  auth,
  packages,
  templates,
  invitations,
  wishes,
  rsvps,
  admin,
  rateLimit,
  readBody,
  db,
}) {
  return async function route(req, url, segments) {
    const method = req.method;
    if (segments[0] !== 'api')
      throw new HttpError(404, 'Endpoint tidak ditemukan.');
    if (url.pathname === '/api/auth/session' && method === 'GET')
      return auth.info(req);
    if (
      ['/api/auth/login', '/api/auth/setup'].includes(url.pathname) &&
      method === 'POST'
    ) {
      rateLimit(req);
      const body = await readBody(req);
      return url.pathname.endsWith('/setup')
        ? auth.setup(req, body)
        : auth.login(req, body);
    }
    if (url.pathname === '/api/auth/logout' && method === 'POST')
      return auth.logout(req);
    if (method === 'GET' && url.pathname === '/api/health') {
      await db.prepare('SELECT 1').get();
      return { status: 200, data: { status: 'ok' } };
    }
    if (method === 'GET' && url.pathname === '/api/packages')
      return packages.list();
    if (method === 'GET' && url.pathname === '/api/templates')
      return templates.list(url.searchParams.get('category'));
    if (
      method === 'GET' &&
      segments[1] === 'templates' &&
      segments.length === 3
    )
      return templates.find(segments[2]);
    if (segments[1] === 'invitations' && segments[2] && segments.length <= 4) {
      const invitation = await invitations.published(segments[2]);
      if (method === 'GET' && segments.length === 3)
        return { status: 200, data: invitation };
      if (segments[3] === 'wishes' && method === 'GET')
        return wishes.list(invitation.id, url.searchParams);
      if (method === 'POST' && ['wishes', 'rsvps'].includes(segments[3])) {
        rateLimit(req);
        const body = await readBody(req);
        return segments[3] === 'wishes'
          ? wishes.create(invitation.id, body)
          : rsvps.create(invitation.id, body);
      }
    }
    if (segments[1] === 'admin') {
      await admin(req);
      if (
        segments[2] === 'packages' &&
        segments.length === 4 &&
        method === 'PATCH'
      )
        return packages.updatePrice(segments[3], await readBody(req));
      if (
        segments[2] === 'templates' &&
        segments.length === 4 &&
        method === 'PATCH'
      )
        return templates.updatePackage(segments[3], await readBody(req));
      if (segments[2] === 'invitations' && segments.length === 3) {
        if (method === 'GET') return invitations.list(url.searchParams);
        if (method === 'POST') return invitations.create(await readBody(req));
      }
      if (
        segments[2] === 'invitations' &&
        segments[3] &&
        segments.length <= 5
      ) {
        const invitation = await invitations.find(segments[3]);
        if (segments.length === 4) {
          if (method === 'GET') return { status: 200, data: invitation };
          if (method === 'PATCH')
            return invitations.update(invitation.id, await readBody(req));
          if (method === 'DELETE') return invitations.remove(invitation.id);
        }
        if (method === 'GET' && segments[4] === 'wishes')
          return wishes.list(invitation.id, url.searchParams);
        if (method === 'GET' && segments[4] === 'rsvps')
          return rsvps.list(invitation.id, url.searchParams);
      }
      if (
        segments[2] === 'wishes' &&
        segments.length === 4 &&
        method === 'DELETE'
      )
        return wishes.remove(segments[3]);
    }
    throw new HttpError(404, 'Endpoint tidak ditemukan.');
  };
}
