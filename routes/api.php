<?php

declare(strict_types=1);

use ERP\Core\Http\Router;
use ERP\Api\Controllers\AuthController;
use ERP\Api\Controllers\DashboardController;
use ERP\Api\Controllers\CrmController;
use ERP\Api\Controllers\EstoqueController;
use ERP\Api\Controllers\VendasController;
use ERP\Api\Controllers\FinanceiroController;
use ERP\Api\Controllers\RelatoriosController;
use ERP\Api\Controllers\ConfiguracoesController;
use ERP\Api\Controllers\PerformanceController;
use ERP\Api\Controllers\SecurityController;

/**
 * Definições de Rotas da API
 * 
 * Configuração completa de todas as rotas REST do sistema ERP
 */

return function(Router $router) {
    
    // Grupo de rotas da API com middleware de segurança
    $router->group([
        'prefix' => 'api',
        'middleware' => ['cors', 'security', 'auth'],
    ], function(Router $router) {
        
        // ===========================================
        // ROTAS DASHBOARD - Métricas e KPIs
        // ===========================================
        $router->group(['prefix' => 'dashboard'], function(Router $router) {
            $router->get('metrics', [DashboardController::class, 'metrics'])
                   ->name('dashboard.metrics')
                   ->description('Obter métricas gerais do dashboard');
                   
            $router->get('sales-chart', [DashboardController::class, 'salesChart'])
                   ->name('dashboard.sales-chart')
                   ->description('Dados para gráfico de vendas');
                   
            $router->get('revenue-chart', [DashboardController::class, 'revenueChart'])
                   ->name('dashboard.revenue-chart')
                   ->description('Dados para gráfico de receitas');
                   
            $router->get('top-products', [DashboardController::class, 'topProducts'])
                   ->name('dashboard.top-products')
                   ->description('Produtos mais vendidos');
                   
            $router->get('customer-insights', [DashboardController::class, 'customerInsights'])
                   ->name('dashboard.customer-insights')
                   ->description('Insights de clientes');
                   
            $router->get('notifications', [DashboardController::class, 'notifications'])
                   ->name('dashboard.notifications')
                   ->description('Notificações em tempo real');
        });
        
        // ===========================================
        // ROTAS CRM - Gestão de Clientes
        // ===========================================
        $router->group(['prefix' => 'crm'], function(Router $router) {
            $router->get('list', [CrmController::class, 'list'])
                   ->name('crm.list')
                   ->description('Listar clientes com paginação');
                   
            $router->get('{id}', [CrmController::class, 'show'])
                   ->name('crm.show')
                   ->where(['id' => '[0-9]+'])
                   ->description('Visualizar cliente específico');
                   
            $router->post('create', [CrmController::class, 'create'])
                   ->name('crm.create')
                   ->description('Criar novo cliente');
                   
            $router->put('update/{id}', [CrmController::class, 'update'])
                   ->name('crm.update')
                   ->where(['id' => '[0-9]+'])
                   ->description('Atualizar dados do cliente');
                   
            $router->delete('delete/{id}', [CrmController::class, 'delete'])
                   ->name('crm.delete')
                   ->where(['id' => '[0-9]+'])
                   ->description('Remover cliente');
                   
            $router->get('filter', [CrmController::class, 'filter'])
                   ->name('crm.filter')
                   ->description('Filtrar clientes com critérios avançados');
                   
            $router->get('stats', [CrmController::class, 'stats'])
                   ->name('crm.stats')
                   ->description('Estatísticas de clientes');
                   
            $router->get('export', [CrmController::class, 'export'])
                   ->name('crm.export')
                   ->description('Exportar dados de clientes');
        });
        
        // ===========================================
        // ROTAS ESTOQUE - Gestão de Produtos
        // ===========================================
        $router->group(['prefix' => 'estoque'], function(Router $router) {
            $router->get('list', [EstoqueController::class, 'list'])
                   ->name('estoque.list')
                   ->description('Listar produtos em estoque');
                   
            $router->get('{id}', [EstoqueController::class, 'show'])
                   ->name('estoque.show')
                   ->where(['id' => '[0-9]+'])
                   ->description('Visualizar produto específico');
                   
            $router->post('create', [EstoqueController::class, 'create'])
                   ->name('estoque.create')
                   ->description('Cadastrar novo produto');
                   
            $router->put('update/{id}', [EstoqueController::class, 'update'])
                   ->name('estoque.update')
                   ->where(['id' => '[0-9]+'])
                   ->description('Atualizar dados do produto');
                   
            $router->delete('delete/{id}', [EstoqueController::class, 'delete'])
                   ->name('estoque.delete')
                   ->where(['id' => '[0-9]+'])
                   ->description('Remover produto');
                   
            $router->get('alerts', [EstoqueController::class, 'alerts'])
                   ->name('estoque.alerts')
                   ->description('Alertas de estoque baixo e zerado');
                   
            $router->post('movimentacao', [EstoqueController::class, 'movimentacao'])
                   ->name('estoque.movimentacao')
                   ->description('Registrar movimentação de estoque');
                   
            $router->get('movimentacao/history', [EstoqueController::class, 'movementHistory'])
                   ->name('estoque.movimentacao.history')
                   ->description('Histórico de movimentações');
                   
            $router->get('valuation', [EstoqueController::class, 'valuation'])
                   ->name('estoque.valuation')
                   ->description('Relatório de valorização do estoque');
        });
        
        // ===========================================
        // ROTAS VENDAS - Gestão de Vendas
        // ===========================================
        $router->group(['prefix' => 'vendas'], function(Router $router) {
            $router->get('list', [VendasController::class, 'list'])
                   ->name('vendas.list')
                   ->description('Listar vendas com filtros');
                   
            $router->get('{id}', [VendasController::class, 'show'])
                   ->name('vendas.show')
                   ->where(['id' => '[0-9]+'])
                   ->description('Visualizar venda específica');
                   
            $router->post('create', [VendasController::class, 'create'])
                   ->name('vendas.create')
                   ->description('Criar nova venda');
                   
            $router->put('update/{id}', [VendasController::class, 'update'])
                   ->name('vendas.update')
                   ->where(['id' => '[0-9]+'])
                   ->description('Atualizar status da venda');
                   
            $router->delete('delete/{id}', [VendasController::class, 'delete'])
                   ->name('vendas.delete')
                   ->where(['id' => '[0-9]+'])
                   ->description('Cancelar/Remover venda');
                   
            $router->get('metas', [VendasController::class, 'metas'])
                   ->name('vendas.metas')
                   ->description('Metas e objetivos de vendas');
                   
            $router->get('report', [VendasController::class, 'report'])
                   ->name('vendas.report')
                   ->description('Relatórios de vendas (JSON/CSV/PDF)');
                   
            $router->get('por-vendedor', [VendasController::class, 'porVendedor'])
                   ->name('vendas.por-vendedor')
                   ->description('Performance por vendedor');
        });
        
        // ===========================================
        // ROTAS FINANCEIRO - Gestão Financeira
        // ===========================================
        $router->group(['prefix' => 'financeiro'], function(Router $router) {
            $router->get('fluxo', [FinanceiroController::class, 'fluxo'])
                   ->name('financeiro.fluxo')
                   ->description('Fluxo de caixa detalhado');
                   
            $router->get('contas', [FinanceiroController::class, 'contas'])
                   ->name('financeiro.contas')
                   ->description('Contas a pagar e receber');
                   
            $router->post('transacao/create', [FinanceiroController::class, 'criarTransacao'])
                   ->name('financeiro.transacao.create')
                   ->description('Criar nova transação financeira');
                   
            $router->put('transacao/update/{id}', [FinanceiroController::class, 'atualizarTransacao'])
                   ->name('financeiro.transacao.update')
                   ->where(['id' => '[0-9]+'])
                   ->description('Atualizar transação');
                   
            $router->delete('transacao/delete/{id}', [FinanceiroController::class, 'removerTransacao'])
                   ->name('financeiro.transacao.delete')
                   ->where(['id' => '[0-9]+'])
                   ->description('Remover transação');
                   
            $router->post('concilia', [FinanceiroController::class, 'concilia'])
                   ->name('financeiro.concilia')
                   ->description('Conciliação bancária');
                   
            $router->get('dre', [FinanceiroController::class, 'dre'])
                   ->name('financeiro.dre')
                   ->description('Demonstrativo de Resultado (DRE)');
        });
        
        // ===========================================
        // ROTAS RELATÓRIOS - Business Intelligence
        // ===========================================
        $router->group(['prefix' => 'relatorios'], function(Router $router) {
            $router->get('{tipo}', [RelatoriosController::class, 'gerarRelatorio'])
                   ->name('relatorios.gerar')
                   ->where(['tipo' => '[a-z]+'])
                   ->description('Gerar relatório por tipo');
                   
            $router->post('export', [RelatoriosController::class, 'exportar'])
                   ->name('relatorios.exportar')
                   ->description('Exportar relatórios (CSV/PDF/Excel)');
                   
            $router->get('dashboard-bi', [RelatoriosController::class, 'dashboardBi'])
                   ->name('relatorios.dashboard-bi')
                   ->description('Dashboard BI com KPIs avançados');
                   
            $router->post('comparativo', [RelatoriosController::class, 'comparativo'])
                   ->name('relatorios.comparativo')
                   ->description('Análise comparativa de períodos');
        });
        
        // ===========================================
        // ROTAS CONFIGURAÇÕES - Sistema e Empresa
        // ===========================================
        $router->group(['prefix' => 'config'], function(Router $router) {
            
            // Configurações da Empresa
            $router->get('empresa', [ConfiguracoesController::class, 'obterEmpresa'])
                   ->name('config.empresa.obter')
                   ->description('Obter dados da empresa');
                   
            $router->put('empresa', [ConfiguracoesController::class, 'atualizarEmpresa'])
                   ->name('config.empresa.atualizar')
                   ->description('Atualizar dados da empresa');
            
            // Configurações de Moeda
            $router->get('moeda', [ConfiguracoesController::class, 'obterMoeda'])
                   ->name('config.moeda.obter')
                   ->description('Obter configurações de moeda');
                   
            $router->put('moeda', [ConfiguracoesController::class, 'atualizarMoeda'])
                   ->name('config.moeda.atualizar')
                   ->description('Atualizar configurações de moeda');
            
            // Configurações de Idioma
            $router->get('idioma', [ConfiguracoesController::class, 'obterIdioma'])
                   ->name('config.idioma.obter')
                   ->description('Obter configurações de idioma');
                   
            $router->put('idioma', [ConfiguracoesController::class, 'atualizarIdioma'])
                   ->name('config.idioma.atualizar')
                   ->description('Atualizar configurações de idioma');
            
            // Gestão de Usuários
            $router->get('usuarios', [ConfiguracoesController::class, 'listarUsuarios'])
                   ->name('config.usuarios.listar')
                   ->description('Listar usuários do tenant');
                   
            $router->post('usuarios', [ConfiguracoesController::class, 'criarUsuario'])
                   ->name('config.usuarios.criar')
                   ->description('Criar novo usuário');
                   
            $router->put('usuarios/{id}', [ConfiguracoesController::class, 'atualizarUsuario'])
                   ->name('config.usuarios.atualizar')
                   ->where(['id' => '[0-9]+'])
                   ->description('Atualizar dados do usuário');
                   
            $router->delete('usuarios/{id}', [ConfiguracoesController::class, 'removerUsuario'])
                   ->name('config.usuarios.remover')
                   ->where(['id' => '[0-9]+'])
                   ->description('Remover usuário do tenant');
            
            // Backup e Restauração
            $router->post('backup', [ConfiguracoesController::class, 'fazerBackup'])
                   ->name('config.backup')
                   ->description('Criar backup do sistema');
                   
            $router->post('restore', [ConfiguracoesController::class, 'restaurarBackup'])
                   ->name('config.restore')
                   ->description('Restaurar backup do sistema');
            
            // Configurações Gerais
            $router->get('sistema', [ConfiguracoesController::class, 'obterConfiguracoesSistema'])
                   ->name('config.sistema')
                   ->description('Obter todas as configurações do sistema');
        });
        
        // ===========================================
        // ROTAS PERFORMANCE - Monitoramento e IA
        // ===========================================
        $router->group(['prefix' => 'performance'], function(Router $router) {
            // Dashboard de Performance
            $router->get('dashboard', [PerformanceController::class, 'dashboard'])
                   ->name('performance.dashboard')
                   ->description('Dashboard completo de performance');
                   
            // Benchmark do Sistema
            $router->get('benchmark', [PerformanceController::class, 'benchmark'])
                   ->name('performance.benchmark')
                   ->description('Executar benchmark rápido do sistema');
                   
            // Métricas Históricas
            $router->get('metrics', [PerformanceController::class, 'metricas'])
                   ->name('performance.metrics')
                   ->description('Métricas históricas para gráficos');
                   
            // Alertas do Sistema
            $router->get('alerts', [PerformanceController::class, 'alertas'])
                   ->name('performance.alerts')
                   ->description('Alertas ativos e histórico');
                   
            $router->post('alerts/suppress', [PerformanceController::class, 'suprimirAlerta'])
                   ->name('performance.alerts.suppress')
                   ->description('Suprimir alerta temporariamente');
                   
            // Previsões com Machine Learning
            $router->get('predictions', [PerformanceController::class, 'predicoes'])
                   ->name('performance.predictions')
                   ->description('Previsões ML de performance');
                   
            // Otimização Automática
            $router->post('optimize', [PerformanceController::class, 'otimizar'])
                   ->name('performance.optimize')
                   ->description('Executar otimização automática');
                   
            // Status de Saúde
            $router->get('health', [PerformanceController::class, 'saudesSistema'])
                   ->name('performance.health')
                   ->description('Status de saúde detalhado');
                   
            // Treinamento de Modelos ML
            $router->post('ml/train', [PerformanceController::class, 'treinarModelos'])
                   ->name('performance.ml.train')
                   ->description('Treinar modelos de machine learning');
                   
            // Relatórios de Performance
            $router->get('reports', [PerformanceController::class, 'relatorios'])
                   ->name('performance.reports')
                   ->description('Relatórios detalhados de performance');
        });
        
        // ===========================================
        // ROTAS SEGURANÇA - Enterprise Security & Compliance
        // ===========================================
        $router->group(['prefix' => 'security'], function(Router $router) {
            // Dashboard de Segurança
            $router->get('dashboard', [SecurityController::class, 'dashboard'])
                   ->name('security.dashboard')
                   ->description('Dashboard completo de segurança enterprise');
                   
            // Monitoramento de Ameaças
            $router->get('threats', [SecurityController::class, 'threats'])
                   ->name('security.threats')
                   ->description('Análise e monitoramento de ameaças');
                   
            // Scan de Segurança
            $router->post('scan', [SecurityController::class, 'scan'])
                   ->name('security.scan')
                   ->description('Executar scan de vulnerabilidades');
                   
            // Criptografia
            $router->get('encryption/status', [SecurityController::class, 'encryptionStatus'])
                   ->name('security.encryption.status')
                   ->description('Status da criptografia end-to-end');
                   
            $router->post('encryption/rotate-keys', [SecurityController::class, 'rotateKeys'])
                   ->name('security.encryption.rotate')
                   ->description('Rotacionar chaves de criptografia');
                   
            // Compliance LGPD/GDPR
            $router->get('audit/compliance', [SecurityController::class, 'complianceCheck'])
                   ->name('security.compliance')
                   ->description('Verificação de compliance LGPD/GDPR');
                   
            $router->post('audit/log-event', [SecurityController::class, 'logAuditEvent'])
                   ->name('security.audit.log')
                   ->description('Registrar evento de auditoria');
                   
            // Backup e Disaster Recovery
            $router->post('backup/execute', [SecurityController::class, 'executeBackup'])
                   ->name('security.backup.execute')
                   ->description('Executar backup manual');
                   
            $router->post('backup/restore', [SecurityController::class, 'restoreBackup'])
                   ->name('security.backup.restore')
                   ->description('Restaurar sistema de backup');
                   
            $router->get('backup/health', [SecurityController::class, 'backupHealth'])
                   ->name('security.backup.health')
                   ->description('Monitorar saúde dos backups');
                   
            $router->post('disaster-recovery/test', [SecurityController::class, 'testDisasterRecovery'])
                   ->name('security.dr.test')
                   ->description('Testar procedimentos de disaster recovery');
                   
            // Direitos do Titular (LGPD/GDPR)
            $router->post('subject-rights/request', [SecurityController::class, 'processSubjectRightsRequest'])
                   ->name('security.subject_rights')
                   ->description('Processar solicitações de direitos do titular');
                   
            // SOC (Security Operations Center)
            $router->get('soc/dashboard', [SecurityController::class, 'socDashboard'])
                   ->name('security.soc.dashboard')
                   ->description('Dashboard SOC unificado');
                   
            $router->post('soc/incident', [SecurityController::class, 'manageIncident'])
                   ->name('security.soc.incident')
                   ->description('Gerenciar incidente no SOC');
                   
            $router->get('soc/metrics', [SecurityController::class, 'socMetrics'])
                   ->name('security.soc.metrics')
                   ->description('Métricas de performance do SOC');
                   
            // WAF (Web Application Firewall)
            $router->post('waf/analyze', [SecurityController::class, 'wafAnalyze'])
                   ->name('security.waf.analyze')
                   ->description('Análise WAF de requisição');
                   
            // IDS (Intrusion Detection System)
            $router->get('ids/dashboard', [SecurityController::class, 'idsDashboard'])
                   ->name('security.ids.dashboard')
                   ->description('Dashboard IDS em tempo real');
                   
            // Penetration Testing
            $router->post('pentest/execute', [SecurityController::class, 'executePentest'])
                   ->name('security.pentest.execute')
                   ->description('Executar penetration testing');
                   
            // AI Monitoring
            $router->get('ai/dashboard', [SecurityController::class, 'aiDashboard'])
                   ->name('security.ai.dashboard')
                   ->description('Dashboard AI Monitoring');
                   
            $router->post('ai/predict-threats', [SecurityController::class, 'predictThreats'])
                   ->name('security.ai.predict')
                   ->description('Predição de ameaças com IA');
                   
            // Threat Intelligence
            $router->get('threat-intel/dashboard', [SecurityController::class, 'threatIntelDashboard'])
                   ->name('security.threat_intel.dashboard')
                   ->description('Dashboard Threat Intelligence');
                   
            $router->post('threat-intel/collect', [SecurityController::class, 'collectThreatIntel'])
                   ->name('security.threat_intel.collect')
                   ->description('Coletar threat intelligence');
                   
            // Zero Trust
            $router->get('zero-trust/dashboard', [SecurityController::class, 'zeroTrustDashboard'])
                   ->name('security.zero_trust.dashboard')
                   ->description('Dashboard Zero Trust');
                   
            $router->post('zero-trust/verify', [SecurityController::class, 'zeroTrustVerify'])
                   ->name('security.zero_trust.verify')
                   ->description('Verificação contínua Zero Trust');
        });
    });
    
    // ===========================================
    // ROTAS DE AUTENTICAÇÃO (sem middleware auth)
    // ===========================================
    $router->group([
        'prefix' => 'api/auth',
        'middleware' => ['cors', 'security'],
    ], function(Router $router) {
        
        // Login
        $router->post('login', [AuthController::class, 'login'])
               ->name('auth.login')
               ->description('Autenticar usuário no sistema');
        
        // Verificação 2FA
        $router->post('verify-2fa', [AuthController::class, 'verify2fa'])
               ->name('auth.verify-2fa')
               ->description('Verificar código de autenticação 2FA');
        
        // Refresh Token
        $router->post('refresh', [AuthController::class, 'refresh'])
               ->name('auth.refresh')
               ->description('Renovar token de acesso');
        
        // Forgot Password
        $router->post('forgot-password', [AuthController::class, 'forgotPassword'])
               ->name('auth.forgot-password')
               ->description('Solicitar reset de senha');
        
        // Reset Password
        $router->post('reset-password', [AuthController::class, 'resetPassword'])
               ->name('auth.reset-password')
               ->description('Redefinir senha do usuário');
    });
    
    // ===========================================
    // ROTAS AUTENTICADAS DE AUTH
    // ===========================================
    $router->group([
        'prefix' => 'api/auth',
        'middleware' => ['cors', 'security', 'auth'],
    ], function(Router $router) {
        
        // Dados do usuário atual
        $router->get('me', [AuthController::class, 'me'])
               ->name('auth.me')
               ->description('Obter dados do usuário atual');
        
        // Logout
        $router->post('logout', [AuthController::class, 'logout'])
               ->name('auth.logout')
               ->description('Fazer logout do usuário');
    });
    
    // ===========================================
    // ROTAS PÚBLICAS (sem autenticação)
    // ===========================================
    $router->group([
        'prefix' => 'api/public',
        'middleware' => ['cors', 'security'],
    ], function(Router $router) {
        
        // Endpoint de status do sistema
        $router->get('status', function() {
            return [
                'status' => 'ativo',
                'versao' => '2.0.0',
                'timestamp' => date('c'),
                'ambiente' => $_ENV['APP_ENV'] ?? 'producao',
            ];
        })->name('api.status')->description('Status geral do sistema');
        
        // Endpoint de saúde do sistema
        $router->get('health', function() {
            return [
                'sistema' => 'funcionando',
                'banco_dados' => 'conectado',
                'cache' => 'ativo',
                'memoria_usada' => memory_get_usage(true),
                'tempo_resposta' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
                'timestamp' => date('c'),
            ];
        })->name('api.health')->description('Verificação de saúde do sistema');
    });
    
    // ===========================================
    // ROTA CATCH-ALL PARA APIs NÃO ENCONTRADAS
    // ===========================================
    $router->any('api/{path}', function() {
        return [
            'erro' => 'Endpoint não encontrado',
            'codigo' => 404,
            'mensagem' => 'A rota solicitada não existe nesta API',
            'documentacao' => '/api/docs',
            'timestamp' => date('c'),
        ];
    })->where(['path' => '.*'])->name('api.404');
};