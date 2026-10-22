import mysql from 'mysql2/promise';
import { readFileSync } from 'node:fs';

export async function openDatabase(config) {
  if (!/^[a-zA-Z0-9_]{1,64}$/.test(config.database))
    throw new Error('Nama database tidak valid.');
  const { database, connectionLimit, ...connectionConfig } = config;
  const connection = await mysql.createConnection(connectionConfig);
  try {
    await connection.query(
      `CREATE DATABASE IF NOT EXISTS \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`,
    );
  } finally {
    await connection.end();
  }
  const pool = mysql.createPool({
    ...config,
    waitForConnections: true,
    queueLimit: 100,
    charset: 'utf8mb4',
    timezone: 'Z',
    connectTimeout: 10000,
  });
  try {
    const statements = [
      `CREATE TABLE IF NOT EXISTS packages (
        id VARCHAR(20) PRIMARY KEY, name VARCHAR(40) NOT NULL,
        price BIGINT UNSIGNED NULL, sort_order TINYINT UNSIGNED NOT NULL
      ) ENGINE=InnoDB`,
      `CREATE TABLE IF NOT EXISTS social_links (
        id TINYINT UNSIGNED PRIMARY KEY, data LONGTEXT NOT NULL
      ) ENGINE=InnoDB`,
      `CREATE TABLE IF NOT EXISTS admins (
    id VARCHAR(36) PRIMARY KEY, name VARCHAR(80) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE, password_hash VARCHAR(200) NOT NULL
  ) ENGINE=InnoDB`,
      `CREATE TABLE IF NOT EXISTS admin_sessions (
    token_hash CHAR(64) PRIMARY KEY, admin_id VARCHAR(36) NOT NULL,
    csrf_token CHAR(64) NOT NULL, expires_at BIGINT NOT NULL,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_session_expiry (expires_at)
  ) ENGINE=InnoDB`,
      `CREATE TABLE IF NOT EXISTS templates (
        id VARCHAR(80) PRIMARY KEY, name VARCHAR(160) NOT NULL,
        category VARCHAR(80) NOT NULL, description TEXT NOT NULL, image TEXT NOT NULL
      ) ENGINE=InnoDB`,
      `CREATE TABLE IF NOT EXISTS invitations (
        id VARCHAR(36) PRIMARY KEY, slug VARCHAR(80) NOT NULL UNIQUE,
        template_id VARCHAR(80) NOT NULL, title VARCHAR(160) NOT NULL,
        event_date VARCHAR(40) NOT NULL, data LONGTEXT NOT NULL,
        status ENUM('draft','published') NOT NULL DEFAULT 'draft',
        created_at VARCHAR(24) NOT NULL, updated_at VARCHAR(24) NOT NULL,
        FOREIGN KEY (template_id) REFERENCES templates(id)
      ) ENGINE=InnoDB`,
      `CREATE TABLE IF NOT EXISTS invitation_guest_lists (
        invitation_id VARCHAR(36) PRIMARY KEY, data LONGTEXT NOT NULL,
        revision INT UNSIGNED NOT NULL, updated_at VARCHAR(24) NOT NULL,
        FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE
      ) ENGINE=InnoDB`,
      `CREATE TABLE IF NOT EXISTS guest_photos (
        id VARCHAR(36) PRIMARY KEY, invitation_id VARCHAR(36) NOT NULL,
        filename VARCHAR(160) NOT NULL UNIQUE, mime VARCHAR(30) NOT NULL,
        size INT UNSIGNED NOT NULL, created_at VARCHAR(24) NOT NULL,
        FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE,
        INDEX idx_guest_photos_invitation (invitation_id, created_at)
      ) ENGINE=InnoDB`,
      `CREATE TABLE IF NOT EXISTS rsvps (
        id VARCHAR(36) PRIMARY KEY, invitation_id VARCHAR(36) NOT NULL,
        name VARCHAR(80) NOT NULL, attendance ENUM('Hadir','Belum pasti','Tidak hadir') NOT NULL,
        guest_count TINYINT UNSIGNED NOT NULL, note TEXT NOT NULL,
        created_at VARCHAR(24) NOT NULL,
        FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE,
        INDEX idx_rsvps_invitation (invitation_id, created_at)
      ) ENGINE=InnoDB`,
      `CREATE TABLE IF NOT EXISTS wishes (
        id VARCHAR(36) PRIMARY KEY, invitation_id VARCHAR(36) NOT NULL,
        name VARCHAR(80) NOT NULL, message TEXT NOT NULL, created_at VARCHAR(24) NOT NULL,
        FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE,
        INDEX idx_wishes_invitation (invitation_id, created_at)
      ) ENGINE=InnoDB`,
    ];
    for (const statement of statements) await pool.query(statement);
    const [wishGuestColumns] = await pool.query(
      "SHOW COLUMNS FROM wishes LIKE 'guest_key'",
    );
    if (!wishGuestColumns.length) {
      await pool.query(
        'ALTER TABLE wishes ADD COLUMN guest_key CHAR(64) NULL, ADD UNIQUE KEY uq_wishes_guest (invitation_id, guest_key)',
      );
    }
    await pool.execute(
      'INSERT IGNORE INTO social_links (id, data) VALUES (1, \'{"instagram":"","tiktok":"","whatsapp":""}\')',
    );
    for (const [index, name] of [
      'Silver',
      'Gold',
      'Platinum',
      'Exclusive',
    ].entries()) {
      await pool.execute(
        'INSERT IGNORE INTO packages (id, name, price, sort_order) VALUES (?, ?, NULL, ?)',
        [name.toLowerCase(), name, index],
      );
    }
    const [columns] = await pool.query(
      "SHOW COLUMNS FROM templates LIKE 'data'",
    );
    if (!columns.length)
      await pool.query('ALTER TABLE templates ADD COLUMN data LONGTEXT NULL');
    const [packageColumns] = await pool.query(
      "SHOW COLUMNS FROM templates LIKE 'package_id'",
    );
    if (!packageColumns.length) {
      await pool.query(
        "ALTER TABLE templates ADD COLUMN package_id ENUM('silver','gold','platinum','exclusive') NULL",
      );
    }
    const defaults = JSON.parse(
      readFileSync(new URL('./templateData.json', import.meta.url), 'utf8'),
    );
    const templates = JSON.parse(
      readFileSync(new URL('./templates.json', import.meta.url), 'utf8'),
    );
    for (const template of templates) {
      await pool.execute(
        `INSERT INTO templates (id, name, category, description, image, data, package_id)
        VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE
        name=VALUES(name), category=VALUES(category), description=VALUES(description), image=VALUES(image), data=COALESCE(data, VALUES(data)), package_id=COALESCE(package_id, VALUES(package_id))`,
        [
          template.id,
          template.name,
          template.category,
          template.description,
          template.img,
          JSON.stringify(defaults[template.id]),
          template.packageId,
        ],
      );
      const [rows] = await pool.execute(
        'SELECT data FROM templates WHERE id=?',
        [template.id],
      );
      const templateData = JSON.parse(rows[0].data || '{}');
      let dataChanged = false;
      for (const [key, defaultValue] of Object.entries({
        agenda: [],
        videoUrl: '',
        prayers: [],
      })) {
        if (!(key in templateData)) {
          templateData[key] = defaultValue;
          dataChanged = true;
        }
      }
      if (dataChanged) {
        await pool.execute('UPDATE templates SET data=? WHERE id=?', [
          JSON.stringify(templateData),
          template.id,
        ]);
      }
    }
    return {
      prepare(sql) {
        return {
          async get(...parameters) {
            const [rows] = await pool.query(sql, parameters);
            return rows[0];
          },
          async all(...parameters) {
            const [rows] = await pool.query(sql, parameters);
            return rows;
          },
          async run(...parameters) {
            const [result] = await pool.query(sql, parameters);
            return { changes: result.affectedRows };
          },
        };
      },
      async close() {
        await pool.end();
      },
    };
  } catch (error) {
    await pool.end();
    throw error;
  }
}
