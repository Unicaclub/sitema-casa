-- Migration: Tabelas para monitoramento multi-tenant
-- Data: 2025-08-04

-- =====================================================
-- 1. TABELA DE MÉTRICAS DE MONITORAMENTO
-- =====================================================

CREATE TABLE tenant_monitoring_metrics (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    metrics_data JSON NOT NULL,
    collected_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_monitoring_tenant (tenant_id),
    INDEX idx_tenant_monitoring_collected (collected_at),
    INDEX idx_tenant_monitoring_tenant_collected (tenant_id, collected_at)
);

-- =====================================================
-- 2. TABELA DE ALERTAS DE MONITORAMENTO
-- =====================================================

CREATE TABLE tenant_monitoring_alerts (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    alert_type VARCHAR(100) NOT NULL,
    severity ENUM('info', 'warning', 'error', 'critical') NOT NULL,
    message TEXT NOT NULL,
    alert_data JSON,
    status ENUM('active', 'acknowledged', 'resolved') DEFAULT 'active',
    acknowledged_by INT NULL,
    acknowledged_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant_alerts_tenant (tenant_id),
    INDEX idx_tenant_alerts_type (alert_type),
    INDEX idx_tenant_alerts_severity (severity),
    INDEX idx_tenant_alerts_status (status),
    INDEX idx_tenant_alerts_created (created_at),
    INDEX idx_tenant_alerts_tenant_status (tenant_id, status)
);

-- =====================================================
-- 3. TABELA DE RELATÓRIOS DE SEGURANÇA
-- =====================================================

CREATE TABLE tenant_security_reports (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    report_type VARCHAR(50) DEFAULT 'security',
    period_days INT NOT NULL,
    report_data JSON NOT NULL,
    generated_by INT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant_reports_tenant (tenant_id),
    INDEX idx_tenant_reports_type (report_type),
    INDEX idx_tenant_reports_generated (generated_at),
    INDEX idx_tenant_reports_tenant_generated (tenant_id, generated_at)
);

-- =====================================================
-- 4. TABELA DE CONFIGURAÇÕES DE MONITORAMENTO
-- =====================================================

CREATE TABLE tenant_monitoring_config (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    config_key VARCHAR(100) NOT NULL,
    config_value JSON NOT NULL,
    enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_config (tenant_id, config_key),
    INDEX idx_tenant_config_tenant (tenant_id),
    INDEX idx_tenant_config_key (config_key),
    INDEX idx_tenant_config_enabled (enabled)
);

-- =====================================================
-- 5. TABELA DE DASHBOARD DE MÉTRICAS (MATERIALIZED VIEW)
-- =====================================================

CREATE TABLE tenant_metrics_dashboard (
    tenant_id INT NOT NULL,
    metric_date DATE NOT NULL,
    active_users INT DEFAULT 0,
    api_requests INT DEFAULT 0,
    data_operations INT DEFAULT 0,
    security_events INT DEFAULT 0,
    cross_tenant_attempts INT DEFAULT 0,
    avg_query_time_ms DECIMAL(10,2) DEFAULT 0,
    cache_hit_rate_percent DECIMAL(5,2) DEFAULT 0,
    data_size_mb DECIMAL(10,2) DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (tenant_id, metric_date),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_dashboard_date (metric_date),
    INDEX idx_dashboard_updated (last_updated)
);

-- =====================================================
-- 6. STORED PROCEDURES PARA AGREGAÇÃO DE MÉTRICAS
-- =====================================================

DELIMITER //

