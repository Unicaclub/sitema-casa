-- Migration: Create Performance Metrics Table
-- Version: 006
-- Created: 2023-12-15

CREATE TABLE IF NOT EXISTS performance_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_id VARCHAR(64) NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    
    -- Metric Identification
    metric_name VARCHAR(100) NOT NULL,
    metric_category ENUM('system', 'application', 'security', 'business', 'user_experience') NOT NULL,
    metric_type ENUM('counter', 'gauge', 'histogram', 'timer') NOT NULL DEFAULT 'gauge',
    
    -- Metric Values
    value_numeric DECIMAL(20,6) NULL,
    value_string VARCHAR(500) NULL,
    value_json JSON NULL,
    unit VARCHAR(20) NULL,
    
    -- Context
    source_system VARCHAR(100) NOT NULL,
    environment ENUM('production', 'staging', 'development', 'test') NOT NULL DEFAULT 'production',
    instance_id VARCHAR(100) NULL,
    node_id VARCHAR(100) NULL,
    
    -- Dimensions
    dimensions JSON NULL,
    tags JSON NULL,
    
    -- Aggregation Support
    aggregation_period ENUM('1min', '5min', '15min', '1hour', '1day') NOT NULL DEFAULT '1min',
    sample_count INT UNSIGNED DEFAULT 1,
    min_value DECIMAL(20,6) NULL,
    max_value DECIMAL(20,6) NULL,
    avg_value DECIMAL(20,6) NULL,
    sum_value DECIMAL(20,6) NULL,
    std_dev DECIMAL(20,6) NULL,
    percentile_95 DECIMAL(20,6) NULL,
    percentile_99 DECIMAL(20,6) NULL,
    
    -- Alerting
    alert_threshold_min DECIMAL(20,6) NULL,
    alert_threshold_max DECIMAL(20,6) NULL,
    alert_triggered BOOLEAN DEFAULT FALSE,
    
    -- Metadata
    collected_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_metric_id (metric_id),
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_metric_name (metric_name),
    INDEX idx_metric_category (metric_category),
    INDEX idx_source_system (source_system),
    INDEX idx_environment (environment),
    INDEX idx_aggregation_period (aggregation_period),
    INDEX idx_collected_at (collected_at),
    INDEX idx_alert_triggered (alert_triggered),
    INDEX idx_composite_search (tenant_id, metric_name, source_system, collected_at),
    INDEX idx_time_series (metric_name, collected_at, aggregation_period),
    INDEX idx_alerting (alert_triggered, metric_name, collected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partitioning by day for high-volume metrics
ALTER TABLE performance_metrics PARTITION BY RANGE (TO_DAYS(collected_at)) (
    PARTITION p20231215 VALUES LESS THAN (TO_DAYS('2023-12-16')),
    PARTITION p20231216 VALUES LESS THAN (TO_DAYS('2023-12-17')),
    PARTITION p20231217 VALUES LESS THAN (TO_DAYS('2023-12-18')),
    PARTITION p20231218 VALUES LESS THAN (TO_DAYS('2023-12-19')),
    PARTITION p20231219 VALUES LESS THAN (TO_DAYS('2023-12-20')),
    PARTITION p20231220 VALUES LESS THAN (TO_DAYS('2023-12-21')),
    PARTITION p20231221 VALUES LESS THAN (TO_DAYS('2023-12-22')),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);