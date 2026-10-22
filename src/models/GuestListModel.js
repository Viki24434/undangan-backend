export default class GuestListModel {
  constructor(db) {
    this.db = db;
  }

  invitation(id) {
    return this.db
      .prepare('SELECT id, title, slug, status FROM invitations WHERE id=?')
      .get(id);
  }

  find(id) {
    return this.db
      .prepare('SELECT * FROM invitation_guest_lists WHERE invitation_id=?')
      .get(id);
  }

  async save(id, guests, revision, updatedAt) {
    const data = JSON.stringify(guests);
    if (revision === 0) {
      const result = await this.db
        .prepare(
          'INSERT IGNORE INTO invitation_guest_lists (invitation_id, data, revision, updated_at) VALUES (?, ?, 1, ?)',
        )
        .run(id, data, updatedAt);
      return result.changes === 1;
    }
    const result = await this.db
      .prepare(
        'UPDATE invitation_guest_lists SET data=?, revision=revision+1, updated_at=? WHERE invitation_id=? AND revision=?',
      )
      .run(data, updatedAt, id, revision);
    return result.changes === 1;
  }
}
