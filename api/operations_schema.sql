CREATE TABLE IF NOT EXISTS tt_operation_records (
    storage_key VARCHAR(96) NOT NULL PRIMARY KEY,
    payload LONGTEXT NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    updated_by VARCHAR(160) NOT NULL,
    updated_by_user BIGINT NULL,
    updated_by_module VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_operation_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    storage_key VARCHAR(96) NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    updated_by VARCHAR(160) NOT NULL,
    updated_by_user BIGINT NULL,
    updated_by_module VARCHAR(32) NOT NULL,
    INDEX idx_operation_history_key_version (storage_key, version),
    INDEX idx_operation_history_time (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tt_export_documents (
    id CHAR(32) NOT NULL PRIMARY KEY,
    contract_ref VARCHAR(100) NOT NULL,
    lot_ref VARCHAR(100) NOT NULL DEFAULT '',
    category VARCHAR(64) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(80) NOT NULL UNIQUE,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    uploaded_at DATETIME(6) NOT NULL,
    uploaded_by VARCHAR(160) NOT NULL,
    uploaded_by_user BIGINT NULL,
    deleted_at DATETIME(6) NULL,
    INDEX idx_export_documents_lot (contract_ref, lot_ref, category),
    INDEX idx_export_documents_uploaded (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
