-- Migration: Padronização para tenant_id
-- Converte todas as referências de company_id para tenant_id
-- Data: 2025-08-04

-- =====================================================
-- 1. RENOMEAR TABELA COMPANIES PARA TENANTS
-- =====================================================

-- Criar nova tabela tenants baseada em companies
CREATE TABLE tenants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    document VARCHAR(20) UNIQUE,
    email VARCHAR(255),
    phone VARCHAR(20),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(2),
    zip_code VARCHAR(10),
    logo VARCHAR(255),
    settings JSON,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_tenants_code (code),
    INDEX idx_tenants_active (active)
);

-- Migrar dados de companies para tenants
INSERT INTO tenants SELECT * FROM companies;

-- =====================================================
-- 2. ATUALIZAR TABELAS PARA USAR TENANT_ID
-- =====================================================

-- Usuários
ALTER TABLE users ADD COLUMN tenant_id INT NULL AFTER id;
UPDATE users SET tenant_id = company_id;
ALTER TABLE users MODIFY COLUMN tenant_id INT NOT NULL;
ALTER TABLE users ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE users ADD INDEX idx_users_tenant (tenant_id);
ALTER TABLE users DROP FOREIGN KEY users_ibfk_1;
ALTER TABLE users DROP INDEX idx_users_company;
ALTER TABLE users DROP COLUMN company_id;

-- Atualizar unique constraint
ALTER TABLE users DROP INDEX unique_email_company;
ALTER TABLE users ADD UNIQUE KEY unique_email_tenant (email, tenant_id);

-- Roles
ALTER TABLE roles ADD COLUMN tenant_id INT NULL AFTER id;
UPDATE roles SET tenant_id = company_id;
ALTER TABLE roles MODIFY COLUMN tenant_id INT NOT NULL;
ALTER TABLE roles ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE roles ADD INDEX idx_roles_tenant (tenant_id);
ALTER TABLE roles DROP FOREIGN KEY roles_ibfk_1;
ALTER TABLE roles DROP INDEX idx_roles_company;
ALTER TABLE roles DROP COLUMN company_id;

-- Clientes (já usa tenant_id mas atualiza referência)
-- Apenas atualizar foreign key se existir
ALTER TABLE clientes DROP FOREIGN KEY IF EXISTS clientes_ibfk_1;
ALTER TABLE clientes ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);

-- Categorias
ALTER TABLE categories ADD COLUMN tenant_id INT NULL AFTER id;
UPDATE categories SET tenant_id = company_id;
ALTER TABLE categories MODIFY COLUMN tenant_id INT NOT NULL;
ALTER TABLE categories ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE categories ADD INDEX idx_categories_tenant (tenant_id);
ALTER TABLE categories DROP FOREIGN KEY categories_ibfk_1;
ALTER TABLE categories DROP INDEX idx_categories_company;
ALTER TABLE categories DROP COLUMN company_id;

-- Fornecedores
ALTER TABLE suppliers ADD COLUMN tenant_id INT NULL AFTER id;
UPDATE suppliers SET tenant_id = company_id;
ALTER TABLE suppliers MODIFY COLUMN tenant_id INT NOT NULL;
ALTER TABLE suppliers ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE suppliers ADD INDEX idx_suppliers_tenant (tenant_id);
ALTER TABLE suppliers DROP FOREIGN KEY suppliers_ibfk_1;
ALTER TABLE suppliers DROP INDEX idx_suppliers_company;
ALTER TABLE suppliers DROP COLUMN company_id;

-- Produtos (já usa tenant_id mas atualiza referência)
ALTER TABLE products DROP FOREIGN KEY IF EXISTS products_ibfk_1;
ALTER TABLE products DROP FOREIGN KEY IF EXISTS products_ibfk_2;
ALTER TABLE products ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
-- Atualizar unique constraint
ALTER TABLE products DROP INDEX unique_sku_company;
ALTER TABLE products ADD UNIQUE KEY unique_sku_tenant (sku, tenant_id);

-- Movimentações de estoque (já usa tenant_id)
ALTER TABLE stock_movements DROP FOREIGN KEY IF EXISTS stock_movements_ibfk_1;
ALTER TABLE stock_movements ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);

-- Vendas (já usa tenant_id)
ALTER TABLE sales DROP FOREIGN KEY IF EXISTS sales_ibfk_1;
ALTER TABLE sales ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
-- Atualizar unique constraint
ALTER TABLE sales DROP INDEX unique_number_company;
ALTER TABLE sales ADD UNIQUE KEY unique_number_tenant (number, tenant_id);

