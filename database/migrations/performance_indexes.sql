-- =====================================================
-- ÍNDICES DE PERFORMANCE CRÍTICOS - SISTEMA ERP
-- Implementação imediata para alta performance
-- =====================================================

-- VENDAS - Otimização crítica para dashboard e relatórios
CREATE INDEX IF NOT EXISTS idx_vendas_status_data ON vendas(status, data_venda, tenant_id);
CREATE INDEX IF NOT EXISTS idx_vendas_tenant_created ON vendas(tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_vendas_cliente_tenant ON vendas(cliente_id, tenant_id);
CREATE INDEX IF NOT EXISTS idx_vendas_valor_status ON vendas(valor_total, status, tenant_id);

-- PRODUTOS/ESTOQUE - Crítico para alertas e listagens
CREATE INDEX IF NOT EXISTS idx_produtos_estoque_minimo ON produtos(quantidade_atual, estoque_minimo, tenant_id);
CREATE INDEX IF NOT EXISTS idx_produtos_categoria_status ON produtos(categoria, status, tenant_id);
CREATE INDEX IF NOT EXISTS idx_produtos_sku_tenant ON produtos(sku, tenant_id);
CREATE INDEX IF NOT EXISTS idx_produtos_nome_ativo ON produtos(nome, status, tenant_id);

-- CLIENTES - Otimização para CRM
CREATE INDEX IF NOT EXISTS idx_clientes_status_tipo ON clientes(status, tipo_pessoa, tenant_id);
CREATE INDEX IF NOT EXISTS idx_clientes_email_tenant ON clientes(email, tenant_id);
CREATE INDEX IF NOT EXISTS idx_clientes_created_tenant ON clientes(created_at, tenant_id);
CREATE INDEX IF NOT EXISTS idx_clientes_nome_status ON clientes(nome, status, tenant_id);

-- TRANSAÇÕES FINANCEIRAS - Crítico para fluxo de caixa
CREATE INDEX IF NOT EXISTS idx_financeiro_vencimento ON transacoes_financeiras(data_vencimento, status, tenant_id);
CREATE INDEX IF NOT EXISTS idx_financeiro_tipo_data ON transacoes_financeiras(tipo, data_transacao, tenant_id);
CREATE INDEX IF NOT EXISTS idx_financeiro_categoria ON transacoes_financeiras(categoria, tenant_id);

-- CONTAS A RECEBER/PAGAR - Performance para dashboards financeiros
CREATE INDEX IF NOT EXISTS idx_contas_receber_vencimento ON contas_receber(data_vencimento, status, tenant_id);
CREATE INDEX IF NOT EXISTS idx_contas_pagar_vencimento ON contas_pagar(data_vencimento, status, tenant_id);

-- MOVIMENTAÇÕES DE ESTOQUE - Histórico otimizado
CREATE INDEX IF NOT EXISTS idx_movimentacoes_data_tipo ON movimentacoes_estoque(created_at, tipo, tenant_id);
CREATE INDEX IF NOT EXISTS idx_movimentacoes_produto ON movimentacoes_estoque(produto_id, tenant_id);

-- VENDAS ITENS - Otimização para relatórios de produtos
CREATE INDEX IF NOT EXISTS idx_vendas_itens_produto ON vendas_itens(produto_id, quantidade);
CREATE INDEX IF NOT EXISTS idx_vendas_itens_venda ON vendas_itens(venda_id, produto_id);

-- USUÁRIOS E AUTENTICAÇÃO - Performance de login
CREATE INDEX IF NOT EXISTS idx_users_email_status ON users(email, status);
CREATE INDEX IF NOT EXISTS idx_users_tenant_role ON users(tenant_id, role);
CREATE INDEX IF NOT EXISTS idx_user_tokens_user ON user_tokens(user_id, expires_at);

-- LOGS E AUDITORIA - Performance para auditoria
CREATE INDEX IF NOT EXISTS idx_auditoria_logs_data ON auditoria_logs(timestamp, tenant_id);
CREATE INDEX IF NOT EXISTS idx_auditoria_logs_usuario ON auditoria_logs(usuario_id, timestamp);
CREATE INDEX IF NOT EXISTS idx_auditoria_logs_nivel ON auditoria_logs(nivel_risco, tenant_id);

-- NOTIFICAÇÕES - Performance para sistema de notificações
CREATE INDEX IF NOT EXISTS idx_notificacoes_usuario_lida ON notificacoes(usuario_id, lida, created_at);
CREATE INDEX IF NOT EXISTS idx_notificacoes_tenant_tipo ON notificacoes(tenant_id, tipo);

-- CACHE ENTRIES - Performance para sistema de cache
CREATE INDEX IF NOT EXISTS idx_cache_entries_key ON cache_entries(cache_key, expires_at);
CREATE INDEX IF NOT EXISTS idx_cache_entries_expires ON cache_entries(expires_at);

-- =====================================================
-- ÍNDICES COMPOSTOS PARA QUERIES COMPLEXAS
-- =====================================================

-- Dashboard métricas - Query única otimizada
CREATE INDEX IF NOT EXISTS idx_dashboard_metrics ON vendas(tenant_id, status, data_venda, valor_total);

-- Relatórios de vendas por período
CREATE INDEX IF NOT EXISTS idx_relatorios_vendas ON vendas(tenant_id, data_venda, status, valor_total, cliente_id);

-- Top produtos mais vendidos
CREATE INDEX IF NOT EXISTS idx_top_produtos ON vendas_itens(produto_id, quantidade, valor_total);

-- Análise financeira otimizada
CREATE INDEX IF NOT EXISTS idx_analise_financeira ON transacoes_financeiras(tenant_id, data_transacao, tipo, valor, categoria);

-- =====================================================
-- VIEWS MATERIALIZADAS PARA PERFORMANCE SUPREMA
-- =====================================================

-- Dashboard Metrics View - Atualizada a cada 5 minutos
CREATE OR REPLACE VIEW v_dashboard_metrics AS
SELECT 
    tenant_id,
    DATE(data_venda) as data,
    COUNT(*) as total_vendas,
    SUM(valor_total) as receita_total,
    AVG(valor_total) as ticket_medio,
    COUNT(DISTINCT cliente_id) as clientes_unicos
FROM vendas 
WHERE status = 'concluida'
  AND data_venda >= DATE_SUB(NOW(), INTERVAL 90 DAY)
GROUP BY tenant_id, DATE(data_venda);

-- Top Products View - Atualizada diariamente
CREATE OR REPLACE VIEW v_top_produtos AS
SELECT 
    p.tenant_id,
    p.id as produto_id,
    p.nome,
    p.sku,
    SUM(vi.quantidade) as total_vendido,
    SUM(vi.valor_total) as receita_total,
    COUNT(DISTINCT v.id) as numero_vendas
FROM produtos p
JOIN vendas_itens vi ON p.id = vi.produto_id
JOIN vendas v ON vi.venda_id = v.id
WHERE v.status = 'concluida'
  AND v.data_venda >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY p.tenant_id, p.id, p.nome, p.sku
ORDER BY total_vendido DESC;

-- Alertas de Estoque View - Tempo real
CREATE OR REPLACE VIEW v_alertas_estoque AS
SELECT 
    tenant_id,
    id,
    nome,
    sku,
    quantidade_atual,
    estoque_minimo,
    (quantidade_atual / estoque_minimo) as ratio_estoque,
    CASE 
        WHEN quantidade_atual <= 0 THEN 'critico'
        WHEN quantidade_atual <= estoque_minimo THEN 'baixo'
        WHEN quantidade_atual <= estoque_minimo * 1.5 THEN 'atencao'
        ELSE 'normal'
    END as nivel_alerta
FROM produtos 
WHERE status = 'ativo'
  AND quantidade_atual <= estoque_minimo * 2;

-- =====================================================
-- ÍNDICES DE TEXTO COMPLETO PARA BUSCA AVANÇADA
-- =====================================================

-- Busca de produtos
CREATE FULLTEXT INDEX IF NOT EXISTS idx_produtos_fulltext ON produtos(nome, descricao, sku);

-- Busca de clientes  
CREATE FULLTEXT INDEX IF NOT EXISTS idx_clientes_fulltext ON clientes(nome, email, documento);

-- =====================================================
-- ESTATÍSTICAS E OTIMIZAÇÕES AUTOMÁTICAS
-- =====================================================

-- Atualizar estatísticas das tabelas principais
ANALYZE TABLE vendas, produtos, clientes, transacoes_financeiras;

-- Otimizar tabelas após criação dos índices
OPTIMIZE TABLE vendas, produtos, clientes, transacoes_financeiras;

-- =====================================================
-- CONFIGURAÇÕES DE PERFORMANCE DO MYSQL
-- =====================================================

-- Configurações recomendadas para o my.cnf:
/*
[mysqld]
# Performance crítica
innodb_buffer_pool_size = 2G
innodb_buffer_pool_instances = 8
innodb_log_file_size = 512M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Cache de queries
query_cache_type = 1
query_cache_size = 256M
query_cache_limit = 16M

# Conexões
max_connections = 1000
max_user_connections = 950
thread_cache_size = 50

# Timeouts otimizados
wait_timeout = 600
interactive_timeout = 600
connect_timeout = 60

# Temp tables
tmp_table_size = 256M
max_heap_table_size = 256M

# MyISAM (se usar)
key_buffer_size = 256M

# Logs
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
log_queries_not_using_indexes = 1
*/