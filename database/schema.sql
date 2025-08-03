-- ERP Sistema - Schema Completo do Banco de Dados
-- Versão: 1.0
-- Data: 2025-08-03

-- =====================================================
-- 1. ESTRUTURA DE EMPRESAS E USUÁRIOS
-- =====================================================

-- Empresas (Multi-tenancy)
CREATE TABLE companies (
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
    
    INDEX idx_companies_code (code),
    INDEX idx_companies_active (active)
);

-- Usuários
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(255),
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    two_factor_secret VARCHAR(255),
    last_login_at TIMESTAMP NULL,
    last_login_ip VARCHAR(45),
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY unique_email_company (email, company_id),
    INDEX idx_users_company (company_id),
    INDEX idx_users_email (email),
    INDEX idx_users_active (active)
);

-- Perfis/Roles
CREATE TABLE roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    permissions JSON,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    INDEX idx_roles_company (company_id)
);

-- Usuários x Perfis
CREATE TABLE user_roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_role (user_id, role_id)
);

-- =====================================================
-- 2. MÓDULO CRM - CLIENTES
-- =====================================================

-- Clientes
CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    type ENUM('individual', 'company') DEFAULT 'individual',
    name VARCHAR(255) NOT NULL,
    document VARCHAR(20),
    email VARCHAR(255),
    phone VARCHAR(20),
    whatsapp VARCHAR(20),
    birth_date DATE,
    gender ENUM('M', 'F', 'Other'),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(2),
    zip_code VARCHAR(10),
    credit_limit DECIMAL(15,2) DEFAULT 0,
    credit_used DECIMAL(15,2) DEFAULT 0,
    notes TEXT,
    tags JSON,
    status ENUM('active', 'inactive', 'blocked') DEFAULT 'active',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_clients_company (company_id),
    INDEX idx_clients_document (document),
    INDEX idx_clients_email (email),
    INDEX idx_clients_status (status),
    FULLTEXT idx_clients_search (name, email, document)
);

-- Contatos dos clientes
CREATE TABLE client_contacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(20),
    position VARCHAR(100),
    is_primary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_contacts_client (client_id)
);

-- Endereços dos clientes
CREATE TABLE client_addresses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    type ENUM('billing', 'shipping', 'other') DEFAULT 'billing',
    address TEXT NOT NULL,
    city VARCHAR(100),
    state VARCHAR(2),
    zip_code VARCHAR(10),
    country VARCHAR(2) DEFAULT 'BR',
    is_primary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_addresses_client (client_id)
);

-- =====================================================
-- 3. MÓDULO ESTOQUE - PRODUTOS
-- =====================================================

-- Categorias de produtos
CREATE TABLE product_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    parent_id INT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    image VARCHAR(255),
    active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (parent_id) REFERENCES product_categories(id),
    INDEX idx_categories_company (company_id),
    INDEX idx_categories_parent (parent_id)
);

-- Fornecedores
CREATE TABLE suppliers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    document VARCHAR(20),
    email VARCHAR(255),
    phone VARCHAR(20),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(2),
    zip_code VARCHAR(10),
    contact_person VARCHAR(255),
    payment_terms TEXT,
    notes TEXT,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    INDEX idx_suppliers_company (company_id),
    INDEX idx_suppliers_document (document)
);

-- Produtos
CREATE TABLE products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    category_id INT,
    supplier_id INT,
    sku VARCHAR(100) NOT NULL,
    barcode VARCHAR(100),
    name VARCHAR(255) NOT NULL,
    description TEXT,
    short_description TEXT,
    cost_price DECIMAL(15,2) DEFAULT 0,
    sale_price DECIMAL(15,2) DEFAULT 0,
    weight DECIMAL(8,3),
    dimensions VARCHAR(50),
    unit VARCHAR(20) DEFAULT 'UN',
    min_stock INT DEFAULT 0,
    max_stock INT DEFAULT 0,
    current_stock INT DEFAULT 0,
    reserved_stock INT DEFAULT 0,
    location VARCHAR(100),
    images JSON,
    attributes JSON,
    tax_info JSON,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (category_id) REFERENCES product_categories(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    UNIQUE KEY unique_sku_company (sku, company_id),
    INDEX idx_products_company (company_id),
    INDEX idx_products_category (category_id),
    INDEX idx_products_barcode (barcode),
    INDEX idx_products_stock (min_stock, current_stock),
    FULLTEXT idx_products_search (name, description, sku)
);

