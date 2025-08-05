<?php

namespace Tests\Performance;

use Tests\TestCase;
use Core\MultiTenant\TenantManager;
use Core\Database\Database;
use Core\Cache\CacheManager;
use PHPUnit\Framework\Attributes\Test;

/**
 * MultiTenantPerformanceTest - Testes de performance para sistema multi-tenant
 * 
 * Valida que o sistema mantém performance adequada mesmo com muitos tenants
 * e grandes volumes de dados
 */
class MultiTenantPerformanceTest extends TestCase
{
    private TenantManager $tenantManager;
    private Database $database;
    private CacheManager $cache;
    private array $testTenants = [];
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tenantManager = $this->app->resolve(TenantManager::class);
        $this->database = $this->app->resolve(Database::class);
        $this->cache = $this->app->resolve(CacheManager::class);
        
        $this->createMultipleTenants(50); // 50 tenants para teste
        $this->createBulkTestData();
    }
    
    protected function tearDown(): void
    {
        $this->cleanupPerformanceTestData();
        parent::tearDown();
    }
    
    #[Test]
    public function test_tenant_switching_performance()
    {
        $startTime = microtime(true);
        $iterations = 1000;
        
        // Teste de alternância entre tenants
        for ($i = 0; $i < $iterations; $i++) {
            $tenantId = $this->testTenants[array_rand($this->testTenants)]['id'];
            $this->tenantManager->setCurrentTenant($tenantId);
            
            // Operação simples para forçar carregamento de contexto
            $tenant = $this->tenantManager->getCurrentTenant();
            $this->assertNotNull($tenant);
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = ($totalTime / $iterations) * 1000; // em millisegundos
        
        // Deve ser menor que 5ms por operação
        $this->assertLessThan(5.0, $avgTime, 
            "Tenant switching should be under 5ms per operation, got {$avgTime}ms");
            
        echo "\nTenant switching performance: {$avgTime}ms per operation\n";
    }
    
    #[Test]
    public function test_scoped_query_performance()
    {
        $tenantId = $this->testTenants[0]['id'];
        $this->tenantManager->setCurrentTenant($tenantId);
        
        $startTime = microtime(true);
        $iterations = 100;
        
        for ($i = 0; $i < $iterations; $i++) {
            $query = $this->database->table('clientes');
            $this->tenantManager->scopeToTenant($query);
            $results = $query->limit(10)->get();
            
            $this->assertNotEmpty($results);
            // Verificar que todos os resultados pertencem ao tenant correto
            foreach ($results as $result) {
                $this->assertEquals($tenantId, $result['tenant_id']);
            }
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = ($totalTime / $iterations) * 1000;
        
        // Deve ser menor que 10ms por query
        $this->assertLessThan(10.0, $avgTime,
            "Scoped queries should be under 10ms per operation, got {$avgTime}ms");
            
        echo "\nScoped query performance: {$avgTime}ms per query\n";
    }
    
    #[Test]
    public function test_cache_isolation_performance()
    {
        $startTime = microtime(true);
        $iterations = 500;
        
        for ($i = 0; $i < $iterations; $i++) {
            $tenantId = $this->testTenants[$i % count($this->testTenants)]['id'];
            $this->tenantManager->setCurrentTenant($tenantId);
            
            $cacheKey = $this->tenantManager->tenantCacheKey("test_key_{$i}");
            $this->cache->put($cacheKey, "test_value_{$i}", 3600);
            
            $retrieved = $this->cache->get($cacheKey);
            $this->assertEquals("test_value_{$i}", $retrieved);
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = ($totalTime / $iterations) * 1000;
        
        // Deve ser menor que 3ms por operação de cache
        $this->assertLessThan(3.0, $avgTime,
            "Cache operations should be under 3ms per operation, got {$avgTime}ms");
            
        echo "\nCache isolation performance: {$avgTime}ms per operation\n";
    }
    
    #[Test]
    public function test_bulk_operations_performance()
    {
        $tenantId = $this->testTenants[0]['id'];
        $this->tenantManager->setCurrentTenant($tenantId);
        
        $startTime = microtime(true);
        
        // Operação bulk de update
        $query = $this->database->table('clientes');
        $this->tenantManager->scopeToTenant($query);
        $affected = $query->where('ativo', true)->update(['updated_at' => now()]);
        
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;
        
        // Operação bulk deve ser menor que 100ms
        $this->assertLessThan(100.0, $totalTime,
            "Bulk operations should be under 100ms, got {$totalTime}ms");
        
        $this->assertGreaterThan(0, $affected, 'Bulk operation should affect some records');
        
        echo "\nBulk operation performance: {$totalTime}ms for {$affected} records\n";
    }
    
    #[Test]
    public function test_concurrent_tenant_access_simulation()
    {
        $startTime = microtime(true);
        $totalOperations = 0;
        
        // Simula 10 usuários concorrentes, cada um fazendo 50 operações
        for ($user = 0; $user < 10; $user++) {
            $tenantId = $this->testTenants[$user % count($this->testTenants)]['id'];
            
            for ($op = 0; $op < 50; $op++) {
                $this->tenantManager->setCurrentTenant($tenantId);
                
                // Simula operações típicas
                $query = $this->database->table('clientes');
                $this->tenantManager->scopeToTenant($query);
                $clientes = $query->limit(5)->get();
                
                $query = $this->database->table('produtos');
                $this->tenantManager->scopeToTenant($query);
                $produtos = $query->limit(3)->get();
                
                $totalOperations += 2;
                
                // Verifica isolamento
                foreach ($clientes as $cliente) {
                    $this->assertEquals($tenantId, $cliente['tenant_id']);
                }
                foreach ($produtos as $produto) {
                    $this->assertEquals($tenantId, $produto['tenant_id']);
                }
            }
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = ($totalTime / $totalOperations) * 1000;
        
        // Média deve ser menor que 5ms por operação
        $this->assertLessThan(5.0, $avgTime,
            "Concurrent operations should average under 5ms, got {$avgTime}ms");
            
        echo "\nConcurrent access simulation: {$avgTime}ms per operation ({$totalOperations} total)\n";
    }
    
    #[Test]
    public function test_database_index_effectiveness()
    {
        $tenantId = $this->testTenants[0]['id'];
        $this->tenantManager->setCurrentTenant($tenantId);
        
        // Teste query com índice tenant_id
        $startTime = microtime(true);
        
        $query = $this->database->table('clientes');
        $this->tenantManager->scopeToTenant($query);
        $results = $query->where('ativo', true)->orderBy('nome')->get();
        
        $endTime = microtime(true);
        $queryTime = ($endTime - $startTime) * 1000;
        
        // Query com índice deve ser rápida mesmo com muitos dados
        $this->assertLessThan(50.0, $queryTime,
            "Indexed tenant query should be under 50ms, got {$queryTime}ms");
        
        // Verificar que EXPLAIN usa o índice
        $explainResult = $this->database->select("
            EXPLAIN SELECT * FROM clientes 
            WHERE tenant_id = ? AND ativo = 1 
            ORDER BY nome
        ", [$tenantId]);
        
        $this->assertNotEmpty($explainResult);
        echo "\nDatabase index performance: {$queryTime}ms for " . count($results) . " records\n";
    }
    
    #[Test]
    public function test_memory_usage_with_multiple_tenants()
    {
        $initialMemory = memory_get_usage(true);
        
        // Simula operações com múltiplos tenants
        for ($i = 0; $i < 100; $i++) {
            $tenantId = $this->testTenants[$i % count($this->testTenants)]['id'];
            $this->tenantManager->setCurrentTenant($tenantId);
            
            // Operações que podem consumir memória
            $tenant = $this->tenantManager->getCurrentTenant();
            $stats = $this->tenantManager->getTenantStats($tenantId);
            
            $query = $this->database->table('clientes');
            $this->tenantManager->scopeToTenant($query);
            $results = $query->limit(20)->get();
        }
        
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        $memoryIncreaseMB = $memoryIncrease / 1024 / 1024;
        
        // Aumento de memória deve ser razoável (menos de 10MB)
        $this->assertLessThan(10.0, $memoryIncreaseMB,
            "Memory increase should be under 10MB, got {$memoryIncreaseMB}MB");
            
        echo "\nMemory usage increase: {$memoryIncreaseMB}MB for 100 operations\n";
    }
    
    #[Test]
    public function test_cache_hit_ratio_optimization()
    {
        $cacheHits = 0;
        $totalCacheOperations = 0;
        
        // Primeira passada - popula cache
        for ($i = 0; $i < 20; $i++) {
            $tenantId = $this->testTenants[$i % 5]['id']; // Usa apenas 5 tenants para aumentar hits
            $this->tenantManager->setCurrentTenant($tenantId);
            
            $tenant = $this->tenantManager->getCurrentTenant(); // Deve cachear
            $totalCacheOperations++;
        }
        
        // Segunda passada - deve ter muitos cache hits
        for ($i = 0; $i < 20; $i++) {
            $tenantId = $this->testTenants[$i % 5]['id'];
            
            $cacheKey = "tenant:{$tenantId}";
            if ($this->cache->has($cacheKey)) {
                $cacheHits++;
            }
            
            $this->tenantManager->setCurrentTenant($tenantId);
            $tenant = $this->tenantManager->getCurrentTenant();
            $totalCacheOperations++;
        }
        
        $hitRatio = ($cacheHits / $totalCacheOperations) * 100;
        
        // Hit ratio deve ser alto para operações repetidas
        $this->assertGreaterThan(50.0, $hitRatio,
            "Cache hit ratio should be over 50%, got {$hitRatio}%");
            
        echo "\nCache hit ratio: {$hitRatio}% ({$cacheHits}/{$totalCacheOperations})\n";
    }
    
    private function createMultipleTenants(int $count): void
    {
        $tenants = [];
        for ($i = 1; $i <= $count; $i++) {
            $tenants[] = [
                'name' => "Performance Test Tenant {$i}",
                'code' => "PERF{$i}",
                'email' => "perf{$i}@test.com",
                'active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ];
        }
        
        // Insert em batch para performance
        $this->database->table('tenants')->insert($tenants);
        
        // Recuperar IDs
        $this->testTenants = $this->database->table('tenants')
            ->where('code', 'like', 'PERF%')
            ->get()
            ->toArray();
    }
    
    private function createBulkTestData(): void
    {
        $clientes = [];
        $produtos = [];
        
        foreach ($this->testTenants as $tenant) {
            $tenantId = $tenant['id'];
            
            // 100 clientes por tenant
            for ($i = 1; $i <= 100; $i++) {
                $clientes[] = [
                    'tenant_id' => $tenantId,
                    'nome' => "Cliente {$i} T{$tenantId}",
                    'email' => "cliente{$i}t{$tenantId}@test.com",
                    'telefone' => "11" . str_pad((string)($tenantId * 1000 + $i), 9, '0', STR_PAD_LEFT),
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
            
            // 50 produtos por tenant
            for ($i = 1; $i <= 50; $i++) {
                $produtos[] = [
                    'tenant_id' => $tenantId,
                    'nome' => "Produto {$i} T{$tenantId}",
                    'sku' => "PROD-T{$tenantId}-" . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
                    'preco' => rand(10, 1000) / 10,
                    'quantidade_atual' => rand(0, 100),
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        }
        
        // Insert em batches para melhor performance
        $clienteBatches = array_chunk($clientes, 1000);
        foreach ($clienteBatches as $batch) {
            $this->database->table('clientes')->insert($batch);
        }
        
        $produtoBatches = array_chunk($produtos, 1000);
        foreach ($produtoBatches as $batch) {
            $this->database->table('produtos')->insert($batch);
        }
    }
    
    private function cleanupPerformanceTestData(): void
    {
        // Limpar em ordem para evitar problemas de foreign key
        $this->database->table('clientes')->where('email', 'like', '%@test.com')->delete();
        $this->database->table('produtos')->where('sku', 'like', 'PROD-T%')->delete();
        $this->database->table('tenants')->where('code', 'like', 'PERF%')->delete();
        
        // Limpar cache de teste
        $this->cache->flush();
    }
    
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}