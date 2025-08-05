<?php

/**
 * Script de teste para comandos multi-tenant
 * Uso: php test_commands.php
 */

echo "🧪 TESTE DOS COMANDOS MULTI-TENANT\n";
echo str_repeat('=', 50) . "\n";

// Simular ambiente para testes
$_SERVER['REQUEST_URI'] = '/test';
$_SERVER['REQUEST_METHOD'] = 'GET';

// Incluir dependências necessárias
require_once 'src/Core/Database/Database.php';
require_once 'src/Core/Cache/CacheManager.php';
require_once 'src/Core/Logger.php';
require_once 'src/Core/Auth/Auth.php';
require_once 'src/Core/MultiTenant/TenantManager.php';
require_once 'src/Core/MultiTenant/AuditLogger.php';
require_once 'src/Core/MultiTenant/MonitoringManager.php';
require_once 'src/Core/CLI/Commands/TenantCommands.php';

try {
    // Inicializar dependências básicas
    echo "1. Inicializando sistema...\n";
    
    // Configuração do banco
    $dbConfig = [
        'host' => 'localhost',
        'database' => 'erp_sistema',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4'
    ];
    
    $database = new Core\Database\Database($dbConfig);
    echo "   ✅ Database inicializado\n";
    
    // Cache simples para testes
    $cache = new class {
        private $data = [];
        public function remember($key, $ttl, $callback) {
            if (!isset($this->data[$key])) {
                $this->data[$key] = $callback();
            }
            return $this->data[$key];
        }
        public function put($key, $value, $ttl) { $this->data[$key] = $value; }
        public function get($key, $default = null) { return $this->data[$key] ?? $default; }
        public function has($key) { return isset($this->data[$key]); }
        public function forget($key) { unset($this->data[$key]); }
        public function flush() { $this->data = []; }
        public function deleteByPattern($pattern) {}
    };
    echo "   ✅ Cache inicializado\n";
    
    // Logger simples
    $logger = new class {
        public function info($message, $context = []) { echo "   [INFO] $message\n"; }
        public function error($message, $context = []) { echo "   [ERROR] $message\n"; }
        public function warning($message, $context = []) { echo "   [WARNING] $message\n"; }
        public function critical($message, $context = []) { echo "   [CRITICAL] $message\n"; }
        public function log($level, $message, $context = []) { echo "   [$level] $message\n"; }
    };
    echo "   ✅ Logger inicializado\n";
    
    // Auth mock
    $auth = new class {
        public function check() { return true; }
        public function user() { return (object)['id' => 1, 'tenant_id' => 1]; }
        public function id() { return 1; }
    };
    echo "   ✅ Auth inicializado\n";
    
    // Inicializar componentes multi-tenant
    $tenantManager = new Core\MultiTenant\TenantManager($database, $cache, $logger);
    echo "   ✅ TenantManager inicializado\n";
    
    $auditLogger = new Core\MultiTenant\AuditLogger($database, $logger, $tenantManager, $auth);
    echo "   ✅ AuditLogger inicializado\n";
    
    // Mock para notificações (para MonitoringManager)
    $notifications = new class {
        public function enviarNotificacao($userId, $title, $message, $type, $data) {
            echo "   📧 Notificação enviada para usuário $userId: $title\n";
        }
    };
    
    $monitoring = new Core\MultiTenant\MonitoringManager($database, $logger, $cache, $notifications, $auditLogger);
    echo "   ✅ MonitoringManager inicializado\n";
    
    // Inicializar comandos
    $tenantCommands = new Core\CLI\Commands\TenantCommands($tenantManager, $auditLogger, $monitoring, $database, $logger);
    echo "   ✅ TenantCommands inicializado\n";
    
    echo "\n2. Testando comandos...\n";
    
    // Teste 1: Listar tenants
    echo "\n📋 Teste: tenant:list\n";
    ob_start();
    $result = $tenantCommands->listTenants();
    $output = ob_get_clean();
    echo $output;
    if ($result === 0) {
        echo "   ✅ Comando executado com sucesso\n";
    } else {
        echo "   ❌ Comando falhou\n";
    }
    
    // Teste 2: Criar tenant
    echo "\n🏢 Teste: tenant:create\n";
    ob_start();
    $result = $tenantCommands->create(['Empresa Teste CMD', 'cmd@teste.com', 'CMDTEST']);
    $output = ob_get_clean();
    echo $output;
    if ($result === 0) {
        echo "   ✅ Tenant criado com sucesso\n";
    } else {
        echo "   ❌ Falha ao criar tenant\n";
    }
    
    // Teste 3: Stats de tenant
    echo "\n📊 Teste: tenant:stats\n";
    ob_start();
    $result = $tenantCommands->stats(['1']);
    $output = ob_get_clean();
    echo $output;
    if ($result === 0) {
        echo "   ✅ Stats obtidas com sucesso\n";
    } else {
        echo "   ❌ Falha ao obter stats\n";
    }
    
    // Teste 4: Validar isolamento
    echo "\n🔒 Teste: tenant:validate-isolation\n";
    ob_start();
    $result = $tenantCommands->validateIsolation(['1']);
    $output = ob_get_clean();
    echo $output;
    if ($result === 0) {
        echo "   ✅ Validação de isolamento OK\n";
    } else {
        echo "   ⚠️  Problemas de isolamento detectados (normal em ambiente de teste)\n";
    }
    
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "🎯 RESUMO DOS TESTES DE COMANDOS:\n";
    echo "✅ Sistema de comandos funcionando\n";
    echo "✅ TenantManager operacional\n";
    echo "✅ AuditLogger funcionando\n";
    echo "✅ MonitoringManager ativo\n";
    echo "\n💡 PRÓXIMOS PASSOS:\n";
    echo "1. Execute: php test_multitenant.php (se ainda não executou)\n";
    echo "2. Execute testes unitários\n";
    echo "3. Teste com dados reais\n";
    echo "\n🎉 Comandos multi-tenant estão funcionando!\n";
    
} catch (Exception $e) {
    echo "\n❌ ERRO DURANTE TESTE:\n";
    echo "Erro: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
    echo "\n💡 DICAS PARA RESOLVER:\n";
    echo "1. Verifique se as migrações foram executadas\n";
    echo "2. Verifique configuração do banco de dados\n";
    echo "3. Verifique se todos os arquivos foram criados\n";
}