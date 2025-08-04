-- Seeder: Threat Intelligence Data
-- Version: 003
-- Created: 2023-12-15

-- Known malicious IPs
INSERT INTO threat_intelligence (
    ioc_id, tenant_id, ioc_type, ioc_value, threat_type, confidence_score, severity,
    source_feed, threat_actor, campaign_name, mitre_attack_techniques, tags,
    first_seen, last_seen, is_active, created_at
) VALUES 
(
    'ip_198.51.100.42', 1, 'ip', '198.51.100.42', 'apt', 95.5, 'critical',
    'FireEye_iSIGHT', 'APT28', 'Operation GhostWriter',
    JSON_ARRAY('T1071.001', 'T1573.002', 'T1041'),
    JSON_ARRAY('c2', 'espionage', 'government_targeting'),
    NOW() - INTERVAL 7 DAY, NOW() - INTERVAL 2 HOUR, TRUE, NOW()
),
(
    'ip_203.0.113.99', 1, 'ip', '203.0.113.99', 'botnet', 87.3, 'high',
    'AlienVault_OTX', 'Unknown', 'Conficker Botnet',
    JSON_ARRAY('T1071.001', 'T1105', 'T1083'),
    JSON_ARRAY('botnet', 'malware_distribution', 'ddos'),
    NOW() - INTERVAL 15 DAY, NOW() - INTERVAL 6 HOUR, TRUE, NOW()
),
(
    'ip_192.0.2.150', 1, 'ip', '192.0.2.150', 'phishing', 72.1, 'medium',
    'PhishTank', NULL, 'Business Email Compromise',
    JSON_ARRAY('T1566.001', 'T1204.002'),
    JSON_ARRAY('phishing', 'credential_harvesting'),
    NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 8 HOUR, TRUE, NOW()
);

-- Malicious domains
INSERT INTO threat_intelligence (
    ioc_id, tenant_id, ioc_type, ioc_value, threat_type, confidence_score, severity,
    source_feed, threat_actor, campaign_name, mitre_attack_techniques, tags,
    first_seen, last_seen, is_active, created_at
) VALUES 
(
    'domain_malicious-c2.example.com', 1, 'domain', 'malicious-c2.example.com', 'malware', 92.7, 'critical',
    'IBM_X-Force', 'Lazarus Group', 'Operation Dream Job',
    JSON_ARRAY('T1071.001', 'T1573.001', 'T1132.001'),
    JSON_ARRAY('c2', 'data_exfiltration', 'backdoor'),
    NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 1 HOUR, TRUE, NOW()
),
(
    'domain_phishing-bank.example.org', 1, 'domain', 'phishing-bank.example.org', 'phishing', 88.9, 'high',
    'VirusTotal', NULL, 'Banking Trojan Campaign',
    JSON_ARRAY('T1566.002', 'T1539', 'T1056.001'),
    JSON_ARRAY('phishing', 'banking_trojan', 'credential_theft'),
    NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 4 HOUR, TRUE, NOW()
),
(
    'domain_malware-drop.example.net', 1, 'domain', 'malware-drop.example.net', 'malware', 79.4, 'medium',
    'Recorded_Future', 'FIN7', 'Carbanak Campaign',
    JSON_ARRAY('T1105', 'T1027', 'T1059.003'),
    JSON_ARRAY('malware_hosting', 'payload_delivery'),
    NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 12 HOUR, TRUE, NOW()
);

