export default class GuestPhotoModel {
  constructor(db) {
    this.db = db;
  }

  create(id, invitationId, filename, mime, size, createdAt) {
    return this.db
      .prepare(
        'INSERT INTO guest_photos (id, invitation_id, filename, mime, size, created_at) VALUES (?, ?, ?, ?, ?, ?)',
      )
      .run(id, invitationId, filename, mime, size, createdAt);
  }

  list(invitationId, limit, offset) {
    return this.db
      .prepare(
        'SELECT id, filename, size, created_at AS createdAt FROM guest_photos WHERE invitation_id=? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
      )
      .all(invitationId, limit, offset);
  }

  count(invitationId) {
    return this.db
      .prepare(
        'SELECT COUNT(*) AS count FROM guest_photos WHERE invitation_id=?',
      )
      .get(invitationId);
  }

  find(invitationId, id) {
    return this.db
      .prepare('SELECT * FROM guest_photos WHERE invitation_id=? AND id=?')
      .get(invitationId, id);
  }

  files(invitationId) {
    return this.db
      .prepare('SELECT filename FROM guest_photos WHERE invitation_id=?')
      .all(invitationId);
  }
}