-- Procedure para agregar métricas diárias
CREATE PROCEDURE AggregateDailyMetrics(IN target_date DATE)
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_tenant_id INT;
    DECLARE tenant_cursor CURSOR FOR 
        SELECT id FROM tenants WHERE active = TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN tenant_cursor;
    
    tenant_loop: LOOP
        FETCH tenant_cursor INTO v_tenant_id;
        IF done THEN
            LEAVE tenant_loop;
        END IF;
        
        -- Inserir ou atualizar métricas do dia
        INSERT INTO tenant_metrics_dashboard (
            tenant_id, 
            metric_date,
            active_users,
            api_requests,
            data_operations,
            security_events,
            cross_tenant_attempts,
            avg_query_time_ms,
            cache_hit_rate_percent
        )
        SELECT 
            v_tenant_id,
            target_date,
            COUNT(DISTINCT CASE WHEN category = 'api_access' THEN user_id END) as active_users,
            COUNT(CASE WHEN category = 'api_access' THEN 1 END) as api_requests,
            COUNT(CASE WHEN category = 'data_operation' THEN 1 END) as data_operations,
            COUNT(CASE WHEN category = 'security' THEN 1 END) as security_events,
            0 as cross_tenant_attempts, -- Será calculado separadamente
            0 as avg_query_time_ms,     -- Será calculado separadamente
            0 as cache_hit_rate_percent -- Será calculado separadamente
        FROM audit_logs 
        WHERE tenant_id = v_tenant_id 
        AND DATE(created_at) = target_date
        ON DUPLICATE KEY UPDATE
            active_users = VALUES(active_users),
            api_requests = VALUES(api_requests),
            data_operations = VALUES(data_operations),
            security_events = VALUES(security_events);
        
        -- Atualizar tentativas de acesso cruzado
        UPDATE tenant_metrics_dashboard 
        SET cross_tenant_attempts = (
            SELECT COUNT(*) 
            FROM tenant_access_logs 
            WHERE current_tenant_id = v_tenant_id 
            AND access_granted = FALSE 
            AND DATE(created_at) = target_date
        )
        WHERE tenant_id = v_tenant_id AND metric_date = target_date;
        
        -- Atualizar métricas de performance
        UPDATE tenant_metrics_dashboard 
        SET 
            avg_query_time_ms = COALESCE((
                SELECT AVG(metric_value) 
                FROM performance_metrics 
                WHERE tenant_id = v_tenant_id 
                AND metric_name = 'query_time'
                AND DATE(collected_at) = target_date
            ), 0),
            data_size_mb = COALESCE((
                SELECT metric_value / 1024 / 1024
                FROM performance_metrics 
                WHERE tenant_id = v_tenant_id 
                AND metric_name = 'data_size'
                AND DATE(collected_at) = target_date
                ORDER BY collected_at DESC
                LIMIT 1
            ), 0)
        WHERE tenant_id = v_tenant_id AND metric_date = target_date;
        
    END LOOP;
    
    CLOSE tenant_cursor;
END//

-- Procedure para limpeza de métricas antigas
CREATE PROCEDURE CleanupOldMetrics(IN retention_days INT)
BEGIN
    DECLARE cleanup_date DATETIME;
    SET cleanup_date = DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- Limpar métricas antigas
    DELETE FROM tenant_monitoring_metrics WHERE collected_at < cleanup_date;
    
    -- Limpar alertas resolvidos antigos
    DELETE FROM tenant_monitoring_alerts 
    WHERE status = 'resolved' AND resolved_at < cleanup_date;
    
    -- Limpar relatórios antigos
    DELETE FROM tenant_security_reports WHERE generated_at < cleanup_date;
    
    -- Limpar logs de performance antigos
    DELETE FROM performance_metrics WHERE collected_at < cleanup_date;
    
    -- Manter apenas dashboard dos últimos 90 dias
    DELETE FROM tenant_metrics_dashboard 
    WHERE metric_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY);
END//

DELIMITER ;

-- =====================================================
-- 7. VIEWS PARA RELATÓRIOS
-- =====================================================

-- View de alertas ativos por tenant
CREATE VIEW vw_active_alerts AS
SELECT 
    t.id as tenant_id,
    t.name as tenant_name,
    a.alert_type,
    a.severity,
    a.message,
    a.created_at,
    TIMESTAMPDIFF(HOUR, a.created_at, NOW()) as hours_active
FROM tenant_monitoring_alerts a
JOIN tenants t ON a.tenant_id = t.id
WHERE a.status = 'active'
ORDER BY a.severity DESC, a.created_at DESC;

-- View de métricas de segurança consolidadas
CREATE VIEW vw_security_metrics AS
SELECT 
    d.tenant_id,
    t.name as tenant_name,
    d.metric_date,
    d.security_events,
    d.cross_tenant_attempts,
    CASE 
        WHEN d.cross_tenant_attempts > 0 THEN 'high_risk'
        WHEN d.security_events > 10 THEN 'medium_risk'
        ELSE 'low_risk'
    END as risk_level
FROM tenant_metrics_dashboard d
JOIN tenants t ON d.tenant_id = t.id
WHERE d.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
ORDER BY d.metric_date DESC, risk_level DESC;

-- View de performance por tenant
CREATE VIEW vw_performance_metrics AS
SELECT 
    d.tenant_id,
    t.name as tenant_name,
    d.metric_date,
    d.avg_query_time_ms,
    d.cache_hit_rate_percent,
    d.data_size_mb,
    d.api_requests,
    CASE 
        WHEN d.avg_query_time_ms > 1000 THEN 'poor'
        WHEN d.avg_query_time_ms > 500 THEN 'fair'
        ELSE 'good'
    END as performance_rating
FROM tenant_metrics_dashboard d
JOIN tenants t ON d.tenant_id = t.id
WHERE d.metric_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
ORDER BY d.metric_date DESC, d.avg_query_time_ms DESC;

-- =====================================================
-- 8. TRIGGERS PARA MONITORAMENTO AUTOMÁTICO
-- =====================================================

DELIMITER //

