export default class SocialLinksModel {
  constructor(db) {
    this.db = db;
  }

  find() {
    return this.db.prepare('SELECT data FROM social_links WHERE id=1').get();
  }

  update(data) {
    return this.db
      .prepare('UPDATE social_links SET data=? WHERE id=1')
      .run(JSON.stringify(data));
  }
}
