export default class PackageModel {
  constructor(db) {
    this.db = db;
  }

  list() {
    return this.db.prepare('SELECT * FROM packages ORDER BY sort_order').all();
  }

  find(id) {
    return this.db.prepare('SELECT * FROM packages WHERE id=?').get(id);
  }

  updatePrice(id, price) {
    return this.db
      .prepare('UPDATE packages SET price=? WHERE id=?')
      .run(price, id);
  }
}