-- Trigger para detectar queries sem filtro de tenant
CREATE TRIGGER monitor_tenant_queries
AFTER INSERT ON performance_metrics
FOR EACH ROW
BEGIN
    -- Se uma query não tem tenant_id definido, criar alerta
    IF NEW.tenant_id IS NULL AND NEW.metric_name = 'query_time' THEN
        INSERT INTO tenant_monitoring_alerts (
            tenant_id,
            alert_type,
            severity,
            message,
            alert_data
        ) VALUES (
            1, -- Tenant padrão para alertas de sistema
            'query_without_tenant_filter',
            'critical',
            'Query executada sem filtro por tenant_id',
            JSON_OBJECT('metric_id', NEW.id, 'query_info', NEW.additional_data)
        );
    END IF;
END//

-- Trigger para monitorar tentativas de acesso cruzado
CREATE TRIGGER monitor_cross_tenant_access
AFTER INSERT ON tenant_access_logs
FOR EACH ROW
BEGIN
    -- Se houve tentativa negada, verificar se deve criar alerta
    IF NEW.access_granted = FALSE THEN
        -- Contar tentativas da última hora
        SET @recent_attempts = (
            SELECT COUNT(*) 
            FROM tenant_access_logs 
            WHERE user_id = NEW.user_id 
            AND access_granted = FALSE 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        );
        
        -- Se mais de 5 tentativas, criar alerta
        IF @recent_attempts >= 5 THEN
            INSERT INTO tenant_monitoring_alerts (
                tenant_id,
                alert_type,
                severity,
                message,
                alert_data
            ) VALUES (
                NEW.current_tenant_id,
                'multiple_cross_tenant_attempts',
                'critical',
                CONCAT('Usuário ', NEW.user_id, ' fez ', @recent_attempts, ' tentativas de acesso cruzado na última hora'),
                JSON_OBJECT('user_id', NEW.user_id, 'attempts', @recent_attempts, 'ip_address', NEW.ip_address)
            );
        END IF;
    END IF;
END//

DELIMITER ;

-- =====================================================
-- 9. ÍNDICES ADICIONAIS PARA PERFORMANCE
-- =====================================================

-- Índices compostos para consultas frequentes
CREATE INDEX idx_audit_logs_tenant_category_date ON audit_logs(tenant_id, category, created_at);
CREATE INDEX idx_audit_logs_tenant_level_date ON audit_logs(tenant_id, level, created_at);
CREATE INDEX idx_performance_metrics_tenant_name_date ON performance_metrics(tenant_id, metric_name, collected_at);

-- =====================================================
-- 10. CONFIGURAÇÕES PADRÃO DE MONITORAMENTO
-- =====================================================

-- Inserir configurações padrão para monitoramento
INSERT INTO tenant_monitoring_config (tenant_id, config_key, config_value) 
SELECT 
    id as tenant_id,
    'alert_thresholds',
    JSON_OBJECT(
        'max_cross_tenant_attempts_per_hour', 5,
        'max_query_time_ms', 1000,
        'max_memory_usage_mb', 512,
        'max_api_errors_per_minute', 10,
        'min_cache_hit_rate_percent', 70
    )
FROM tenants
WHERE active = TRUE;

INSERT INTO tenant_monitoring_config (tenant_id, config_key, config_value)
SELECT 
    id as tenant_id,
    'monitoring_settings',
    JSON_OBJECT(
        'collect_metrics_interval_minutes', 5,
        'generate_reports_interval_hours', 24,
        'cleanup_old_data_days', 90,
        'enable_real_time_alerts', true,
        'enable_email_notifications', true
    )
FROM tenants
WHERE active = TRUE;

-- =====================================================
-- 11. COMENTÁRIOS PARA DOCUMENTAÇÃO
-- =====================================================

ALTER TABLE tenant_monitoring_metrics COMMENT = 'Métricas coletadas em tempo real para monitoramento multi-tenant';
ALTER TABLE tenant_monitoring_alerts COMMENT = 'Alertas gerados pelo sistema de monitoramento multi-tenant';
ALTER TABLE tenant_security_reports COMMENT = 'Relatórios de segurança gerados periodicamente para cada tenant';
ALTER TABLE tenant_monitoring_config COMMENT = 'Configurações de monitoramento personalizáveis por tenant';
ALTER TABLE tenant_metrics_dashboard COMMENT = 'Dashboard agregado de métricas diárias por tenant';

-- Comentários em colunas importantes
ALTER TABLE tenant_monitoring_metrics MODIFY COLUMN metrics_data JSON COMMENT 'Dados de métricas em formato JSON contendo performance, segurança, uso e saúde';
ALTER TABLE tenant_monitoring_alerts MODIFY COLUMN alert_data JSON COMMENT 'Dados detalhados do alerta em formato JSON';
ALTER TABLE tenant_security_reports MODIFY COLUMN report_data JSON COMMENT 'Dados completos do relatório de segurança em formato JSON';