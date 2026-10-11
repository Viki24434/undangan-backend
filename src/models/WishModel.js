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
  create(id, invitationId, name, message, now) {
    return this.db
      .prepare('INSERT INTO wishes VALUES (?, ?, ?, ?, ?)')
      .run(id, invitationId, name, message, now);
  }
  remove(id) {
    return this.db.prepare('DELETE FROM wishes WHERE id=?').run(id);
  }
}
