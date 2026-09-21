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
