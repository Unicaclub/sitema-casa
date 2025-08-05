<?php

namespace Tests\Feature\MultiTenant;

use Tests\TestCase;
use Core\MultiTenant\TenantManager;
use Core\Auth\Auth;
use Core\Database\Database;
use Core\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TenantIsolationTest - Testes de isolamento multi-tenant
 * 
 * Valida que dados de diferentes tenants são completamente isolados
 * e que não há vazamento de dados entre tenants
 */
class TenantIsolationTest extends TestCase
{
    private TenantManager $tenantManager;
    private Database $database;
    private array $testTenants = [];
    private array $testUsers = [];
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tenantManager = $this->app->resolve(TenantManager::class);
        $this->database = $this->app->resolve(Database::class);
        
        $this->createTestTenants();
        $this->createTestUsers();
        $this->createTestData();
    }
    
    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }
    
    #[Test]
    public function test_tenant_context_is_set_correctly()
    {
        $tenantId = $this->testTenants[0]['id'];
        $this->tenantManager->setCurrentTenant($tenantId);
        
        $this->assertEquals($tenantId, $this->tenantManager->getCurrentTenantId());
        
        $tenant = $this->tenantManager->getCurrentTenant();
        $this->assertEquals($tenantId, $tenant['id']);
    }
    
    #[Test]
    public function test_invalid_tenant_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tenantManager->setCurrentTenant(99999);
    }
    
    #[Test]
    public function test_tenant_access_validation()
    {
        $tenant1User = $this->testUsers[0];
        $tenant2Id = $this->testTenants[1]['id'];
        
        // Usuário do tenant 1 não deve ter acesso ao tenant 2
        $hasAccess = $this->tenantManager->validateTenantAccess($tenant1User['id'], $tenant2Id);
        $this->assertFalse($hasAccess);
        
        // Usuário deve ter acesso ao próprio tenant
        $hasAccess = $this->tenantManager->validateTenantAccess($tenant1User['id'], $tenant1User['tenant_id']);
        $this->assertTrue($hasAccess);
    }
    
    #[Test]
    #[DataProvider('moduleDataProvider')]
    public function test_module_data_isolation(string $table, array $sampleData)
    {
        $tenant1Id = $this->testTenants[0]['id'];
        $tenant2Id = $this->testTenants[1]['id'];
        
        // Criar dados para tenant 1
        $this->tenantManager->setCurrentTenant($tenant1Id);
        $data1 = array_merge($sampleData, ['tenant_id' => $tenant1Id]);
        $id1 = $this->database->table($table)->insertGetId($data1);
        
        // Criar dados para tenant 2
        $this->tenantManager->setCurrentTenant($tenant2Id);
        $data2 = array_merge($sampleData, ['tenant_id' => $tenant2Id]);
        $id2 = $this->database->table($table)->insertGetId($data2);
        
        // Verificar isolamento - tenant 1 não deve ver dados do tenant 2
        $this->tenantManager->setCurrentTenant($tenant1Id);
        $result = $this->database->table($table)->where('id', $id2)->first();
        $this->assertNull($result, "Tenant 1 should not see data from tenant 2 in table {$table}");
        
        // Verificar que vê próprios dados
        $result = $this->database->table($table)->where('id', $id1)->first();
        $this->assertNotNull($result, "Tenant 1 should see its own data in table {$table}");
        
        // Verificar isolamento inverso
        $this->tenantManager->setCurrentTenant($tenant2Id);
        $result = $this->database->table($table)->where('id', $id1)->first();
        $this->assertNull($result, "Tenant 2 should not see data from tenant 1 in table {$table}");
    }
    
    public static function moduleDataProvider(): array
    {
        return [
            'clientes' => [
                'clientes',
                [
                    'nome' => 'Cliente Teste',
                    'email' => 'teste@cliente.com',
                    'telefone' => '11999999999',
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ],
            'produtos' => [
                'produtos',
                [
                    'nome' => 'Produto Teste',
                    'sku' => 'PROD-' . uniqid(),
                    'preco' => 100.00,
                    'quantidade_atual' => 10,
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ],
            'vendas' => [
                'vendas',
                [
                    'numero' => 'VENDA-' . uniqid(),
                    'data_venda' => now(),
                    'valor_total' => 500.00,
                    'status' => 'concluida',
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ]
        ];
    }
    
    #[Test]
    public function test_cross_tenant_api_access_denied()
    {
        $tenant1User = $this->testUsers[0];
        $tenant2Id = $this->testTenants[1]['id'];
        
        // Simular login com usuário do tenant 1
        $this->actingAs($tenant1User);
        
        // Tentar acessar dados do tenant 2 via API
        $response = $this->get("/api/clientes?tenant_id={$tenant2Id}");
        $this->assertEquals(403, $response->getStatusCode());
        
        // Verificar que tentativa foi logada
        $logs = $this->database->table('tenant_access_logs')
            ->where('user_id', $tenant1User['id'])
            ->where('requested_tenant_id', $tenant2Id)
            ->get();
            
        $this->assertCount(1, $logs, 'Cross-tenant access attempt should be logged');
    }
    
    #[Test]
    public function test_cache_isolation()
    {
        $tenant1Id = $this->testTenants[0]['id'];
        $tenant2Id = $this->testTenants[1]['id'];
        
        // Definir dados no cache para tenant 1
        $this->tenantManager->setCurrentTenant($tenant1Id);
        $key1 = $this->tenantManager->tenantCacheKey('test_data');
        cache()->put($key1, 'tenant1_data', 3600);
        
        // Definir dados no cache para tenant 2
        $this->tenantManager->setCurrentTenant($tenant2Id);
        $key2 = $this->tenantManager->tenantCacheKey('test_data');
        cache()->put($key2, 'tenant2_data', 3600);
        
        // Verificar isolamento
        $this->assertNotEquals($key1, $key2, 'Cache keys should be different for different tenants');
        
        $this->tenantManager->setCurrentTenant($tenant1Id);
        $data1 = cache()->get($this->tenantManager->tenantCacheKey('test_data'));
        $this->assertEquals('tenant1_data', $data1);
        
        $this->tenantManager->setCurrentTenant($tenant2Id);
        $data2 = cache()->get($this->tenantManager->tenantCacheKey('test_data'));
        $this->assertEquals('tenant2_data', $data2);
    }
    
    #[Test]
    public function test_database_queries_auto_scoped()
    {
        $tenant1Id = $this->testTenants[0]['id'];
        $tenant2Id = $this->testTenants[1]['id'];
        
        // Criar clientes para ambos os tenants
        $this->database->table('clientes')->insert([
            'tenant_id' => $tenant1Id,
            'nome' => 'Cliente Tenant 1',
            'email' => 'cliente1@tenant1.com',
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        $this->database->table('clientes')->insert([
            'tenant_id' => $tenant2Id,
            'nome' => 'Cliente Tenant 2',
            'email' => 'cliente2@tenant2.com',
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        // Definir contexto do tenant 1
        $this->tenantManager->setCurrentTenant($tenant1Id);
        
        // Query deveria retornar apenas dados do tenant 1
        $query = $this->database->table('clientes');
        $this->tenantManager->scopeToTenant($query);
        $clientes = $query->get();
        
        $this->assertCount(1, $clientes);
        $this->assertEquals($tenant1Id, $clientes[0]['tenant_id']);
        $this->assertEquals('Cliente Tenant 1', $clientes[0]['nome']);
    }
    
    #[Test]
    public function test_resource_ownership_validation()
    {
        $tenant1Id = $this->testTenants[0]['id'];
        $tenant2Id = $this->testTenants[1]['id'];
        
        // Criar cliente para tenant 1
        $clienteId = $this->database->table('clientes')->insertGetId([
            'tenant_id' => $tenant1Id,
            'nome' => 'Cliente Tenant 1',
            'email' => 'cliente@tenant1.com',
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        // Tenant 1 deve ter acesso ao próprio cliente
        $this->tenantManager->setCurrentTenant($tenant1Id);
        $hasOwnership = $this->tenantManager->validateOwnership('clientes', $clienteId);
        $this->assertTrue($hasOwnership);
        
        // Tenant 2 não deve ter acesso ao cliente do tenant 1
        $this->tenantManager->setCurrentTenant($tenant2Id);
        $hasOwnership = $this->tenantManager->validateOwnership('clientes', $clienteId);
        $this->assertFalse($hasOwnership);
    }
    
    #[Test]
    public function test_data_leakage_detection_in_response()
    {
        $tenant1Id = $this->testTenants[0]['id'];
        $tenant2Id = $this->testTenants[1]['id'];
        
        // Simular resposta com dados de múltiplos tenants (vazamento)
        $responseData = [
            'clientes' => [
                ['id' => 1, 'nome' => 'Cliente 1', 'tenant_id' => $tenant1Id],
                ['id' => 2, 'nome' => 'Cliente 2', 'tenant_id' => $tenant2Id] // Vazamento!
            ]
        ];
        
        $this->tenantManager->setCurrentTenant($tenant1Id);
        
        // Middleware deveria detectar o vazamento
        $middleware = new \Core\Http\Middleware\TenantMiddleware(
            $this->tenantManager,
            $this->app->resolve(Auth::class),
            $this->app->resolve(Logger::class)
        );
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Data leakage detected');
        
        // Simular validação de resposta
        $response = new \Core\Http\Response($responseData);
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('validateResponse');
        $method->setAccessible(true);
        $method->invoke($middleware, $response, $tenant1Id);
    }
    
    #[Test]
    public function test_bulk_operations_respect_tenant_isolation()
    {
        $tenant1Id = $this->testTenants[0]['id'];
        $tenant2Id = $this->testTenants[1]['id'];
        
        // Criar dados para ambos os tenants
        $this->database->table('clientes')->insert([
            ['tenant_id' => $tenant1Id, 'nome' => 'Cliente 1T1', 'email' => 'c1@t1.com', 'ativo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant1Id, 'nome' => 'Cliente 2T1', 'email' => 'c2@t1.com', 'ativo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant2Id, 'nome' => 'Cliente 1T2', 'email' => 'c1@t2.com', 'ativo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant2Id, 'nome' => 'Cliente 2T2', 'email' => 'c2@t2.com', 'ativo' => true, 'created_at' => now(), 'updated_at' => now()]
        ]);
        
        // Operação bulk com contexto do tenant 1
        $this->tenantManager->setCurrentTenant($tenant1Id);
        
        // Update bulk deveria afetar apenas dados do tenant 1
        $query = $this->database->table('clientes');
        $this->tenantManager->scopeToTenant($query);
        $affected = $query->update(['ativo' => false]);
        
        $this->assertEquals(2, $affected, 'Bulk update should affect only tenant 1 records');
        
        // Verificar que dados do tenant 2 não foram afetados
        $tenant2ActiveCount = $this->database->table('clientes')
            ->where('tenant_id', $tenant2Id)
            ->where('ativo', true)
            ->count();
            
        $this->assertEquals(2, $tenant2ActiveCount, 'Tenant 2 records should remain active');
    }
    
    private function createTestTenants(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $tenant = [
                'name' => "Tenant Teste {$i}",
                'code' => "TEST{$i}",
                'email' => "tenant{$i}@test.com",
                'active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ];
            
            $tenant['id'] = $this->database->table('tenants')->insertGetId($tenant);
            $this->testTenants[] = $tenant;
        }
    }
    
    private function createTestUsers(): void
    {
        foreach ($this->testTenants as $tenant) {
            $user = [
                'tenant_id' => $tenant['id'],
                'name' => "Usuário Teste {$tenant['id']}",
                'email' => "user{$tenant['id']}@test.com",
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ];
            
            $user['id'] = $this->database->table('users')->insertGetId($user);
            $this->testUsers[] = $user;
        }
    }
    
    private function createTestData(): void
    {
        // Criar dados de teste para cada tenant
        foreach ($this->testTenants as $tenant) {
            $tenantId = $tenant['id'];
            
            // Clientes
            $this->database->table('clientes')->insert([
                'tenant_id' => $tenantId,
                'nome' => "Cliente Principal T{$tenantId}",
                'email' => "cliente{$tenantId}@test.com",
                'telefone' => "11999999{$tenantId}{$tenantId}{$tenantId}",
                'ativo' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            // Produtos
            $this->database->table('produtos')->insert([
                'tenant_id' => $tenantId,
                'nome' => "Produto Principal T{$tenantId}",
                'sku' => "PROD-T{$tenantId}-001",
                'preco' => 100.00 * $tenantId,
                'quantidade_atual' => 50,
                'ativo' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
    
    private function cleanupTestData(): void
    {
        // Limpar dados de teste
        $this->database->table('tenant_access_logs')->where('user_id', '>', 0)->delete();
        $this->database->table('clientes')->where('email', 'like', '%@test.com')->delete();
        $this->database->table('produtos')->where('sku', 'like', 'PROD-T%')->delete();
        $this->database->table('users')->where('email', 'like', '%@test.com')->delete();
        $this->database->table('tenants')->where('code', 'like', 'TEST%')->delete();
    }
    
    private function actingAs(array $user): void
    {
        $auth = $this->app->resolve(Auth::class);
        $auth->loginUsingId($user['id']);
    }
}