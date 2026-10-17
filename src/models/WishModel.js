export default class WishModel {
  constructor(db) {
    this.db = db;
  }
  list(id, limit, offset) {
    return this.db
      .prepare(
        'SELECT id, name, message, created_at AS createdAt FROM wishes WHERE invitation_id=? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
      )
      .all(id, limit, offset);
  }
  count(id) {
    return this.db
      .prepare('SELECT COUNT(*) AS count FROM wishes WHERE invitation_id=?')
      .get(id);
  }
  async save(id, invitationId, guestKey, name, message, now) {
    const existing = await this.db
      .prepare(
        'SELECT id FROM wishes WHERE invitation_id=? AND (guest_key=? OR (guest_key IS NULL AND name=?)) ORDER BY guest_key IS NULL, created_at DESC, id DESC LIMIT 1',
      )
      .get(invitationId, guestKey, name);
    const result = await this.db
      .prepare(
        `INSERT INTO wishes (id, invitation_id, name, message, created_at, guest_key)
        VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE
        name=VALUES(name), message=VALUES(message), guest_key=VALUES(guest_key)`,
      )
      .run(existing?.id || id, invitationId, name, message, now, guestKey);
    const wish = await this.db
      .prepare(
        'SELECT id, name, message, created_at AS createdAt FROM wishes WHERE invitation_id=? AND guest_key=?',
      )
      .get(invitationId, guestKey);
    return { wish, updated: Boolean(existing) || result.changes !== 1 };
  }
  remove(id) {
    return this.db.prepare('DELETE FROM wishes WHERE id=?').run(id);
  }
}
