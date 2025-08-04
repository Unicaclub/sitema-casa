-- Migration: Create Security Incidents Table
-- Version: 004
-- Created: 2023-12-15

CREATE TABLE IF NOT EXISTS security_incidents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_id VARCHAR(64) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT NULL,
    category ENUM('malware', 'phishing', 'data_breach', 'insider_threat', 'ddos', 'vulnerability', 'policy_violation', 'unauthorized_access', 'other') NOT NULL,
    severity ENUM('critical', 'high', 'medium', 'low') NOT NULL DEFAULT 'medium',
    priority ENUM('p1', 'p2', 'p3', 'p4') NOT NULL DEFAULT 'p3',
    status ENUM('new', 'assigned', 'investigating', 'contained', 'eradicated', 'recovering', 'resolved', 'closed') NOT NULL DEFAULT 'new',
    
    -- Assignment and Ownership
    assigned_to BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    team_assigned VARCHAR(100) NULL,
    
    -- Timing
    detected_at TIMESTAMP NOT NULL,
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP NULL,
    contained_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    closed_at TIMESTAMP NULL,
    
    -- SLA Tracking
    sla_deadline TIMESTAMP NULL,
    sla_breached BOOLEAN DEFAULT FALSE,
    response_time_minutes INT UNSIGNED NULL,
    resolution_time_minutes INT UNSIGNED NULL,
    
    -- Impact Assessment
    affected_systems JSON NULL,
    affected_users_count INT UNSIGNED DEFAULT 0,
    estimated_impact ENUM('none', 'minimal', 'moderate', 'significant', 'severe') DEFAULT 'none',
    business_impact_description TEXT NULL,
    
    -- Technical Details
    attack_vector ENUM('email', 'web', 'network', 'usb', 'social_engineering', 'insider', 'physical', 'supply_chain', 'unknown') NULL,
    root_cause TEXT NULL,
    indicators_of_compromise JSON NULL,
    evidence_files JSON NULL,
    network_logs_location VARCHAR(500) NULL,
    
    -- Response Actions
    containment_actions JSON NULL,
    eradication_actions JSON NULL,
    recovery_actions JSON NULL,
    lessons_learned TEXT NULL,
    recommendations TEXT NULL,
    
    -- Communication
    stakeholders_notified JSON NULL,
    external_notifications JSON NULL,
    regulatory_reporting_required BOOLEAN DEFAULT FALSE,
    regulatory_reports JSON NULL,
    
    -- Related Data
    related_events JSON NULL,
    related_incidents JSON NULL,
    threat_actor VARCHAR(255) NULL,
    campaign_name VARCHAR(255) NULL,
    
    -- Metadata
    tags JSON NULL,
    custom_fields JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_incident_id (incident_id),
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_category (category),
    INDEX idx_severity (severity),
    INDEX idx_priority (priority),
    INDEX idx_status (status),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_created_by (created_by),
    INDEX idx_detected_at (detected_at),
    INDEX idx_sla_deadline (sla_deadline),
    INDEX idx_sla_breached (sla_breached),
    INDEX idx_attack_vector (attack_vector),
    INDEX idx_threat_actor (threat_actor),
    INDEX idx_campaign_name (campaign_name),
    INDEX idx_composite_search (tenant_id, status, severity, detected_at),
    INDEX idx_sla_monitoring (status, sla_deadline, sla_breached),
    
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    
    FULLTEXT idx_fulltext_search (title, description, root_cause, lessons_learned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;