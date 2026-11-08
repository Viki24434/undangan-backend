import { HttpError } from '../validation.js';

export default class SubmissionModel {
  constructor(db) {
    this.db = db;
  }

  find(id) {
    return this.db
      .prepare('SELECT * FROM customer_submissions WHERE id=?')
      .get(id);
  }

  create(id, tokenHash, payloadHash, data, now) {
    return this.db
      .prepare(
        "INSERT IGNORE INTO customer_submissions (id, token_hash, payload_hash, data, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'uploading', ?, ?)",
      )
      .run(id, tokenHash, payloadHash, JSON.stringify(data), now, now);
  }

  list(status, limit, offset) {
    const filter = status ? ' WHERE status=?' : " WHERE status<>'uploading'";
    return this.db
      .prepare(
        `SELECT id, status, invitation_id, review_note, created_at, updated_at,
      JSON_UNQUOTE(JSON_EXTRACT(data, '$.contact.name')) AS customer_name,
      JSON_UNQUOTE(JSON_EXTRACT(data, '$.form.title')) AS title,
      JSON_UNQUOTE(JSON_EXTRACT(data, '$.form.templateId')) AS template_id,
      JSON_LENGTH(JSON_EXTRACT(data, '$.guestList.guests')) AS guest_count
      FROM customer_submissions${filter} ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?`,
      )
      .all(...(status ? [status, limit, offset] : [limit, offset]));
  }

  count(status) {
    return this.db
      .prepare(
        `SELECT COUNT(*) AS count FROM customer_submissions WHERE ${status ? 'status=?' : "status<>'uploading'"}`,
      )
      .get(...(status ? [status] : []));
  }

  expired(cutoff) {
    return this.db
      .prepare(
        "SELECT id, data FROM customer_submissions WHERE status='uploading' AND created_at<? LIMIT 20",
      )
      .all(cutoff);
  }

  removeExpired(id, cutoff) {
    return this.db
      .prepare(
        "DELETE FROM customer_submissions WHERE id=? AND status='uploading' AND created_at<?",
      )
      .run(id, cutoff);
  }

  async locked(id, work) {
    return this.db.transaction(async (tx) => {
      const row = await tx
        .prepare('SELECT * FROM customer_submissions WHERE id=? FOR UPDATE')
        .get(id);
      if (!row) throw new HttpError(404, 'Kiriman tidak ditemukan.');
      return work(row, tx);
    });
  }
}
