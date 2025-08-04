-- Migration: Create Threat Intelligence Table
-- Version: 003
-- Created: 2023-12-15

CREATE TABLE IF NOT EXISTS threat_intelligence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ioc_id VARCHAR(128) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    ioc_type ENUM('ip', 'domain', 'url', 'file_hash', 'email', 'user_agent', 'certificate', 'registry_key') NOT NULL,
    ioc_value VARCHAR(1000) NOT NULL,
    threat_type ENUM('malware', 'phishing', 'botnet', 'apt', 'ransomware', 'trojan', 'adware', 'scam', 'suspicious') NOT NULL,
    confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    severity ENUM('critical', 'high', 'medium', 'low') NOT NULL DEFAULT 'medium',
    source_feed VARCHAR(100) NOT NULL,
    source_reference VARCHAR(500) NULL,
    threat_actor VARCHAR(255) NULL,
    campaign_name VARCHAR(255) NULL,
    mitre_attack_techniques JSON NULL,
    tags JSON NULL,
    context_data JSON NULL,
    first_seen TIMESTAMP NOT NULL,
    last_seen TIMESTAMP NOT NULL,
    expiry_date TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    false_positive BOOLEAN DEFAULT FALSE,
    verified_by_analyst BOOLEAN DEFAULT FALSE,
    analyst_notes TEXT NULL,
    related_events JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_ioc_id (ioc_id),
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_ioc_type (ioc_type),
    INDEX idx_ioc_value (ioc_value(100)),
    INDEX idx_threat_type (threat_type),
    INDEX idx_confidence_score (confidence_score),
    INDEX idx_severity (severity),
    INDEX idx_source_feed (source_feed),
    INDEX idx_threat_actor (threat_actor),
    INDEX idx_campaign_name (campaign_name),
    INDEX idx_first_seen (first_seen),
    INDEX idx_last_seen (last_seen),
    INDEX idx_is_active (is_active),
    INDEX idx_expiry_date (expiry_date),
    INDEX idx_composite_search (tenant_id, ioc_type, threat_type, is_active),
    
    FULLTEXT idx_fulltext_search (ioc_value, threat_actor, campaign_name, analyst_notes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;