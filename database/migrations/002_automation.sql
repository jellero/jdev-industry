USE jdev_industry;

CREATE TABLE IF NOT EXISTS machine_sync_status (
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

CREATE TABLE IF NOT EXISTS scheduled_tasks (
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
