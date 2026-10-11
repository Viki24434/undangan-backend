export default class RsvpModel {
  constructor(db) {
    this.db = db;
  }
  list(id, limit, offset) {
    return this.db
      .prepare(
        'SELECT id, name, attendance, guest_count AS guestCount, note, created_at AS createdAt FROM rsvps WHERE invitation_id=? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
      )
      .all(id, limit, offset);
  }
  count(id) {
    return this.db
      .prepare('SELECT COUNT(*) AS count FROM rsvps WHERE invitation_id=?')
      .get(id);
  }
  create(id, invitationId, name, attendance, guestCount, note, now) {
    return this.db
      .prepare('INSERT INTO rsvps VALUES (?, ?, ?, ?, ?, ?, ?)')
      .run(id, invitationId, name, attendance, guestCount, note, now);
  }
}
