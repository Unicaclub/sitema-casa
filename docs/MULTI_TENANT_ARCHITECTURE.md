# Arquitetura Multi-Tenant - ERP Sistema

## Índice
1. [Visão Geral](#visão-geral)
2. [Componentes Principais](#componentes-principais)
3. [Isolamento de Dados](#isolamento-de-dados)
4. [Padrões de Uso](#padrões-de-uso)
5. [Segurança](#segurança)
6. [Performance](#performance)
7. [Desenvolvimento](#desenvolvimento)
8. [Testes](#testes)
9. [Monitoramento](#monitoramento)
10. [Migração](#migração)

## Visão Geral

O sistema ERP implementa uma arquitetura **multi-tenant** onde múltiplas empresas (tenants) compartilham a mesma infraestrutura, mas mantêm isolamento completo de dados. Esta abordagem oferece:

- **Eficiência de Recursos**: Uma única instância serve múltiplos clientes
- **Isolamento de Dados**: Garantia de que dados de um tenant nunca vazam para outro
- **Escalabilidade**: Facilita adição de novos tenants sem alterações na infraestrutura
- **Manutenibilidade**: Atualizações centralizadas beneficiam todos os tenants

### Modelo de Tenancy

- **Tenant = Empresa**: Cada empresa é um tenant separado
- **Usuários pertencem a Tenants**: Cada usuário está associado a exatamente um tenant
- **Dados Isolados**: Todas as tabelas incluem `tenant_id` para isolamento
- **Contexto Automático**: Sistema automaticamente aplica filtros por tenant

## Componentes Principais

### 1. TenantManager (`src/Core/MultiTenant/TenantManager.php`)

Gerenciador central responsável por:

```php
// Definir contexto do tenant atual
$tenantManager->setCurrentTenant($tenantId);

// Obter tenant atual
$tenant = $tenantManager->getCurrentTenant();

// Validar acesso a recursos
$hasAccess = $tenantManager->validateOwnership('clientes', $clienteId);

// Gerar chaves de cache com isolamento
$cacheKey = $tenantManager->tenantCacheKey('user_preferences');
```

### 2. TenantMiddleware (`src/Core/Http/Middleware/TenantMiddleware.php`)

Middleware que intercepta todas as requisições para:

- Definir contexto do tenant baseado no usuário autenticado
- Validar tentativas de acesso cruzado entre tenants
- Detectar vazamento de dados nas respostas
- Registrar tentativas suspeitas de acesso

### 3. TenantScoped Trait (`src/Core/MultiTenant/TenantScoped.php`)

Trait que pode ser usado em models para aplicar escopo automático:

```php
class Cliente
{
    use TenantScoped;
    
    protected $tenantColumn = 'tenant_id';
}

// Automaticamente filtra por tenant atual
$clientes = Cliente::all();

// Query sem escopo (usar com cuidado)
$todosClientes = Cliente::withoutTenantScope()->get();
```

## Isolamento de Dados

### Estrutura de Banco de Dados

Todas as tabelas de negócio possuem coluna `tenant_id`:

```sql
-- Exemplo: Tabela de clientes
CREATE TABLE clientes (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    -- outros campos...
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    INDEX idx_clientes_tenant (tenant_id),
    UNIQUE KEY unique_email_tenant (email, tenant_id)
);
```

### Queries Seguras

**✅ CORRETO - Sempre inclui filtro por tenant:**
```php
$clientes = $database->table('clientes')
    ->where('tenant_id', $currentTenantId)
    ->where('ativo', true)
    ->get();
```

**❌ INCORRETO - Sem filtro por tenant:**
```php
$clientes = $database->table('clientes')
    ->where('ativo', true)
    ->get(); // VAZAMENTO DE DADOS!
```

### Escopo Automático

O `TenantManager` pode aplicar escopo automaticamente:

```php
$query = $database->table('clientes');
$tenantManager->scopeToTenant($query);
$clientes = $query->get(); // Automaticamente filtrado por tenant
```

## Padrões de Uso

### 1. Serviços de Negócio

```php
class ServicoCliente
{
    public function __construct(
        private TenantManager $tenantManager,
        private Database $database
    ) {}
    
    public function buscarClientes(?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantManager->getCurrentTenantId();
        
        if (!$tenantId) {
            throw new \InvalidArgumentException('Tenant não definido');
        }
        
        return $this->database->table('clientes')
            ->where('tenant_id', $tenantId)
            ->get();
    }
}
```

### 2. Controllers de API

```php
class ClientesController extends BaseController
{
    public function index(Request $request): Response
    {
        // Middleware já definiu contexto do tenant
        $clientes = $this->servicoCliente->buscarClientes();
        
        return $this->success($clientes);
    }
    
    public function show(Request $request, int $id): Response
    {
        // Validação automática de ownership
        if (!$this->tenantManager->validateOwnership('clientes', $id)) {
            return $this->error('Cliente não encontrado', 404);
        }
        
        $cliente = $this->servicoCliente->buscarPorId($id);
        return $this->success($cliente);
    }
}
```

### 3. Cache Multi-Tenant

```php
// Gerar chave de cache isolada por tenant
$cacheKey = $tenantManager->tenantCacheKey('dashboard_metrics');

// Cache automaticamente isolado
$metrics = $cache->remember($cacheKey, 3600, function() {
    return $this->calcularMetricas();
});

// Invalidar cache de um tenant específico
$tenantManager->invalidateTenantCache($tenantId);
```

## Segurança

### 1. Prevenção de Acesso Cruzado

O sistema implementa múltiplas camadas de proteção:

- **Middleware de Validação**: Intercepta tentativas de acesso
- **Validação de Parâmetros**: Verifica IDs em URLs e payloads
- **Validação de Responses**: Detecta vazamento de dados
- **Logging Auditoria**: Registra tentativas suspeitas

### 2. Validações Implementadas

```php
// Validação em parâmetros da requisição
if ($request->input('tenant_id') !== $userTenantId) {
    throw new \InvalidArgumentException('Access denied to tenant');
}

// Validação de propriedade de recursos
if (!$tenantManager->validateOwnership('produtos', $produtoId)) {
    throw new \InvalidArgumentException('Resource not found');
}

// Validação de resposta para vazamento
$this->validateResponse($response, $userTenantId);
```

### 3. Triggers de Banco de Dados

```sql
-- Trigger para validar tenant_id em inserções
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
END;
```

## Performance

### 1. Índices Otimizados

Todas as tabelas multi-tenant possuem índices compostos:

```sql
-- Índices para queries eficientes
CREATE INDEX idx_clientes_tenant_ativo ON clientes(tenant_id, ativo);
CREATE INDEX idx_vendas_tenant_data ON vendas(tenant_id, data_venda);
CREATE INDEX idx_produtos_tenant_categoria ON produtos(tenant_id, categoria_id);
```

### 2. Cache Estratégico

- **Tenant Context**: Cache do contexto do tenant por 1 hora
- **Tenant Stats**: Cache de estatísticas por 30 minutos  
- **Query Results**: Cache de resultados com chaves isoladas
- **Schema Cache**: Cache de metadados de tabelas por 24 horas

### 3. Views Materializadas

Para relatórios complexos, use views otimizadas:

```sql
CREATE VIEW vw_vendas_completas AS
SELECT 
    v.*,
    c.nome as cliente_nome,
    t.name as tenant_nome
FROM vendas v
LEFT JOIN clientes c ON v.cliente_id = c.id AND v.tenant_id = c.tenant_id
LEFT JOIN tenants t ON v.tenant_id = t.id;
```

## Desenvolvimento

### 1. Criando Novos Módulos

Ao criar um novo módulo, siga este checklist:

- [ ] Tabela inclui coluna `tenant_id`
- [ ] Foreign key para tabela `tenants`
- [ ] Índice composto com `tenant_id`
- [ ] Unique constraints incluem `tenant_id`
- [ ] Model usa trait `TenantScoped`
- [ ] Service valida contexto do tenant
- [ ] Controller aplica middleware
- [ ] Testes de isolamento implementados

### 2. Exemplo de Nova Tabela

```sql
CREATE TABLE nova_entidade (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    ativa BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    INDEX idx_nova_entidade_tenant (tenant_id),
    INDEX idx_nova_entidade_tenant_ativa (tenant_id, ativa),
    UNIQUE KEY unique_nome_tenant (nome, tenant_id)
);
```

### 3. Exemplo de Model

```php
<?php

namespace App\Models;

use Core\MultiTenant\TenantScoped;

class NovaEntidade
{
    use TenantScoped;
    
    protected $table = 'nova_entidade';
    protected $tenantColumn = 'tenant_id';
    
    protected $fillable = [
        'nome',
        'descricao', 
        'ativa'
    ];
}
```

## Testes

### 1. Testes de Isolamento

Execute a suíte completa de testes:

```bash
# Todos os testes multi-tenant
vendor/bin/phpunit tests/Feature/MultiTenant/

# Testes específicos de isolamento
vendor/bin/phpunit tests/Feature/MultiTenant/TenantIsolationTest.php

# Testes de performance
vendor/bin/phpunit tests/Performance/MultiTenantPerformanceTest.php
```

### 2. Exemplo de Teste

```php
public function test_tenant_data_isolation()
{
    // Criar dados para tenant 1
    $this->tenantManager->setCurrentTenant(1);
    $cliente1 = Cliente::create(['nome' => 'Cliente T1']);
    
    // Criar dados para tenant 2  
    $this->tenantManager->setCurrentTenant(2);
    $cliente2 = Cliente::create(['nome' => 'Cliente T2']);
    
    // Tenant 1 não deve ver dados do tenant 2
    $this->tenantManager->setCurrentTenant(1);
    $clientes = Cliente::all();
    
    $this->assertCount(1, $clientes);
    $this->assertEquals('Cliente T1', $clientes[0]->nome);
}
```

## Monitoramento

### 1. Métricas Importantes

- **Cross-tenant Access Attempts**: Tentativas de acesso cruzado
- **Query Performance por Tenant**: Tempo de resposta por tenant
- **Cache Hit Rate**: Taxa de acerto do cache isolado
- **Data Leakage Alerts**: Alertas de vazamento de dados

### 2. Logs de Auditoria

```php
// Exemplo de log de tentativa de acesso cruzado
$this->logger->critical('Cross-tenant access attempt', [
    'user_id' => $userId,
    'current_tenant_id' => $currentTenantId,
    'requested_tenant_id' => $requestedTenantId,
    'resource' => $resource,
    'ip_address' => $request->getClientIp(),
    'user_agent' => $request->getUserAgent()
]);
```

### 3. Alertas Configurados

- Mais de 5 tentativas de acesso cruzado por usuário/hora
- Queries sem filtro por tenant_id
- Tempo de resposta > 1s para queries multi-tenant
- Vazamento de dados detectado em responses

## Migração

### 1. Executar Migração Completa

```bash
# Backup do banco atual
mysqldump -u root -p erp_sistema > backup_pre_multitenant.sql

# Executar migração de padronização
mysql -u root -p erp_sistema < database/migrations/009_standardize_tenant_id.sql

# Verificar integridade dos dados
php artisan tenant:validate-data
```

### 2. Rollback (Se Necessário)

```sql
-- Script de rollback está incluído na migração
-- Restaura backup se necessário
mysql -u root -p erp_sistema < backup_pre_multitenant.sql
```

### 3. Validação Pós-Migração

```bash
# Executar testes de isolamento
composer test-multitenant

# Verificar performance
composer test-performance

# Validar integridade referencial
php artisan tenant:check-integrity
```

## Comandos Artisan

### 1. Comandos Disponíveis

```bash
# Inicializar sistema multi-tenant
php artisan tenant:setup

# Criar novo tenant
php artisan tenant:create "Nome da Empresa" codigo@empresa.com

# Validar isolamento de dados
php artisan tenant:validate-isolation

# Estatísticas por tenant
php artisan tenant:stats [tenant_id]

# Limpar cache de tenant específico
php artisan tenant:cache-clear [tenant_id]
```

### 2. Exemplo de Uso

```bash
# Criar tenant para nova empresa
php artisan tenant:create "Empresa XYZ Ltda" contato@empresaxyz.com.br

# Verificar se isolamento está funcionando
php artisan tenant:validate-isolation --tenant=5

# Obter estatísticas do tenant 5
php artisan tenant:stats 5
```

## Troubleshooting

### 1. Problemas Comuns

**Query sem tenant_id:**
```
ERROR: Tenant not defined for query scope
```
**Solução:** Certifique-se de que o contexto do tenant está definido antes da query.

**Cross-tenant access:**
```
ERROR: Resource not found or access denied  
```
**Solução:** Verifique se o usuário tem acesso ao tenant do recurso solicitado.

**Cache leakage:**
```
WARNING: Cache key without tenant isolation
```
**Solução:** Use `tenantCacheKey()` para gerar chaves isoladas.

### 2. Debug Mode

```php
// Ativar debug multi-tenant
config(['multitenant.debug' => true]);

// Logs detalhados de queries
config(['multitenant.log_queries' => true]);

// Validação strict de responses
config(['multitenant.strict_validation' => true]);
```

## Conclusão

A arquitetura multi-tenant implementada garante:

- ✅ **Isolamento Completo**: Dados nunca vazam entre tenants
- ✅ **Performance Otimizada**: Índices e cache adequados
- ✅ **Segurança Reforçada**: Múltiplas camadas de validação
- ✅ **Desenvolvimento Ágil**: Padrões claros e ferramentas automatizadas
- ✅ **Monitoramento Completo**: Métricas e alertas configurados
- ✅ **Testes Abrangentes**: Cobertura de casos de uso e edge cases

Para dúvidas ou suporte, consulte a documentação completa ou entre em contato com a equipe de desenvolvimento.