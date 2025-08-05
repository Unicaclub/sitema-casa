<?php

namespace Core\CLI\Commands;

use Core\MultiTenant\TenantManager;
use Core\MultiTenant\AuditLogger;
use Core\MultiTenant\MonitoringManager;
use Core\Database\Database;
use Core\Logger;

/**
 * TenantCommands - Comandos CLI para gerenciar sistema multi-tenant
 */
class TenantCommands
{
    private TenantManager $tenantManager;
    private AuditLogger $auditLogger;
    private MonitoringManager $monitoring;
    private Database $database;
    private Logger $logger;
    
    public function __construct(
        TenantManager $tenantManager,
        AuditLogger $auditLogger,
        MonitoringManager $monitoring,
        Database $database,
        Logger $logger
    ) {
        $this->tenantManager = $tenantManager;
        $this->auditLogger = $auditLogger;
        $this->monitoring = $monitoring;
        $this->database = $database;
        $this->logger = $logger;
    }
    
    /**
     * Comando: php artisan tenant:create
     */
    public function create(array $args): int
    {
        if (count($args) < 2) {
            echo "Uso: php artisan tenant:create <nome> <email> [codigo]\n";
            return 1;
        }
        
        $name = $args[0];
        $email = $args[1];
        $code = $args[2] ?? $this->generateTenantCode($name);
        
        try {
            $tenantId = $this->database->table('tenants')->insertGetId([
                'name' => $name,
                'code' => $code,
                'email' => $email,
                'active' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Criar usuário administrador padrão
            $adminId = $this->database->table('users')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => 'Administrador',
                'email' => $email,
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'is_admin' => true,
                'active' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Configurações padrão de monitoramento
            $this->createDefaultMonitoringConfig($tenantId);
            
            $this->auditLogger->logTenantOperation('tenant_created', $tenantId, [
                'name' => $name,
                'code' => $code,
                'admin_user_id' => $adminId
            ]);
            
            echo "✅ Tenant criado com sucesso!\n";
            echo "   ID: {$tenantId}\n";
            echo "   Nome: {$name}\n";
            echo "   Código: {$code}\n";
            echo "   Admin ID: {$adminId}\n";
            echo "   Email: {$email}\n";
            echo "   Senha padrão: admin123\n";
            
            return 0;
            
        } catch (\Exception $e) {
            echo "❌ Erro ao criar tenant: {$e->getMessage()}\n";
            $this->logger->error('Failed to create tenant', [
                'name' => $name,
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }
    
    /**
     * Comando: php artisan tenant:list
     */
    public function listTenants(): int
    {
        try {
            $tenants = $this->database->table('tenants')
                ->select(['id', 'name', 'code', 'email', 'active', 'created_at'])
                ->orderBy('created_at', 'DESC')
                ->get();
            
            if (empty($tenants)) {
                echo "Nenhum tenant encontrado.\n";
                return 0;
            }
            
            echo "\n📊 Lista de Tenants:\n";
            echo str_repeat('-', 80) . "\n";
            printf("%-5s %-20s %-15s %-25s %-8s %s\n", 
                'ID', 'Nome', 'Código', 'Email', 'Status', 'Criado em');
            echo str_repeat('-', 80) . "\n";
            
            foreach ($tenants as $tenant) {
                $status = $tenant['active'] ? '✅ Ativo' : '❌ Inativo';
                $created = date('d/m/Y', strtotime($tenant['created_at']));
                
                printf("%-5d %-20s %-15s %-25s %-8s %s\n",
                    $tenant['id'],
                    substr($tenant['name'], 0, 20),
                    $tenant['code'],
                    substr($tenant['email'], 0, 25),
                    $status,
                    $created
                );
            }
            
            echo str_repeat('-', 80) . "\n";
            echo "Total: " . count($tenants) . " tenants\n\n";
            
            return 0;
            
        } catch (\Exception $e) {
            echo "❌ Erro ao listar tenants: {$e->getMessage()}\n";
            return 1;
        }
    }
    
    /**
     * Comando: php artisan tenant:stats [tenant_id]
     */
    public function stats(array $args): int
    {
        $tenantId = isset($args[0]) ? (int)$args[0] : null;
        
        try {
            if ($tenantId) {
                $this->showTenantStats($tenantId);
            } else {
                $this->showAllTenantsStats();
            }
            
            return 0;
            
        } catch (\Exception $e) {
            echo "❌ Erro ao obter estatísticas: {$e->getMessage()}\n";
            return 1;
        }
    }
    
    /**
     * Comando: php artisan tenant:validate-isolation [tenant_id]
     */
    public function validateIsolation(array $args): int
    {
        $tenantId = isset($args[0]) ? (int)$args[0] : null;
        
        echo "🔍 Validando isolamento multi-tenant...\n\n";
        
        try {
            $issues = [];
            
            if ($tenantId) {
                $issues = $this->validateSingleTenantIsolation($tenantId);
            } else {
                $issues = $this->validateAllTenantsIsolation();
            }
            
            if (empty($issues)) {
                echo "✅ Isolamento multi-tenant está funcionando corretamente!\n";
                return 0;
            } else {
                echo "❌ Problemas de isolamento encontrados:\n\n";
                foreach ($issues as $issue) {
                    echo "  • {$issue}\n";
                }
                echo "\n";
                return 1;
            }
            
        } catch (\Exception $e) {
            echo "❌ Erro durante validação: {$e->getMessage()}\n";
            return 1;
        }
    }
    
    /**
     * Comando: php artisan tenant:cache-clear [tenant_id]
     */
    public function cacheClear(array $args): int
    {
        $tenantId = isset($args[0]) ? (int)$args[0] : null;
        
        try {
            if ($tenantId) {
                $this->tenantManager->invalidateTenantCache($tenantId);
                echo "✅ Cache do tenant {$tenantId} limpo com sucesso!\n";
            } else {
                // Limpar cache de todos os tenants
                $tenants = $this->tenantManager->getActiveTenants();
                foreach ($tenants as $tenant) {
                    $this->tenantManager->invalidateTenantCache($tenant['id']);
                }
                echo "✅ Cache de todos os tenants limpo com sucesso!\n";
            }
            
            return 0;
            
        } catch (\Exception $e) {
            echo "❌ Erro ao limpar cache: {$e->getMessage()}\n";
            return 1;
        }
    }
    
    /**
     * Comando: php artisan tenant:security-report [tenant_id] [days]
     */
    public function securityReport(array $args): int
    {
        $tenantId = isset($args[0]) ? (int)$args[0] : null;
        $days = isset($args[1]) ? (int)$args[1] : 7;
        
        if (!$tenantId) {
            echo "Uso: php artisan tenant:security-report <tenant_id> [days=7]\n";
            return 1;
        }
        
        try {
            echo "📊 Gerando relatório de segurança...\n";
            
            $report = $this->monitoring->generateSecurityReport($tenantId, $days);
            
            echo "\n🔒 RELATÓRIO DE SEGURANÇA - TENANT {$tenantId}\n";
            echo str_repeat('=', 60) . "\n";
            echo "Período: {$days} dias\n";
            echo "Gerado em: {$report['generated_at']}\n\n";
            
            // Métricas de segurança
            $security = $report['security_metrics'];
            echo "📈 MÉTRICAS DE SEGURANÇA:\n";
            echo "  • Tentativas de acesso cruzado (24h): {$security['cross_tenant_attempts_24h']}\n";
            echo "  • Tentativas de acesso cruzado (1h): {$security['cross_tenant_attempts_1h']}\n";
            echo "  • Falhas de autenticação (24h): {$security['auth_failures_24h']}\n";
            echo "  • Eventos críticos (24h): {$security['critical_security_events_24h']}\n";
            echo "  • IPs únicos (24h): {$security['unique_ips_24h']}\n\n";
            
            // Atividades suspeitas
            if (!empty($report['suspicious_activity'])) {
                echo "⚠️  ATIVIDADES SUSPEITAS:\n";
                foreach ($report['suspicious_activity'] as $activity) {
                    echo "  • {$activity['description']}\n";
                }
                echo "\n";
            }
            
            // Detecção de vazamento
            if (!empty($report['data_leakage_detection'])) {
                echo "🚨 DETECÇÃO DE VAZAMENTO DE DADOS:\n";
                foreach ($report['data_leakage_detection'] as $leakage) {
                    echo "  • {$leakage['description']} ({$leakage['count']} ocorrências)\n";
                }
                echo "\n";
            }
            
            // Recomendações
            if (!empty($report['recommendations'])) {
                echo "💡 RECOMENDAÇÕES:\n";
                foreach ($report['recommendations'] as $rec) {
                    $priority = strtoupper($rec['priority']);
                    echo "  • [{$priority}] {$rec['title']}\n";
                    echo "    {$rec['description']}\n\n";
                }
            }
            
            return 0;
            
        } catch (\Exception $e) {
            echo "❌ Erro ao gerar relatório: {$e->getMessage()}\n";
            return 1;
        }
    }
    
    /**
     * Comando: php artisan tenant:monitor [tenant_id]
     */
    public function monitor(array $args): int
    {
        $tenantId = isset($args[0]) ? (int)$args[0] : null;
        
        try {
            if ($tenantId) {
                echo "📊 Coletando métricas do tenant {$tenantId}...\n";
                $metrics = $this->monitoring->collectMetrics($tenantId);
                $this->displayMetrics($metrics);
            } else {
                echo "📊 Coletando métricas de todos os tenants...\n";
                $tenants = $this->tenantManager->getActiveTenants();
                
                foreach ($tenants as $tenant) {
                    echo "\n" . str_repeat('-', 50) . "\n";
                    echo "Tenant: {$tenant['name']} (ID: {$tenant['id']})\n";
                    echo str_repeat('-', 50) . "\n";
                    
                    $metrics = $this->monitoring->collectMetrics($tenant['id']);
                    $this->displayMetrics($metrics);
                }
            }
            
            return 0;
            
        } catch (\Exception $e) {
            echo "❌ Erro ao coletar métricas: {$e->getMessage()}\n";
            return 1;
        }
    }
    
    /**
     * Comando: php artisan tenant:cleanup [days=90]
     */
    public function cleanup(array $args): int
    {
        $days = isset($args[0]) ? (int)$args[0] : 90;
        
        echo "🧹 Iniciando limpeza de dados antigos ({$days} dias)...\n";
        
        try {
            // Executar procedure de limpeza
            $this->database->statement("CALL CleanupOldMetrics(?)", [$days]);
            
            echo "✅ Limpeza concluída com sucesso!\n";
            echo "   • Métricas mais antigas que {$days} dias removidas\n";
            echo "   • Alertas resolvidos antigos removidos\n";
            echo "   • Relatórios antigos removidos\n";
            echo "   • Logs de performance antigos removidos\n";
            
            $this->auditLogger->log('system_cleanup', 'system', 'info', [
                'retention_days' => $days
            ]);
            
            return 0;
            
        } catch (\Exception $e) {
            echo "❌ Erro durante limpeza: {$e->getMessage()}\n";
            return 1;
        }
    }
    
    /**
     * Comando: php artisan tenant:aggregate-metrics [date]
     */
    public function aggregateMetrics(array $args): int
    {
        $date = isset($args[0]) ? $args[0] : date('Y-m-d');
        
        echo "📊 Agregando métricas para {$date}...\n";
        
        try {
            // Executar procedure de agregação
            $this->database->statement("CALL AggregateDailyMetrics(?)", [$date]);
            
            echo "✅ Agregação de métricas concluída para {$date}!\n";
            
            return 0;
            
        } catch (\Exception $e) {
            echo "❌ Erro durante agregação: {$e->getMessage()}\n";
            return 1;
        }
    }
    
    // Métodos auxiliares
    
    private function generateTenantCode(string $name): string
    {
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 6));
        $code = $code ?: 'TENANT';
        
        // Verificar se código já existe
        $counter = 1;
        $originalCode = $code;
        
        while ($this->database->table('tenants')->where('code', $code)->exists()) {
            $code = $originalCode . $counter;
            $counter++;
        }
        
        return $code;
    }
    
    private function createDefaultMonitoringConfig(int $tenantId): void
    {
        $configs = [
            'alert_thresholds' => [
                'max_cross_tenant_attempts_per_hour' => 5,
                'max_query_time_ms' => 1000,
                'max_memory_usage_mb' => 512,
                'max_api_errors_per_minute' => 10,
                'min_cache_hit_rate_percent' => 70
            ],
            'monitoring_settings' => [
                'collect_metrics_interval_minutes' => 5,
                'generate_reports_interval_hours' => 24,
                'cleanup_old_data_days' => 90,
                'enable_real_time_alerts' => true,
                'enable_email_notifications' => true
            ]
        ];
        
        foreach ($configs as $key => $value) {
            $this->database->table('tenant_monitoring_config')->insert([
                'tenant_id' => $tenantId,
                'config_key' => $key,
                'config_value' => json_encode($value),
                'enabled' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    private function showTenantStats(int $tenantId): void
    {
        $tenant = $this->database->table('tenants')->where('id', $tenantId)->first();
        
        if (!$tenant) {
            echo "Tenant {$tenantId} não encontrado.\n";
            return;
        }
        
        echo "📊 ESTATÍSTICAS - {$tenant['name']} (ID: {$tenantId})\n";
        echo str_repeat('=', 60) . "\n";
        
        $stats = $this->tenantManager->getTenantStats($tenantId);
        
        foreach ($stats as $table => $count) {
            $label = ucfirst(str_replace('_', ' ', $table));
            echo sprintf("  %-20s: %d\n", $label, $count);
        }
        
        echo "\n";
    }
    
    private function showAllTenantsStats(): void
    {
        $tenants = $this->tenantManager->getActiveTenants();
        
        echo "📊 ESTATÍSTICAS GERAIS\n";
        echo str_repeat('=', 80) . "\n";
        printf("%-5s %-20s %-10s %-10s %-10s %-10s\n", 
            'ID', 'Nome', 'Usuários', 'Clientes', 'Produtos', 'Vendas');
        echo str_repeat('-', 80) . "\n";
        
        foreach ($tenants as $tenant) {
            $stats = $this->tenantManager->getTenantStats($tenant['id']);
            
            printf("%-5d %-20s %-10d %-10d %-10d %-10d\n",
                $tenant['id'],
                substr($tenant['name'], 0, 20),
                $stats['users'] ?? 0,
                $stats['clientes'] ?? 0,
                $stats['produtos'] ?? 0,
                $stats['vendas'] ?? 0
            );
        }
        
        echo str_repeat('-', 80) . "\n\n";
    }
    
    private function validateSingleTenantIsolation(int $tenantId): array
    {
        $issues = [];
        
        // Verificar se tenant existe
        if (!$this->tenantManager->tenantExists($tenantId)) {
            $issues[] = "Tenant {$tenantId} não existe ou está inativo";
            return $issues;
        }
        
        // Verificar dados órfãos (sem tenant_id)
        $tables = ['clientes', 'produtos', 'vendas', 'transacoes_financeiras'];
        
        foreach ($tables as $table) {
            try {
                $orphans = $this->database->table($table)
                    ->whereNull('tenant_id')
                    ->count();
                    
                if ($orphans > 0) {
                    $issues[] = "Tabela {$table} tem {$orphans} registros sem tenant_id";
                }
            } catch (\Exception $e) {
                // Tabela pode não existir
            }
        }
        
        return $issues;
    }
    
    private function validateAllTenantsIsolation(): array
    {
        $issues = [];
        $tenants = $this->tenantManager->getActiveTenants();
        
        foreach ($tenants as $tenant) {
            $tenantIssues = $this->validateSingleTenantIsolation($tenant['id']);
            $issues = array_merge($issues, $tenantIssues);
        }
        
        return $issues;
    }
    
    private function displayMetrics(array $metrics): void
    {
        echo "\n⏱️  PERFORMANCE:\n";
        $perf = $metrics['performance'];
        echo "  • Tempo médio de query: {$perf['avg_query_time_ms']}ms\n";
        echo "  • Queries lentas (24h): {$perf['slow_queries_24h']}\n";
        echo "  • Uso médio de memória: {$perf['avg_memory_usage_mb']}MB\n";
        echo "  • Cache hit rate: {$perf['cache_hit_rate_percent']}%\n";
        
        echo "\n🔒 SEGURANÇA:\n";
        $sec = $metrics['security'];
        echo "  • Tentativas cross-tenant (24h): {$sec['cross_tenant_attempts_24h']}\n";
        echo "  • Tentativas cross-tenant (1h): {$sec['cross_tenant_attempts_1h']}\n";
        echo "  • Falhas de auth (24h): {$sec['auth_failures_24h']}\n";
        echo "  • Eventos críticos (24h): {$sec['critical_security_events_24h']}\n";
        
        echo "\n📈 USO:\n";
        $usage = $metrics['usage'];
        echo "  • Usuários ativos (24h): {$usage['active_users_24h']}\n";
        echo "  • Requests API (24h): {$usage['api_requests_24h']}\n";
        echo "  • Operações de dados (24h): {$usage['data_operations_24h']}\n";
        echo "  • Tamanho dos dados: {$usage['data_size_mb']}MB\n";
        
        echo "\n💚 SAÚDE:\n";
        $health = $metrics['health'];
        echo "  • Erros aplicação (1h): {$health['application_errors_1h']}\n";
        echo "  • Conectividade BD: " . ($health['database_connectivity'] ? '✅' : '❌') . "\n";
        echo "  • Conectividade Cache: " . ($health['cache_connectivity'] ? '✅' : '❌') . "\n";
        echo "  • Status: {$health['status']}\n";
        
        echo "\n⏱️  Tempo de coleta: {$metrics['collection_time_ms']}ms\n";
    }
}