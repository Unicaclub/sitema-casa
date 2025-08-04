-- Migration: Create JWT Tokens Table
-- Version: 007
-- Created: 2023-12-15

CREATE TABLE IF NOT EXISTS jwt_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_id VARCHAR(64) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    
    -- Token Details
    token_type ENUM('access', 'refresh', 'reset_password', 'email_verification', 'api_key') NOT NULL DEFAULT 'access',
    token_hash VARCHAR(255) NOT NULL,
    jti VARCHAR(64) NOT NULL UNIQUE, -- JWT ID from token payload
    
    -- Scopes and Permissions
    scopes JSON NULL,
    permissions JSON NULL,
    audience VARCHAR(255) NULL,
    
    -- Device and Context
    device_id VARCHAR(128) NULL,
    device_name VARCHAR(255) NULL,
    device_type ENUM('web', 'mobile', 'desktop', 'api', 'service') NOT NULL DEFAULT 'web',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    geo_location JSON NULL,
    
    -- Timing
    issued_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    not_before TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    revoked_at TIMESTAMP NULL,
    revoked_by BIGINT UNSIGNED NULL,
    revocation_reason ENUM('user_logout', 'admin_revoke', 'security_breach', 'expired', 'replaced', 'suspicious_activity') NULL,
    
    -- Security
    refresh_count INT UNSIGNED DEFAULT 0,
    max_refresh_count INT UNSIGNED DEFAULT 10,
    rate_limit_remaining INT UNSIGNED DEFAULT 1000,
    rate_limit_reset_at TIMESTAMP NULL,
    
    -- Metadata
    parent_token_id VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_token_id (token_id),
    INDEX idx_user_id (user_id),
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_token_hash (token_hash),
    INDEX idx_jti (jti),
    INDEX idx_token_type (token_type),
    INDEX idx_device_id (device_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_issued_at (issued_at),
    INDEX idx_expires_at (expires_at),
    INDEX idx_last_used_at (last_used_at),
    INDEX idx_is_active (is_active),
    INDEX idx_revoked_at (revoked_at),
    INDEX idx_cleanup_expired (expires_at, is_active),
    INDEX idx_security_monitoring (user_id, ip_address, created_at),
    INDEX idx_device_tracking (user_id, device_id, is_active),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;