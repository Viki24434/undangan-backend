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
  async create(id, invitationId, name, attendance, guestCount, note, now) {
    const existing = await this.db
      .prepare(
        'SELECT id FROM rsvps WHERE invitation_id=? AND name=? ORDER BY created_at DESC, id DESC LIMIT 1',
      )
      .get(invitationId, name);
    return this.db
      .prepare(
        `INSERT INTO rsvps (id, invitation_id, name, attendance, guest_count, note, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE
        name=VALUES(name), attendance=VALUES(attendance), guest_count=VALUES(guest_count),
        note=IF(VALUES(note)='', note, VALUES(note))`,
      )
      .run(
        existing?.id || id,
        invitationId,
        name,
        attendance,
        guestCount,
        note,
        now,
      );
  }
}
