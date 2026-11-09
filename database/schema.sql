CREATE TABLE IF NOT EXISTS packages (
    id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    price BIGINT UNSIGNED NULL,
    sort_order TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS social_links (
    id TINYINT UNSIGNED PRIMARY KEY,
    data LONGTEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admins (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_sessions (
    token_hash CHAR(64) PRIMARY KEY,
    admin_id VARCHAR(36) NOT NULL,
    csrf_token CHAR(64) NOT NULL,
    expires_at BIGINT NOT NULL,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_session_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS templates (
    id VARCHAR(80) PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    image TEXT NOT NULL,
    data LONGTEXT NULL,
    package_id ENUM('silver','gold','platinum','exclusive') NULL,
    renderer_id VARCHAR(80) NULL,
    is_custom TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invitations (
    id VARCHAR(36) PRIMARY KEY,
    slug VARCHAR(80) NOT NULL UNIQUE,
    template_id VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    event_date VARCHAR(40) NOT NULL,
    data LONGTEXT NOT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    created_at VARCHAR(24) NOT NULL,
    updated_at VARCHAR(24) NOT NULL,
    FOREIGN KEY (template_id) REFERENCES templates(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_submissions (
    id VARCHAR(36) PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    data LONGTEXT NOT NULL,
    status ENUM('uploading','pending','accepted','rejected') NOT NULL,
    invitation_id VARCHAR(36) NULL,
    review_note VARCHAR(1000) NOT NULL DEFAULT '',
    created_at VARCHAR(24) NOT NULL,
    updated_at VARCHAR(24) NOT NULL,
    FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE SET NULL,
    INDEX idx_submissions_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invitation_guest_lists (
    invitation_id VARCHAR(36) PRIMARY KEY,
    data LONGTEXT NOT NULL,
    revision INT UNSIGNED NOT NULL,
    updated_at VARCHAR(24) NOT NULL,
    FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guest_photos (
    id VARCHAR(36) PRIMARY KEY,
    invitation_id VARCHAR(36) NOT NULL,
    filename VARCHAR(160) NOT NULL UNIQUE,
    mime VARCHAR(30) NOT NULL,
    size INT UNSIGNED NOT NULL,
    created_at VARCHAR(24) NOT NULL,
    FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE,
    INDEX idx_guest_photos_invitation (invitation_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rsvps (
    id VARCHAR(36) PRIMARY KEY,
    invitation_id VARCHAR(36) NOT NULL,
    name VARCHAR(80) NOT NULL,
    attendance ENUM('Hadir','Belum pasti','Tidak hadir') NOT NULL,
    guest_count TINYINT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    created_at VARCHAR(24) NOT NULL,
    FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE,
    INDEX idx_rsvps_invitation (invitation_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wishes (
    id VARCHAR(36) PRIMARY KEY,
    invitation_id VARCHAR(36) NOT NULL,
    name VARCHAR(80) NOT NULL,
    message TEXT NOT NULL,
    created_at VARCHAR(24) NOT NULL,
    guest_key CHAR(64) NULL,
    FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE,
    INDEX idx_wishes_invitation (invitation_id, created_at),
    UNIQUE KEY uq_wishes_guest (invitation_id, guest_key)
) ENGINE=InnoDB;
