CREATE TABLE IF NOT EXISTS company_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    company_name VARCHAR(180) NOT NULL DEFAULT 'JDEV Industry',
    address VARCHAR(255) NULL,
    vat_number VARCHAR(60) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(60) NULL,
    logo_path VARCHAR(255) NULL,
    print_footer VARCHAR(500) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO company_settings (id, company_name) VALUES (1, 'JDEV Industry');

CREATE TABLE IF NOT EXISTS pricing_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    basis ENUM('hour','cut','scheme','job') NOT NULL,
    unit_price DECIMAL(14,4) NOT NULL DEFAULT 0,
    machine_id INT UNSIGNED NULL,
    material_match VARCHAR(180) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pricing_active (active),
    INDEX idx_pricing_machine (machine_id),
    CONSTRAINT fk_pricing_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS job_cost_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    category ENUM('material','extra','discount') NOT NULL DEFAULT 'material',
    description VARCHAR(190) NOT NULL,
    quantity DECIMAL(14,4) NOT NULL DEFAULT 1,
    unit VARCHAR(30) NOT NULL DEFAULT 'pz',
    unit_price DECIMAL(14,4) NOT NULL DEFAULT 0,
    cost_date DATE NOT NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cost_job (job_id),
    INDEX idx_cost_date (cost_date),
    CONSTRAINT fk_cost_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS job_cost_snapshots (
    job_id INT UNSIGNED NOT NULL PRIMARY KEY,
    automatic_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    manual_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    details_json LONGTEXT NOT NULL,
    calculated_at DATETIME NOT NULL,
    CONSTRAINT fk_snapshot_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB;
