export function invitationRecord(row) {
  return {
    id: row.id,
    slug: row.slug,
    templateId: row.template_id,
    title: row.title,
    eventDate: row.event_date,
    data: JSON.parse(row.data),
    status: row.status,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

export default class InvitationModel {
  constructor(db) {
    this.db = db;
  }
  published(slug) {
    return this.db
      .prepare("SELECT * FROM invitations WHERE slug=? AND status='published'")
      .get(slug);
  }
  find(id) {
    return this.db.prepare('SELECT * FROM invitations WHERE id=?').get(id);
  }
  findSlug(slug, exceptId) {
    return exceptId
      ? this.db
          .prepare('SELECT id FROM invitations WHERE slug=? AND id<>?')
          .get(slug, exceptId)
      : this.db.prepare('SELECT id FROM invitations WHERE slug=?').get(slug);
  }
  list(limit, offset) {
    return this.db
      .prepare(
        'SELECT * FROM invitations ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
      )
      .all(limit, offset);
  }
  count() {
    return this.db.prepare('SELECT COUNT(*) AS count FROM invitations').get();
  }
  create(id, input, now) {
    return this.db
      .prepare('INSERT INTO invitations VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
      .run(
        id,
        input.slug,
        input.templateId,
        input.title,
        input.eventDate,
        JSON.stringify(input.data),
        input.status,
        now,
        now,
      );
  }
  update(id, input) {
    return this.db
      .prepare(
        'UPDATE invitations SET slug=?, template_id=?, title=?, event_date=?, data=?, status=?, updated_at=? WHERE id=?',
      )
      .run(
        input.slug,
        input.templateId,
        input.title,
        input.eventDate,
        JSON.stringify(input.data),
        input.status,
        new Date().toISOString(),
        id,
      );
  }
  remove(id) {
    return this.db.prepare('DELETE FROM invitations WHERE id=?').run(id);
  }
}