-- Contas bancárias
ALTER TABLE bank_accounts ADD COLUMN tenant_id INT NULL AFTER id;
UPDATE bank_accounts SET tenant_id = company_id;
ALTER TABLE bank_accounts MODIFY COLUMN tenant_id INT NOT NULL;
ALTER TABLE bank_accounts ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE bank_accounts ADD INDEX idx_accounts_tenant (tenant_id);
ALTER TABLE bank_accounts DROP FOREIGN KEY bank_accounts_ibfk_1;
ALTER TABLE bank_accounts DROP INDEX idx_accounts_company;
ALTER TABLE bank_accounts DROP COLUMN company_id;

-- Categorias financeiras
ALTER TABLE financial_categories ADD COLUMN tenant_id INT NULL AFTER id;
UPDATE financial_categories SET tenant_id = company_id;
ALTER TABLE financial_categories MODIFY COLUMN tenant_id INT NOT NULL;
ALTER TABLE financial_categories ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE financial_categories ADD INDEX idx_financial_categories_tenant (tenant_id);
ALTER TABLE financial_categories DROP FOREIGN KEY financial_categories_ibfk_1;
ALTER TABLE financial_categories DROP INDEX idx_financial_categories_company;
ALTER TABLE financial_categories DROP COLUMN company_id;

-- Contas a receber (já usa tenant_id)
ALTER TABLE accounts_receivable DROP FOREIGN KEY IF EXISTS accounts_receivable_ibfk_1;
ALTER TABLE accounts_receivable ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);

-- Contas a pagar (já usa tenant_id)
ALTER TABLE accounts_payable DROP FOREIGN KEY IF EXISTS accounts_payable_ibfk_1;
ALTER TABLE accounts_payable ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);

-- Movimentações financeiras (já usa tenant_id)
ALTER TABLE financial_movements DROP FOREIGN KEY IF EXISTS financial_movements_ibfk_1;
ALTER TABLE financial_movements ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);

-- Campanhas de marketing
ALTER TABLE marketing_campaigns ADD COLUMN tenant_id INT NULL AFTER id;
UPDATE marketing_campaigns SET tenant_id = company_id;
ALTER TABLE marketing_campaigns MODIFY COLUMN tenant_id INT NOT NULL;
ALTER TABLE marketing_campaigns ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE marketing_campaigns ADD INDEX idx_campaigns_tenant (tenant_id);
ALTER TABLE marketing_campaigns DROP FOREIGN KEY marketing_campaigns_ibfk_1;
ALTER TABLE marketing_campaigns DROP INDEX idx_campaigns_company;
ALTER TABLE marketing_campaigns DROP COLUMN company_id;

-- Leads (já usa tenant_id)
ALTER TABLE leads DROP FOREIGN KEY IF EXISTS leads_ibfk_1;
ALTER TABLE leads ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);

-- Logs de auditoria (já usa tenant_id)
ALTER TABLE audit_logs DROP FOREIGN KEY IF EXISTS audit_logs_ibfk_1;
ALTER TABLE audit_logs ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);

-- Configurações do sistema
ALTER TABLE system_settings DROP INDEX unique_setting;
ALTER TABLE system_settings ADD COLUMN tenant_id INT NULL AFTER id;
UPDATE system_settings SET tenant_id = company_id WHERE company_id IS NOT NULL;
ALTER TABLE system_settings ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE system_settings ADD UNIQUE KEY unique_setting_tenant (tenant_id, module, key_name);
ALTER TABLE system_settings ADD INDEX idx_settings_tenant (tenant_id);
ALTER TABLE system_settings DROP FOREIGN KEY IF EXISTS system_settings_ibfk_1;
ALTER TABLE system_settings DROP INDEX idx_settings_company;
ALTER TABLE system_settings DROP COLUMN company_id;

-- =====================================================
-- 3. CRIAR TABELAS AUXILIARES PARA MULTI-TENANCY
-- =====================================================

-- Tabela para mapear usuários a múltiplos tenants (para casos especiais)
CREATE TABLE user_tenants (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
    role VARCHAR(50) DEFAULT 'member',
    permissions JSON,
    is_default BOOLEAN DEFAULT FALSE,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_tenant (user_id, tenant_id),
    INDEX idx_user_tenants_user (user_id),
    INDEX idx_user_tenants_tenant (tenant_id)
);

