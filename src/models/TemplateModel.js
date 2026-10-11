export default class TemplateModel {
  constructor(db) {
    this.db = db;
  }
  list(category) {
    return category
      ? this.db
          .prepare('SELECT * FROM templates WHERE category=? ORDER BY name')
          .all(category)
      : this.db.prepare('SELECT * FROM templates ORDER BY name').all();
  }
  find(id) {
    return this.db.prepare('SELECT * FROM templates WHERE id=?').get(id);
  }
}
