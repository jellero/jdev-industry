CREATE DATABASE IF NOT EXISTS jdev_industry
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE jdev_industry;

CREATE TABLE clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(160) NOT NULL,
    code VARCHAR(60) NULL,
    contact_name VARCHAR(120) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(60) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clients_company (company_name),
    INDEX idx_clients_code (code)
) ENGINE=InnoDB;

CREATE TABLE machines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    api_timeout_seconds TINYINT UNSIGNED NOT NULL DEFAULT 5,
    poll_seconds TINYINT UNSIGNED NOT NULL DEFAULT 5,
    notes TEXT NULL,
    last_version VARCHAR(60) NULL,
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_machines_name (name)
) ENGINE=InnoDB;

CREATE TABLE jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NULL,
    machine_id INT UNSIGNED NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(180) NOT NULL,
    external_project VARCHAR(180) NULL,
    status ENUM('planned','ready','sent','in_progress','done','cancelled') NOT NULL DEFAULT 'planned',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_jobs_code (code),
    INDEX idx_jobs_external_project (external_project),
    CONSTRAINT fk_jobs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_jobs_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE event_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    machine_id INT UNSIGNED NOT NULL,
    job_id INT UNSIGNED NULL,
    event_key CHAR(64) NOT NULL,
    event_time DATETIME NULL,
    event_type VARCHAR(80) NULL,
    message VARCHAR(255) NULL,
    project VARCHAR(180) NULL,
    reference VARCHAR(180) NULL,
    material VARCHAR(180) NULL,
    value_num DECIMAL(18,4) NULL,
    number_num INT NULL,
    elapsed_time DECIMAL(18,3) NULL,
    waste DECIMAL(18,4) NULL,
    source_day DATE NULL,
    payload_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_machine_key (machine_id, event_key),
    INDEX idx_event_time (event_time),
    INDEX idx_event_project (project),
    INDEX idx_event_job (job_id),
    CONSTRAINT fk_events_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE machine_sync_status (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    machine_id INT UNSIGNED NOT NULL,
    operation VARCHAR(40) NOT NULL,
    last_attempt_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    last_status ENUM('never','ok','error') NOT NULL DEFAULT 'never',
    last_http_status SMALLINT UNSIGNED NULL,
    last_duration_ms INT UNSIGNED NULL,
    last_items INT UNSIGNED NOT NULL DEFAULT 0,
    last_message VARCHAR(500) NULL,
    last_payload_json LONGTEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_machine_operation (machine_id, operation),
    INDEX idx_sync_machine (machine_id),
    CONSTRAINT fk_sync_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE scheduled_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    machine_id INT UNSIGNED NOT NULL,
    operation VARCHAR(40) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    last_status ENUM('never','ok','error') NOT NULL DEFAULT 'never',
    last_message VARCHAR(500) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_task_machine_operation (machine_id, operation),
    INDEX idx_tasks_due (enabled, next_run_at),
    CONSTRAINT fk_tasks_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE company_settings (
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

INSERT INTO company_settings (id, company_name) VALUES (1, 'JDEV Industry');

CREATE TABLE pricing_rules (
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

CREATE TABLE job_cost_items (
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

CREATE TABLE job_cost_snapshots (
    job_id INT UNSIGNED NOT NULL PRIMARY KEY,
    automatic_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    manual_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    details_json LONGTEXT NOT NULL,
    calculated_at DATETIME NOT NULL,
    CONSTRAINT fk_snapshot_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB;
