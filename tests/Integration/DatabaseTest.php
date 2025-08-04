<?php

namespace ERP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ERP\Core\Database;

/**
 * Testes de integração com banco de dados
 */
class DatabaseTest extends TestCase
{
    private Database $database;
    
    protected function setUp(): void
    {
        $config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_TEST_DATABASE'] ?? 'erp_test',
            'username' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4'
        ];
        
        $this->database = new Database($config);
        $this->setupTestData();
    }
    
    protected function setupTestData(): void
    {
        // Criar tabela de teste
        $this->database->execute("
            CREATE TABLE IF NOT EXISTS test_companies (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                code VARCHAR(50) UNIQUE NOT NULL,
                active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Limpar dados anteriores
        $this->database->execute("TRUNCATE TABLE test_companies");
    }
    
    public function testDatabaseConnection(): void
    {
        $result = $this->database->first("SELECT 1 as test");
        
        $this->assertEquals(1, $result['test']);
    }
    
    public function testInsertAndSelect(): void
    {
        // Insert
        $companyId = $this->database->insert("
            INSERT INTO test_companies (name, code) 
            VALUES (?, ?)
        ", ['Empresa Teste', 'TESTE001']);
        
        $this->assertIsInt($companyId);
        $this->assertGreaterThan(0, $companyId);
        
        // Select
        $company = $this->database->first("
            SELECT * FROM test_companies WHERE id = ?
        ", [$companyId]);
        
        $this->assertNotNull($company);
        $this->assertEquals('Empresa Teste', $company['name']);
        $this->assertEquals('TESTE001', $company['code']);
    }
    
    public function testUpdate(): void
    {
        // Insert primeiro
        $companyId = $this->database->insert("
            INSERT INTO test_companies (name, code) 
            VALUES (?, ?)
        ", ['Empresa Original', 'ORIG001']);
        
        // Update
        $affected = $this->database->execute("
            UPDATE test_companies 
            SET name = ?, active = ? 
            WHERE id = ?
        ", ['Empresa Atualizada', false, $companyId]);
        
        $this->assertEquals(1, $affected);
        
        // Verificar mudança
        $company = $this->database->first("
            SELECT * FROM test_companies WHERE id = ?
        ", [$companyId]);
        
        $this->assertEquals('Empresa Atualizada', $company['name']);
        $this->assertEquals(0, $company['active']);
    }
    
    public function testSelectMultiple(): void
    {
        // Insert multiple
        $this->database->insert("
            INSERT INTO test_companies (name, code) VALUES (?, ?)
        ", ['Empresa 1', 'EMP001']);
        
        $this->database->insert("
            INSERT INTO test_companies (name, code) VALUES (?, ?)
        ", ['Empresa 2', 'EMP002']);
        
        // Select all
        $companies = $this->database->select("
            SELECT * FROM test_companies ORDER BY name
        ");
        
        $this->assertIsArray($companies);
        $this->assertCount(2, $companies);
        $this->assertEquals('Empresa 1', $companies[0]['name']);
        $this->assertEquals('Empresa 2', $companies[1]['name']);
    }
    
    public function testTransaction(): void
    {
        $this->database->beginTransaction();
        
        try {
            $id1 = $this->database->insert("
                INSERT INTO test_companies (name, code) VALUES (?, ?)
            ", ['Transação 1', 'TRANS001']);
            
            $id2 = $this->database->insert("
                INSERT INTO test_companies (name, code) VALUES (?, ?)
            ", ['Transação 2', 'TRANS002']);
            
            $this->database->commit();
            
            // Verificar se ambos foram inseridos
            $count = $this->database->first("
                SELECT COUNT(*) as total FROM test_companies 
                WHERE code IN ('TRANS001', 'TRANS002')
            ");
            
            $this->assertEquals(2, $count['total']);
            
        } catch (\Exception $e) {
            $this->database->rollback();
            throw $e;
        }
    }
    
    public function testTransactionRollback(): void
    {
        $this->database->beginTransaction();
        
        try {
            $this->database->insert("
                INSERT INTO test_companies (name, code) VALUES (?, ?)
            ", ['Rollback Test', 'ROLL001']);
            
            // Forçar erro com código duplicado
            $this->database->insert("
                INSERT INTO test_companies (name, code) VALUES (?, ?)
            ", ['Rollback Test 2', 'ROLL001']);
            
            $this->database->commit();
            
        } catch (\Exception $e) {
            $this->database->rollback();
            
            // Verificar se rollback funcionou
            $count = $this->database->first("
                SELECT COUNT(*) as total FROM test_companies 
                WHERE code = 'ROLL001'
            ");
            
            $this->assertEquals(0, $count['total']);
        }
    }
    
    protected function tearDown(): void
    {
        $this->database->execute("DROP TABLE IF EXISTS test_companies");
    }
}