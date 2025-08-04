-- Seeder: Create Admin User
-- Version: 001
-- Created: 2023-12-15

-- Insert default admin user
INSERT INTO users (
    tenant_id,
    name,
    email,
    password,
    role,
    permissions,
    email_verified_at,
    is_active,
    must_change_password,
    created_at
) VALUES (
    1,
    'System Administrator',
    'admin@erp-sistema.local',
    '$2y$12$LQv3c1yqBNFcXDJjKzfzNOdPrAWPz7TsRKNc8LNWQi8N8YJlVwUWu', -- password: admin123!@#
    'admin',
    JSON_OBJECT(
        'security', JSON_ARRAY('*'),
        'users', JSON_ARRAY('*'),
        'system', JSON_ARRAY('*'),
        'audit', JSON_ARRAY('*'),
        'performance', JSON_ARRAY('*')
    ),
    NOW(),
    TRUE,
    TRUE, -- Force password change on first login
    NOW()
);

-- Insert security analyst user
INSERT INTO users (
    tenant_id,
    name,
    email,
    password,
    role,
    permissions,
    email_verified_at,
    is_active,
    created_at
) VALUES (
    1,
    'Security Analyst',
    'security@erp-sistema.local',
    '$2y$12$LQv3c1yqBNFcXDJjKzfzNOdPrAWPz7TsRKNc8LNWQi8N8YJlVwUWu', -- password: security123!@#
    'manager',
    JSON_OBJECT(
        'security', JSON_ARRAY('read', 'analyze', 'investigate', 'respond'),
        'incidents', JSON_ARRAY('*'),
        'threats', JSON_ARRAY('*'),
        'audit', JSON_ARRAY('read', 'search')
    ),
    NOW(),
    TRUE,
    NOW()
);

-- Insert SOC operator user
INSERT INTO users (
    tenant_id,
    name,
    email,
    password,
    role,
    permissions,
    email_verified_at,
    is_active,
    created_at
) VALUES (
    1,
    'SOC Operator',
    'soc@erp-sistema.local',
    '$2y$12$LQv3c1yqBNFcXDJjKzfzNOdPrAWPz7TsRKNc8LNWQi8N8YJlVwUWu', -- password: soc123!@#
    'operator',
    JSON_OBJECT(
        'security', JSON_ARRAY('read', 'monitor'),
        'incidents', JSON_ARRAY('read', 'create', 'update'),
        'dashboard', JSON_ARRAY('read'),
        'alerts', JSON_ARRAY('read', 'acknowledge')
    ),
    NOW(),
    TRUE,
    NOW()
);

-- Insert demo viewer user
INSERT INTO users (
    tenant_id,
    name,
    email,
    password,
    role,
    permissions,
    email_verified_at,
    is_active,
    created_at
) VALUES (
    1,
    'Demo Viewer',
    'demo@erp-sistema.local',
    '$2y$12$LQv3c1yqBNFcXDJjKzfzNOdPrAWPz7TsRKNc8LNWQi8N8YJlVwUWu', -- password: demo123!@#
    'viewer',
    JSON_OBJECT(
        'dashboard', JSON_ARRAY('read'),
        'reports', JSON_ARRAY('read')
    ),
    NOW(),
    TRUE,
    NOW()
);