export default class TemplateModel {
  constructor(db) {
    this.db = db;
  }
  list(category) {
    const fields =
      "id, name, category, description, image, package_id, renderer_id, is_custom, JSON_EXTRACT(data, '$.theme') AS theme";
    return category
      ? this.db
          .prepare(
            `SELECT ${fields} FROM templates WHERE category=? ORDER BY name`,
          )
          .all(category)
      : this.db.prepare(`SELECT ${fields} FROM templates ORDER BY name`).all();
  }
  updatePackage(id, packageId) {
    return this.db
      .prepare('UPDATE templates SET package_id=? WHERE id=?')
      .run(packageId, id);
  }
  updateData(id, data) {
    return this.db
      .prepare('UPDATE templates SET data=? WHERE id=?')
      .run(JSON.stringify(data), id);
  }
  find(id) {
    return this.db.prepare('SELECT * FROM templates WHERE id=?').get(id);
  }
  create(template) {
    return this.db
      .prepare(
        'INSERT INTO templates (id, name, category, description, image, data, package_id, renderer_id, is_custom) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
      )
      .run(
        template.id,
        template.name,
        template.category,
        template.description,
        template.img,
        JSON.stringify(template.data),
        template.packageId,
        template.rendererId,
      );
  }
}
