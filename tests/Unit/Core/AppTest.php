<?php

namespace ERP\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ERP\Core\App;

/**
 * Testes para a classe principal App
 */
class AppTest extends TestCase
{
    private App $app;
    
    protected function setUp(): void
    {
        $this->app = App::getInstance();
    }
    
    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = App::getInstance();
        $instance2 = App::getInstance();
        
        $this->assertSame($instance1, $instance2);
    }
    
    public function testConfigReturnsExpectedValues(): void
    {
        $config = $this->app->config();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('database', $config);
        $this->assertArrayHasKey('auth', $config);
        $this->assertArrayHasKey('cache', $config);
    }
    
    public function testConfigWithKeyReturnsSpecificValue(): void
    {
        $dbConfig = $this->app->config('database');
        
        $this->assertIsArray($dbConfig);
    }
    
    public function testConfigWithInvalidKeyReturnsNull(): void
    {
        $result = $this->app->config('invalid.key');
        
        $this->assertNull($result);
    }
    
    public function testGetServiceFromContainer(): void
    {
        $logger = $this->app->get('logger');
        
        $this->assertInstanceOf(\ERP\Core\Logger::class, $logger);
    }
    
    public function testGetModulesReturnsArray(): void
    {
        $modules = $this->app->getModules();
        
        $this->assertIsArray($modules);
    }
}