-- Malicious file hashes
INSERT INTO threat_intelligence (
    ioc_id, tenant_id, ioc_type, ioc_value, threat_type, confidence_score, severity,
    source_feed, threat_actor, campaign_name, mitre_attack_techniques, tags, context_data,
    first_seen, last_seen, is_active, created_at
) VALUES 
(
    'hash_a1b2c3d4e5f6789012345678901234567890abcd', 1, 'file_hash', 'a1b2c3d4e5f6789012345678901234567890abcd', 'ransomware', 98.2, 'critical',
    'CrowdStrike', 'REvil', 'Sodinokibi Campaign',
    JSON_ARRAY('T1486', 'T1083', 'T1057', 'T1012'),
    JSON_ARRAY('ransomware', 'encryption', 'data_destruction'),
    JSON_OBJECT(
        'file_name', 'invoice.pdf.exe',
        'file_size_bytes', 2048576,
        'file_type', 'PE32 executable',
        'packer', 'UPX',
        'compilation_timestamp', '2023-12-01T10:30:00Z'
    ),
    NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 30 MINUTE, TRUE, NOW()
),
(
    'hash_f6e5d4c3b2a1098765432109876543210fedcba9', 1, 'file_hash', 'f6e5d4c3b2a1098765432109876543210fedcba9', 'trojan', 85.6, 'high',
    'MISP_Community', 'Emotet', 'Emotet Banking Campaign',
    JSON_ARRAY('T1055', 'T1140', 'T1082', 'T1033'),
    JSON_ARRAY('banking_trojan', 'process_injection', 'info_stealer'),
    JSON_OBJECT(
        'file_name', 'document.docm',
        'file_size_bytes', 512000,
        'file_type', 'Microsoft Office Document',
        'macro_detected', true,
        'vba_functions', JSON_ARRAY('Shell', 'CreateObject', 'Run')
    ),
    NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 2 HOUR, TRUE, NOW()
);

-- Suspicious URLs
INSERT INTO threat_intelligence (
    ioc_id, tenant_id, ioc_type, ioc_value, threat_type, confidence_score, severity,
    source_feed, campaign_name, mitre_attack_techniques, tags,
    first_seen, last_seen, is_active, created_at
) VALUES 
(
    'url_hxxp://malicious-site.example.com/payload.exe', 1, 'url', 'http://malicious-site.example.com/payload.exe', 'malware', 91.3, 'high',
    'URLVoid', 'Drive-by Download Campaign',
    JSON_ARRAY('T1189', 'T1105', 'T1204.002'),
    JSON_ARRAY('drive_by_download', 'exploit_kit', 'malware_distribution'),
    NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 3 HOUR, TRUE, NOW()
),
(
    'url_hxxps://fake-microsoft-login.example.org/oauth', 1, 'url', 'https://fake-microsoft-login.example.org/oauth', 'phishing', 76.8, 'medium',
    'OpenPhish', 'Office365 Credential Harvesting',
    JSON_ARRAY('T1566.002', 'T1078.004', 'T1539'),
    JSON_ARRAY('credential_harvesting', 'oauth_abuse', 'cloud_targeting'),
    NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 6 HOUR, TRUE, NOW()
);

-- Suspicious email addresses
INSERT INTO threat_intelligence (
    ioc_id, tenant_id, ioc_type, ioc_value, threat_type, confidence_score, severity,
    source_feed, campaign_name, mitre_attack_techniques, tags,
    first_seen, last_seen, is_active, created_at
) VALUES 
(
    'email_noreply@fake-bank.example.com', 1, 'email', 'noreply@fake-bank.example.com', 'phishing', 83.4, 'medium',
    'Anti-Phishing_Working_Group', 'Banking Phishing Campaign',
    JSON_ARRAY('T1566.001', 'T1598.002'),
    JSON_ARRAY('phishing', 'social_engineering', 'financial_fraud'),
    NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 5 HOUR, TRUE, NOW()
),
(
    'email_support@malicious-service.example.net', 1, 'email', 'support@malicious-service.example.net', 'scam', 67.9, 'low',
    'SpamAssassin', 'Tech Support Scam',
    JSON_ARRAY('T1566.001', 'T1204.002'),
    JSON_ARRAY('tech_support_scam', 'social_engineering'),
    NOW() - INTERVAL 14 DAY, NOW() - INTERVAL 1 DAY, TRUE, NOW()
);