-- Movimentações de estoque
CREATE TABLE stock_movements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    product_id INT NOT NULL,
    type ENUM('in', 'out', 'adjustment', 'transfer') NOT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(15,2),
    total_cost DECIMAL(15,2),
    reference_type VARCHAR(50),
    reference_id INT,
    reason VARCHAR(255),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_movements_company (company_id),
    INDEX idx_movements_product (product_id),
    INDEX idx_movements_date (created_at),
    INDEX idx_movements_reference (reference_type, reference_id)
);

-- =====================================================
-- 4. MÓDULO PDV - VENDAS
-- =====================================================

-- Vendas
CREATE TABLE sales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    client_id INT,
    user_id INT NOT NULL,
    type ENUM('sale', 'quote', 'order') DEFAULT 'sale',
    number VARCHAR(50) NOT NULL,
    date DATE NOT NULL,
    due_date DATE,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    discount_percent DECIMAL(5,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    shipping_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(15,2) DEFAULT 0,
    status ENUM('draft', 'pending', 'confirmed', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    payment_status ENUM('pending', 'partial', 'paid', 'overdue') DEFAULT 'pending',
    payment_method VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_number_company (number, company_id),
    INDEX idx_sales_company (company_id),
    INDEX idx_sales_client (client_id),
    INDEX idx_sales_user (user_id),
    INDEX idx_sales_date (date),
    INDEX idx_sales_status (status)
);

-- Itens da venda
CREATE TABLE sale_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    total_price DECIMAL(15,2) NOT NULL,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_sale_items_sale (sale_id),
    INDEX idx_sale_items_product (product_id)
);

-- =====================================================
-- 5. MÓDULO FINANCEIRO
-- =====================================================

-- Contas bancárias
CREATE TABLE bank_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    bank_name VARCHAR(255),
    account_number VARCHAR(50),
    agency VARCHAR(20),
    account_type ENUM('checking', 'savings', 'cash') DEFAULT 'checking',
    balance DECIMAL(15,2) DEFAULT 0,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    INDEX idx_accounts_company (company_id)
);

-- Categorias financeiras
CREATE TABLE financial_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    parent_id INT NULL,
    name VARCHAR(255) NOT NULL,
    type ENUM('income', 'expense') NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (parent_id) REFERENCES financial_categories(id),
    INDEX idx_financial_categories_company (company_id),
    INDEX idx_financial_categories_type (type)
);

-- Contas a receber
CREATE TABLE accounts_receivable (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    client_id INT,
    sale_id INT,
    category_id INT,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    paid_amount DECIMAL(15,2) DEFAULT 0,
    due_date DATE NOT NULL,
    paid_date DATE NULL,
    payment_method VARCHAR(50),
    bank_account_id INT,
    installment_number INT DEFAULT 1,
    total_installments INT DEFAULT 1,
    interest_rate DECIMAL(5,2) DEFAULT 0,
    fine_rate DECIMAL(5,2) DEFAULT 0,
    discount_rate DECIMAL(5,2) DEFAULT 0,
    status ENUM('pending', 'partial', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (sale_id) REFERENCES sales(id),
    FOREIGN KEY (category_id) REFERENCES financial_categories(id),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
    INDEX idx_receivable_company (company_id),
    INDEX idx_receivable_client (client_id),
    INDEX idx_receivable_due_date (due_date),
    INDEX idx_receivable_status (status)
);

-- Contas a pagar
CREATE TABLE accounts_payable (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    supplier_id INT,
    category_id INT,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    paid_amount DECIMAL(15,2) DEFAULT 0,
    due_date DATE NOT NULL,
    paid_date DATE NULL,
    payment_method VARCHAR(50),
    bank_account_id INT,
    installment_number INT DEFAULT 1,
    total_installments INT DEFAULT 1,
    interest_rate DECIMAL(5,2) DEFAULT 0,
    fine_rate DECIMAL(5,2) DEFAULT 0,
    discount_rate DECIMAL(5,2) DEFAULT 0,
    status ENUM('pending', 'partial', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (category_id) REFERENCES financial_categories(id),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
    INDEX idx_payable_company (company_id),
    INDEX idx_payable_supplier (supplier_id),
    INDEX idx_payable_due_date (due_date),
    INDEX idx_payable_status (status)
);

-- Movimentações financeiras
CREATE TABLE financial_movements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    bank_account_id INT NOT NULL,
    type ENUM('income', 'expense', 'transfer') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    category_id INT,
    date DATE NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
    FOREIGN KEY (category_id) REFERENCES financial_categories(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_movements_company (company_id),
    INDEX idx_movements_account (bank_account_id),
    INDEX idx_movements_date (date),
    INDEX idx_movements_reference (reference_type, reference_id)
);

-- =====================================================
-- 6. MÓDULO MARKETING
-- =====================================================

-- Campanhas de marketing
CREATE TABLE marketing_campaigns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    type ENUM('email', 'sms', 'whatsapp', 'social', 'mixed') NOT NULL,
    status ENUM('draft', 'scheduled', 'running', 'paused', 'completed', 'cancelled') DEFAULT 'draft',
    target_audience JSON,
    budget DECIMAL(15,2),
    start_date DATETIME,
    end_date DATETIME,
    template_content TEXT,
    settings JSON,
    metrics JSON,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_campaigns_company (company_id),
    INDEX idx_campaigns_status (status),
    INDEX idx_campaigns_date (start_date, end_date)
);

-- Leads
CREATE TABLE leads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    campaign_id INT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(20),
    source VARCHAR(100),
    status ENUM('new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost') DEFAULT 'new',
    score INT DEFAULT 0,
    estimated_value DECIMAL(15,2),
    probability DECIMAL(5,2),
    assigned_to INT,
    notes TEXT,
    custom_fields JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    INDEX idx_leads_company (company_id),
    INDEX idx_leads_campaign (campaign_id),
    INDEX idx_leads_status (status),
    INDEX idx_leads_assigned (assigned_to)
);

