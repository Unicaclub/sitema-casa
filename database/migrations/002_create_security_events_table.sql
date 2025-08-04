-- Migration: Create Security Events Table
-- Version: 002
-- Created: 2023-12-15

CREATE TABLE IF NOT EXISTS security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(64) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('waf_block', 'ids_alert', 'threat_detected', 'login_attempt', 'access_denied', 'anomaly_detected', 'pentest_finding', 'compliance_violation') NOT NULL,
    severity ENUM('critical', 'high', 'medium', 'low', 'info') NOT NULL DEFAULT 'medium',
    source_system ENUM('waf', 'ids', 'ai_monitor', 'threat_intel', 'zero_trust', 'pentest', 'audit', 'user_action') NOT NULL,
    source_ip VARCHAR(45) NULL,
    user_id BIGINT UNSIGNED NULL,
    user_agent TEXT NULL,
    request_uri TEXT NULL,
    payload JSON NULL,
    threat_score DECIMAL(5,2) DEFAULT 0.00,
    confidence_score DECIMAL(5,2) DEFAULT 0.00,
    mitre_attack_id VARCHAR(10) NULL,
    indicators_of_compromise JSON NULL,
    geo_location JSON NULL,
    status ENUM('new', 'investigating', 'confirmed', 'false_positive', 'resolved', 'suppressed') NOT NULL DEFAULT 'new',
    response_actions JSON NULL,
    analyst_notes TEXT NULL,
    resolved_at TIMESTAMP NULL,
    resolved_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_event_id (event_id),
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_event_type (event_type),
    INDEX idx_severity (severity),
    INDEX idx_source_system (source_system),
    INDEX idx_source_ip (source_ip),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_threat_score (threat_score),
    INDEX idx_created_at (created_at),
    INDEX idx_mitre_attack (mitre_attack_id),
    INDEX idx_composite_search (tenant_id, event_type, severity, created_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;