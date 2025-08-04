-- Migration: Create Audit Logs Table
-- Version: 005
-- Created: 2023-12-15

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_id VARCHAR(64) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    
    -- Event Details
    event_type VARCHAR(100) NOT NULL,
    event_category ENUM('authentication', 'authorization', 'data_access', 'data_modification', 'system_config', 'security_action', 'user_management', 'compliance') NOT NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(100) NULL,
    resource_id VARCHAR(255) NULL,
    resource_name VARCHAR(500) NULL,
    
    -- User Context
    user_id BIGINT UNSIGNED NULL,
    user_email VARCHAR(255) NULL,
    user_name VARCHAR(255) NULL,
    user_role VARCHAR(50) NULL,
    impersonated_by BIGINT UNSIGNED NULL,
    
    -- Request Context
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    request_id VARCHAR(64) NULL,
    session_id VARCHAR(128) NULL,
    device_fingerprint VARCHAR(128) NULL,
    geo_location JSON NULL,
    
    -- Technical Details
    http_method ENUM('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD') NULL,
    endpoint VARCHAR(500) NULL,
    request_payload JSON NULL,
    response_status INT UNSIGNED NULL,
    response_payload JSON NULL,
    
    -- Data Changes
    old_values JSON NULL,
    new_values JSON NULL,
    changed_fields JSON NULL,
    
    -- Risk Assessment
    risk_score DECIMAL(5,2) DEFAULT 0.00,
    anomaly_detected BOOLEAN DEFAULT FALSE,
    policy_violations JSON NULL,
    
    -- Result and Impact
    success BOOLEAN NOT NULL DEFAULT TRUE,
    error_message TEXT NULL,
    business_impact ENUM('none', 'low', 'medium', 'high', 'critical') DEFAULT 'none',
    
    -- Compliance
    retention_period_days INT UNSIGNED DEFAULT 2555, -- 7 years default
    compliance_tags JSON NULL,
    sensitive_data_involved BOOLEAN DEFAULT FALSE,
    data_classification ENUM('public', 'internal', 'confidential', 'restricted') DEFAULT 'internal',
    
    -- Additional Context
    correlation_id VARCHAR(64) NULL,
    parent_audit_id VARCHAR(64) NULL,
    tags JSON NULL,
    notes TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_audit_id (audit_id),
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_event_type (event_type),
    INDEX idx_event_category (event_category),
    INDEX idx_action (action),
    INDEX idx_user_id (user_id),
    INDEX idx_user_email (user_email),
    INDEX idx_ip_address (ip_address),
    INDEX idx_resource_type_id (resource_type, resource_id),
    INDEX idx_success (success),
    INDEX idx_risk_score (risk_score),
    INDEX idx_anomaly_detected (anomaly_detected),
    INDEX idx_business_impact (business_impact),
    INDEX idx_correlation_id (correlation_id),
    INDEX idx_created_at (created_at),
    INDEX idx_retention_cleanup (created_at, retention_period_days),
    INDEX idx_composite_search (tenant_id, event_category, user_id, created_at),
    INDEX idx_security_monitoring (risk_score, anomaly_detected, business_impact, created_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (impersonated_by) REFERENCES users(id) ON DELETE SET NULL,
    
    FULLTEXT idx_fulltext_search (resource_name, error_message, notes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partitioning by month for performance
ALTER TABLE audit_logs PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
    PARTITION p202312 VALUES LESS THAN (202401),
    PARTITION p202401 VALUES LESS THAN (202402),
    PARTITION p202402 VALUES LESS THAN (202403),
    PARTITION p202403 VALUES LESS THAN (202404),
    PARTITION p202404 VALUES LESS THAN (202405),
    PARTITION p202405 VALUES LESS THAN (202406),
    PARTITION p202406 VALUES LESS THAN (202407),
    PARTITION p202407 VALUES LESS THAN (202408),
    PARTITION p202408 VALUES LESS THAN (202409),
    PARTITION p202409 VALUES LESS THAN (202410),
    PARTITION p202410 VALUES LESS THAN (202411),
    PARTITION p202411 VALUES LESS THAN (202412),
    PARTITION p202412 VALUES LESS THAN (202501),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);