-- =====================================================
-- 7. SISTEMA E LOGS
-- =====================================================

-- Logs do sistema
CREATE TABLE logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    level VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    context JSON,
    user_id INT,
    company_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    request_id VARCHAR(50),
    memory_usage BIGINT,
    execution_time DECIMAL(10,4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    INDEX idx_logs_level (level),
    INDEX idx_logs_user (user_id),
    INDEX idx_logs_company (company_id),
    INDEX idx_logs_date (created_at),
    INDEX idx_logs_request (request_id)
);

-- Logs de segurança
CREATE TABLE security_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event VARCHAR(100) NOT NULL,
    data JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_security_event (event),
    INDEX idx_security_ip (ip_address),
    INDEX idx_security_date (created_at)
);

-- Fila de eventos
CREATE TABLE event_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event VARCHAR(255) NOT NULL,
    data JSON,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error TEXT,
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    
    INDEX idx_queue_status (status),
    INDEX idx_queue_scheduled (scheduled_at),
    INDEX idx_queue_event (event)
);

-- Configurações do sistema
CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT,
    module VARCHAR(100) NOT NULL,
    key_name VARCHAR(255) NOT NULL,
    value JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY unique_setting (company_id, module, key_name),
    INDEX idx_settings_company (company_id),
    INDEX idx_settings_module (module)
);

-- =====================================================
-- 8. DADOS INICIAIS
-- =====================================================

-- Empresa padrão
INSERT INTO companies (name, code, document, email, active) VALUES 
('Empresa Demo', 'DEMO', '12345678000100', 'contato@demo.com', TRUE);

-- Usuário administrador padrão
INSERT INTO users (company_id, name, email, password, active) VALUES 
(1, 'Administrador', 'admin@demo.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', TRUE);

-- Perfil administrador
INSERT INTO roles (company_id, name, description, permissions) VALUES 
(1, 'Administrador', 'Acesso total ao sistema', '{"*": ["*"]}');

-- Associa usuário ao perfil
INSERT INTO user_roles (user_id, role_id) VALUES (1, 1);

-- Categorias financeiras padrão
INSERT INTO financial_categories (company_id, name, type) VALUES 
(1, 'Vendas', 'income'),
(1, 'Prestação de Serviços', 'income'),
(1, 'Fornecedores', 'expense'),
(1, 'Salários', 'expense'),
(1, 'Aluguel', 'expense'),
(1, 'Utilities', 'expense');

-- Conta bancária padrão
INSERT INTO bank_accounts (company_id, name, account_type, balance) VALUES 
(1, 'Caixa Geral', 'cash', 0);

-- Configurações padrão
INSERT INTO system_settings (company_id, module, key_name, value) VALUES 
(1, 'general', 'timezone', '"America/Sao_Paulo"'),
(1, 'general', 'currency', '"BRL"'),
(1, 'sales', 'auto_number', 'true'),
(1, 'pdv', 'print_receipt', 'true'),
(1, 'stock', 'auto_update', 'true');
