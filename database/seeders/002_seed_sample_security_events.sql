-- Seeder: Sample Security Events
-- Version: 002
-- Created: 2023-12-15

-- Sample WAF blocks
INSERT INTO security_events (
    event_id, tenant_id, event_type, severity, source_system, source_ip, 
    payload, threat_score, confidence_score, status, created_at
) VALUES 
(
    CONCAT('waf_', UNIX_TIMESTAMP(), '_001'), 1, 'waf_block', 'high', 'waf', '192.168.1.100',
    JSON_OBJECT(
        'attack_type', 'sql_injection',
        'blocked_payload', 'SELECT * FROM users WHERE id=1 OR 1=1--',
        'rule_triggered', 'OWASP_CRS_942100_SQL_INJECTION',
        'user_agent', 'Mozilla/5.0 (Malicious Scanner)'
    ),
    85.5, 92.3, 'confirmed', NOW() - INTERVAL 2 HOUR
),
(
    CONCAT('waf_', UNIX_TIMESTAMP(), '_002'), 1, 'waf_block', 'medium', 'waf', '10.0.0.50',
    JSON_OBJECT(
        'attack_type', 'xss_attempt',
        'blocked_payload', '<script>alert("XSS")</script>',
        'rule_triggered', 'OWASP_CRS_941100_XSS_ATTACK',
        'user_agent', 'Chrome/91.0.4472.124'
    ),
    67.2, 78.9, 'confirmed', NOW() - INTERVAL 1 HOUR
);

-- Sample IDS alerts
INSERT INTO security_events (
    event_id, tenant_id, event_type, severity, source_system, source_ip,
    payload, threat_score, confidence_score, mitre_attack_id, status, created_at
) VALUES 
(
    CONCAT('ids_', UNIX_TIMESTAMP(), '_001'), 1, 'ids_alert', 'critical', 'ids', '172.16.1.200',
    JSON_OBJECT(
        'signature_id', 'ET_TROJAN_Metasploit_Meterpreter',
        'protocol', 'TCP',
        'destination_port', 4444,
        'packet_data', 'suspicious_binary_data_detected'
    ),
    94.7, 96.1, 'T1055', 'investigating', NOW() - INTERVAL 30 MINUTE
),
(
    CONCAT('ids_', UNIX_TIMESTAMP(), '_002'), 1, 'threat_detected', 'high', 'ids', '203.0.113.15',
    JSON_OBJECT(
        'threat_type', 'port_scan',
        'scanned_ports', JSON_ARRAY(22, 80, 443, 3389, 5432),
        'scan_duration_seconds', 45,
        'packets_count', 1250
    ),
    78.3, 85.7, 'T1046', 'new', NOW() - INTERVAL 15 MINUTE
);

-- Sample anomaly detections
INSERT INTO security_events (
    event_id, tenant_id, event_type, severity, source_system, user_id,
    payload, threat_score, confidence_score, status, created_at
) VALUES 
(
    CONCAT('ai_', UNIX_TIMESTAMP(), '_001'), 1, 'anomaly_detected', 'medium', 'ai_monitor', 2,
    JSON_OBJECT(
        'anomaly_type', 'unusual_login_time',
        'normal_login_hours', JSON_ARRAY(8, 9, 10, 11, 14, 15, 16, 17),
        'current_login_hour', 23,
        'confidence_deviation', 2.3,
        'user_behavior_score', 0.15
    ),
    45.2, 71.8, 'investigating', NOW() - INTERVAL 5 MINUTE
),
(
    CONCAT('ai_', UNIX_TIMESTAMP(), '_002'), 1, 'anomaly_detected', 'high', 'ai_monitor', 3,
    JSON_OBJECT(
        'anomaly_type', 'data_exfiltration_pattern',
        'normal_download_size_mb', 15.2,
        'current_download_size_mb', 250.7,
        'download_frequency_multiplier', 8.5,
        'suspicious_file_types', JSON_ARRAY('database', 'archive', 'document')
    ),
    82.1, 88.4, 'new', NOW() - INTERVAL 2 MINUTE
);

-- Sample threat intelligence hits
INSERT INTO security_events (
    event_id, tenant_id, event_type, severity, source_system, source_ip,
    payload, threat_score, confidence_score, indicators_of_compromise, status, created_at
) VALUES 
(
    CONCAT('ti_', UNIX_TIMESTAMP(), '_001'), 1, 'threat_detected', 'critical', 'threat_intel', '198.51.100.42',
    JSON_OBJECT(
        'threat_source', 'known_apt_c2',
        'campaign_name', 'Operation GhostWriter',
        'first_seen_days_ago', 3,
        'threat_actor', 'APT28',
        'malware_family', 'Sofacy'
    ),
    97.8, 98.9, 
    JSON_OBJECT(
        'ip_addresses', JSON_ARRAY('198.51.100.42', '203.0.113.99'),
        'domains', JSON_ARRAY('malicious-c2.example.com'),
        'file_hashes', JSON_ARRAY('d41d8cd98f00b204e9800998ecf8427e')
    ),
    'confirmed', NOW() - INTERVAL 45 MINUTE
);

-- Sample compliance violations
INSERT INTO security_events (
    event_id, tenant_id, event_type, severity, source_system, user_id,
    payload, threat_score, confidence_score, status, created_at
) VALUES 
(
    CONCAT('comp_', UNIX_TIMESTAMP(), '_001'), 1, 'compliance_violation', 'medium', 'audit', 4,
    JSON_OBJECT(
        'violation_type', 'data_retention_exceeded',
        'regulation', 'LGPD',
        'article_reference', 'Art. 16',
        'data_age_days', 2700,
        'max_retention_days', 2555,
        'affected_records_count', 15420
    ),
    35.0, 95.0, 'new', NOW() - INTERVAL 10 MINUTE
);