-- Popular tabela user_tenants com dados existentes
INSERT INTO user_tenants (user_id, tenant_id, role, is_default, active)
SELECT id, tenant_id, 'owner', TRUE, active FROM users;

-- Tabela para log de tentativas de acesso cruzado entre tenants
CREATE TABLE tenant_access_logs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    current_tenant_id INT,
    requested_tenant_id INT,
    resource VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent TEXT,
    access_granted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (current_tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (requested_tenant_id) REFERENCES tenants(id),
    INDEX idx_access_logs_user (user_id),
    INDEX idx_access_logs_tenant (current_tenant_id),
    INDEX idx_access_logs_created (created_at)
);

-- =====================================================
-- 4. ATUALIZAR VIEWS EXISTENTES
-- =====================================================

-- Recriar view de vendas se existir
DROP VIEW IF EXISTS vw_vendas_completas;
CREATE VIEW vw_vendas_completas AS
SELECT 
    v.*,
    c.nome as cliente_nome,
    c.email as cliente_email,
    u.name as vendedor_nome,
    t.name as tenant_nome
FROM vendas v
LEFT JOIN clientes c ON v.cliente_id = c.id AND v.tenant_id = c.tenant_id
LEFT JOIN users u ON v.vendedor_id = u.id AND v.tenant_id = u.tenant_id
LEFT JOIN tenants t ON v.tenant_id = t.id;

-- =====================================================
-- 5. ATUALIZAR SEEDS PARA USAR TENANT_ID
-- =====================================================

-- Atualizar usuário admin para usar tenant_id
UPDATE users SET tenant_id = 1 WHERE email = 'admin@erp.com' AND tenant_id IS NULL;

-- =====================================================
-- 6. TRIGGERS PARA VALIDAÇÃO DE TENANT
-- =====================================================

-- Trigger para validar tenant_id em inserções
DELIMITER //

CREATE TRIGGER validate_tenant_insert_users
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF NEW.tenant_id IS NULL THEN
        SET NEW.tenant_id = 1; -- Tenant padrão
    END IF;
    
    -- Validar se tenant existe e está ativo
    IF NOT EXISTS (SELECT 1 FROM tenants WHERE id = NEW.tenant_id AND active = TRUE) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or inactive tenant_id';
    END IF;
END//

-- Trigger similar para outras tabelas principais
CREATE TRIGGER validate_tenant_insert_clientes
BEFORE INSERT ON clientes
FOR EACH ROW
BEGIN
    IF NEW.tenant_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tenant_id is required';
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM tenants WHERE id = NEW.tenant_id AND active = TRUE) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or inactive tenant_id';
    END IF;
END//

CREATE TRIGGER validate_tenant_insert_produtos
BEFORE INSERT ON produtos
FOR EACH ROW
BEGIN
    IF NEW.tenant_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tenant_id is required';
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM tenants WHERE id = NEW.tenant_id AND active = TRUE) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or inactive tenant_id';
    END IF;
END//

DELIMITER ;

-- =====================================================
-- 7. MANTER TABELA COMPANIES COMO ALIAS (OPCIONAL)
-- =====================================================

-- Criar view para manter compatibilidade com código legado
CREATE VIEW companies AS
SELECT 
    id,
    name,
    code,
    document,
    email,
    phone,
    address,
    city,
    state,
    zip_code,
    logo,
    settings,
    active,
    created_at,
    updated_at
FROM tenants;

-- =====================================================
-- 8. ÍNDICES OTIMIZADOS PARA MULTI-TENANT
-- =====================================================

-- Índices compostos para queries multi-tenant mais eficientes
CREATE INDEX idx_users_tenant_email ON users(tenant_id, email);
CREATE INDEX idx_users_tenant_active ON users(tenant_id, active);

-- Índices para tabelas que já usam tenant_id
CREATE INDEX idx_vendas_tenant_status ON vendas(tenant_id, status);
CREATE INDEX idx_vendas_tenant_data ON vendas(tenant_id, data_venda);
CREATE INDEX idx_clientes_tenant_ativo ON clientes(tenant_id, ativo);
CREATE INDEX idx_produtos_tenant_ativo ON produtos(tenant_id, ativo);

-- Comentários para documentação
ALTER TABLE tenants COMMENT = 'Tabela principal de tenants (empresas) para isolamento multi-tenant';
ALTER TABLE user_tenants COMMENT = 'Relacionamento usuário-tenant para casos de acesso múltiplo';
ALTER TABLE tenant_access_logs COMMENT = 'Log de tentativas de acesso cruzado entre tenants para auditoria';