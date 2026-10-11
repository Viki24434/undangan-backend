export default class AdminModel {
  constructor(db) {
    this.db = db;
  }
  find() {
    return this.db
      .prepare("SELECT * FROM admins WHERE id='primary-admin'")
      .get();
  }
  create(name, email, passwordHash) {
    return this.db
      .prepare(
        'INSERT INTO admins (id, name, email, password_hash) VALUES (?, ?, ?, ?)',
      )
      .run('primary-admin', name, email, passwordHash);
  }
  createSession(hash, csrfToken, expiresAt) {
    return this.db
      .prepare(
        'INSERT INTO admin_sessions (token_hash, admin_id, csrf_token, expires_at) VALUES (?, ?, ?, ?)',
      )
      .run(hash, 'primary-admin', csrfToken, expiresAt);
  }
  session(hash) {
    return this.db
      .prepare(
        'SELECT s.*, a.name, a.email FROM admin_sessions s JOIN admins a ON a.id=s.admin_id WHERE token_hash=? AND expires_at>?',
      )
      .get(hash, Date.now());
  }
  removeSession(hash) {
    return this.db
      .prepare('DELETE FROM admin_sessions WHERE token_hash=?')
      .run(hash);
  }
  cleanup() {
    return this.db
      .prepare('DELETE FROM admin_sessions WHERE expires_at<=?')
      .run(Date.now());
  }
}
