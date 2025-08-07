<?php

declare(strict_types=1);

namespace ERP\Core\Performance;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Cache\CacheInterface;

/**
 * Sistema de Lazy Loading Avançado
 * 
 * Carregamento inteligente e sob demanda para performance suprema
 * 
 * @package ERP\Core\Performance
 */
final class LazyLoader
{
    private array $loadedData = [];
    private array $loadingQueue = [];
    private array $relationshipMap = [];
    private array $accessPatterns = [];
    private array $preloadStrategies = [];
    
    public function __construct(
        private DatabaseManager $database,
        private CacheInterface $cache
    ) {
        $this->inicializarRelationshipMap();
        $this->inicializarPreloadStrategies();
    }
    
    /**
     * Lazy loading com preload inteligente
     */
    public function load(string $type, string|int $id, array $options = []): \Closure
    {
        $cacheKey = "{$type}:{$id}";
        
        return function () use ($type, $id, $cacheKey, $options) {
            // Verificar cache de dados já carregados
            if (isset($this->loadedData[$cacheKey])) {
                $this->registrarAcesso($cacheKey);
                return $this->loadedData[$cacheKey];
            }
            
            // Verificar cache persistente
            if ($this->cache->has($cacheKey)) {
                $data = $this->cache->get($cacheKey);
                $this->loadedData[$cacheKey] = $data;
                $this->registrarAcesso($cacheKey);
                return $data;
            }
            
            // Carregar dados
            $data = $this->carregarDados($type, $id, $options);
            
            // Armazenar em caches
            $this->loadedData[$cacheKey] = $data;
            $this->cache->put($cacheKey, $data, $this->obterTtlParaTipo($type));
            
            // Registrar acesso e iniciar preload
            $this->registrarAcesso($cacheKey);
            $this->adicionarPreloadQueue($type, $id, $data);
            
            return $data;
        };
    }
    
    /**
     * Lazy loading de relacionamentos
     */
    public function loadRelation(string $parentType, string|int $parentId, string $relation): \Closure
    {
        $cacheKey = "{$parentType}:{$parentId}:relation:{$relation}";
        
        return function () use ($parentType, $parentId, $relation, $cacheKey) {
            if (isset($this->loadedData[$cacheKey])) {
                return $this->loadedData[$cacheKey];
            }
            
            if ($this->cache->has($cacheKey)) {
                $data = $this->cache->get($cacheKey);
                $this->loadedData[$cacheKey] = $data;
                return $data;
            }
            
            $data = $this->carregarRelacionamento($parentType, $parentId, $relation);
            
            $this->loadedData[$cacheKey] = $data;
            $this->cache->put($cacheKey, $data, $this->obterTtlParaTipo($relation));
            
            return $data;
        };
    }
    
    /**
     * Lazy loading de coleções com paginação
     */
    public function loadCollection(string $type, array $filters = [], int $page = 1, int $limit = 20): \Closure
    {
        $filterHash = md5(serialize($filters));
        $cacheKey = "{$type}:collection:{$filterHash}:page:{$page}:limit:{$limit}";
        
        return function () use ($type, $filters, $page, $limit, $cacheKey) {
            if (isset($this->loadedData[$cacheKey])) {
                return $this->loadedData[$cacheKey];
            }
            
            if ($this->cache->has($cacheKey)) {
                $data = $this->cache->get($cacheKey);
                $this->loadedData[$cacheKey] = $data;
                return $data;
            }
            
            $data = $this->carregarColecao($type, $filters, $page, $limit);
            
            // Cachear itens individuais também
            foreach ($data['items'] as $item) {
                $itemKey = "{$type}:{$item['id']}";
                $this->loadedData[$itemKey] = $item;
                $this->cache->put($itemKey, $item, $this->obterTtlParaTipo($type));
            }
            
            $this->loadedData[$cacheKey] = $data;
            $this->cache->put($cacheKey, $data, 300); // 5 minutos para coleções
            
            return $data;
        };
    }
    
    /**
     * Preload estratégico baseado em padrões de uso
     */
    public function preloadStrategy(string $strategy, array $context = []): void
    {
        if (! isset($this->preloadStrategies[$strategy])) {
            return;
        }
        
        $strategyConfig = $this->preloadStrategies[$strategy];
        
        foreach ($strategyConfig['items'] as $item) {
            $this->adicionarFilaPreload($item['type'], $item['id'] ?? null, $context);
        }
        
        // Executar preload em background se possível
        $this->executarPreloadBackground();
    }
    
    /**
     * Preload de dados relacionados baseado em probabilidade
     */
    public function preloadRelated(string $type, string|int $id): void
    {
        if (! isset($this->relationshipMap[$type])) {
            return;
        }
        
        $relations = $this->relationshipMap[$type];
        $patterns = $this->analisarPadroesAcesso($type, $id);
        
        foreach ($relations as $relation => $config) {
            $probabilidade = $patterns[$relation] ?? 0;
            
            // Preload se probabilidade > 60%
            if ($probabilidade > 0.6) {
                $this->adicionarFilaPreload($relation, null, ['parent_type' => $type, 'parent_id' => $id]);
            }
        }
    }
    
    /**
     * Invalidação seletiva de cache lazy
     */
    public function invalidate(string $type, string|int $id = null): void
    {
        if ($id !== null) {
            // Invalidar item específico
            $cacheKey = "{$type}:{$id}";
            unset($this->loadedData[$cacheKey]);
            $this->cache->forget($cacheKey);
            
            // Invalidar relacionamentos
            $this->invalidarRelacionamentos($type, $id);
        } else {
            // Invalidar todos os itens do tipo
            $pattern = "{$type}:";
            foreach ($this->loadedData as $key => $value) {
                if (str_starts_with($key, $pattern)) {
                    unset($this->loadedData[$key]);
                    $this->cache->forget($key);
                }
            }
        }
        
        // Invalidar coleções relacionadas
        $this->invalidarColecoes($type);
    }
    
    /**
     * Otimização automática baseada nos padrões de acesso
     */
    public function otimizarAutomaticamente(): void
    {
        $analise = $this->analisarPadroes();
        
        // Ajustar estratégias de preload
        foreach ($analise['frequent_patterns'] as $pattern => $frequency) {
            if ($frequency > 10) { // Mais de 10 acessos
                $this->criarEstrategiaPreload($pattern);
            }
        }
        
        // Limpar dados pouco acessados da memória
        $this->limparDadosPoucoAcessados();
        
        // Ajustar TTLs baseado no uso
        $this->ajustarTtlsDinamicamente();
    }
    
    /**
     * Relatório de performance do lazy loading
     */
    public function gerarRelatorioPerformance(): array
    {
        return [
            'dados_carregados' => count($this->loadedData),
            'fila_preload' => count($this->loadingQueue),
            'padroes_acesso' => $this->analisarPadroes(),
            'hit_rate_memoria' => $this->calcularHitRateMemoria(),
            'estrategias_ativas' => count($this->preloadStrategies),
            'uso_memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'recomendacoes' => $this->gerarRecomendacoes()
        ];
    }
    
    /**
     * Limpeza de memória do lazy loader
     */
    public function limparMemoria(): void
    {
        // Manter apenas dados acessados recentemente (últimos 5 minutos)
        $tempoLimite = time() - 300;
        
        foreach ($this->accessPatterns as $key => $pattern) {
            if ($pattern['last_access'] < $tempoLimite) {
                unset($this->loadedData[$key]);
                unset($this->accessPatterns[$key]);
            }
        }
        
        // Limpar fila de preload antigas
        $this->loadingQueue = array_filter($this->loadingQueue, function($item) {
            return $item['timestamp'] > (time() - 60); // Manter apenas últimos 60 segundos
        });
    }
    
    /**
     * Métodos privados
     */
    
    private function inicializarRelationshipMap(): void
    {
        $this->relationshipMap = [
            'produto' => [
                'categoria' => ['type' => 'belongs_to', 'probability' => 0.8],
                'fornecedor' => ['type' => 'belongs_to', 'probability' => 0.6],
                'vendas_itens' => ['type' => 'has_many', 'probability' => 0.4],
                'movimentacoes' => ['type' => 'has_many', 'probability' => 0.3]
            ],
            'venda' => [
                'cliente' => ['type' => 'belongs_to', 'probability' => 0.9],
                'vendedor' => ['type' => 'belongs_to', 'probability' => 0.7],
                'itens' => ['type' => 'has_many', 'probability' => 0.8],
                'pagamentos' => ['type' => 'has_many', 'probability' => 0.6]
            ],
            'cliente' => [
                'endereco' => ['type' => 'has_one', 'probability' => 0.7],
                'vendas' => ['type' => 'has_many', 'probability' => 0.5],
                'contatos' => ['type' => 'has_many', 'probability' => 0.4]
            ]
        ];
    }
    
    private function inicializarPreloadStrategies(): void
    {
        $this->preloadStrategies = [
            'dashboard_load' => [
                'description' => 'Dados para dashboard',
                'items' => [
                    ['type' => 'metrics', 'id' => 'current'],
                    ['type' => 'alerts', 'id' => 'active'],
                    ['type' => 'top_products', 'id' => 'monthly']
                ]
            ],
            'product_view' => [
                'description' => 'Visualização de produto',
                'items' => [
                    ['type' => 'categoria'],
                    ['type' => 'fornecedor'],
                    ['type' => 'estoque_movimentacoes']
                ]
            ],
            'sale_process' => [
                'description' => 'Processo de venda',
                'items' => [
                    ['type' => 'cliente'],
                    ['type' => 'produtos_ativos'],
                    ['type' => 'formas_pagamento']
                ]
            ]
        ];
    }
    
    private function carregarDados(string $type, string|int $id, array $options): array
    {
        $tabela = $this->obterTabelaParaTipo($type);
        
        $query = "SELECT * FROM {$tabela} WHERE id = ?";
        $params = [$id];
        
        // Aplicar filtros tenant se necessário
        if ($options['tenant_id'] ?? null) {
            $query .= " AND tenant_id = ?";
            $params[] = $options['tenant_id'];
        }
        
        $resultado = $this->database->select($query, $params);
        
        return $resultado[0] ?? [];
    }
    
    private function carregarRelacionamento(string $parentType, string|int $parentId, string $relation): array
    {
        $config = $this->relationshipMap[$parentType][$relation] ?? null;
        
        if (! $config) {
            return [];
        }
        
        switch ($config['type']) {
            case 'belongs_to':
                return $this->carregarBelongsTo($parentType, $parentId, $relation);
                
            case 'has_one':
                return $this->carregarHasOne($parentType, $parentId, $relation);
                
            case 'has_many':
                return $this->carregarHasMany($parentType, $parentId, $relation);
                
            default:
                return [];
        }
    }
    
    private function carregarColecao(string $type, array $filters, int $page, int $limit): array
    {
        $tabela = $this->obterTabelaParaTipo($type);
        $offset = ($page - 1) * $limit;
        
        // Construir WHERE clause
        $whereConditions = [];
        $params = [];
        
        foreach ($filters as $campo => $valor) {
            $whereConditions[] = "{$campo} = ?";
            $params[] = $valor;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Query para itens
        $queryItens = "SELECT * FROM {$tabela} {$whereClause} ORDER BY id DESC LIMIT ? OFFSET ?";
        $paramsItens = array_merge($params, [$limit, $offset]);
        
        // Query para total
        $queryTotal = "SELECT COUNT(*) as total FROM {$tabela} {$whereClause}";
        
        $itens = $this->database->select($queryItens, $paramsItens);
        $total = $this->database->select($queryTotal, $params);
        
        return [
            'items' => $itens,
            'total' => $total[0]['total'] ?? 0,
            'page' => $page,
            'limit' => $limit,
            'has_more' => count($itens) === $limit
        ];
    }
    
    private function registrarAcesso(string $cacheKey): void
    {
        $this->accessPatterns[$cacheKey] = [
            'count' => ($this->accessPatterns[$cacheKey]['count'] ?? 0) + 1,
            'last_access' => time(),
            'first_access' => $this->accessPatterns[$cacheKey]['first_access'] ?? time()
        ];
    }
    
    private function adicionarPreloadQueue(string $type, string|int $id, array $data): void
    {
        // Adicionar relacionamentos prováveis à fila
        if (isset($this->relationshipMap[$type])) {
            foreach ($this->relationshipMap[$type] as $relation => $config) {
                if ($config['probability'] > 0.7) { // Alta probabilidade
                    $this->loadingQueue[] = [
                        'type' => 'relation',
                        'parent_type' => $type,
                        'parent_id' => $id,
                        'relation' => $relation,
                        'timestamp' => time()
                    ];
                }
            }
        }
    }
    
    private function obterTtlParaTipo(string $type): int
    {
        return match($type) {
            'produto' => 600, // 10 minutos
            'cliente' => 300, // 5 minutos
            'venda' => 180,   // 3 minutos
            'categoria' => 1800, // 30 minutos
            'configuracao' => 3600, // 1 hora
            default => 300 // 5 minutos padrão
        };
    }
    
    private function obterTabelaParaTipo(string $type): string
    {
        return match($type) {
            'produto' => 'produtos',
            'cliente' => 'clientes',
            'venda' => 'vendas',
            'categoria' => 'categorias',
            'fornecedor' => 'fornecedores',
            default => $type . 's'
        };
    }
    
    private function analisarPadroesAcesso(string $type, string|int $id): array
    {
        $padroes = [];
        $baseKey = "{$type}:{$id}";
        
        foreach ($this->accessPatterns as $key => $pattern) {
            if (str_starts_with($key, $baseKey . ':relation:')) {
                $relation = str_replace($baseKey . ':relation:', '', $key);
                $padroes[$relation] = min(1.0, $pattern['count'] / 10); // Normalizar para 0-1
            }
        }
        
        return $padroes;
    }
    
    private function analisarPadroes(): array
    {
        $patterns = [];
        $frequentPatterns = [];
        
        foreach ($this->accessPatterns as $key => $data) {
            $parts = explode(':', $key);
            $type = $parts[0] ?? 'unknown';
            
            $patterns[$type] = ($patterns[$type] ?? 0) + $data['count'];
            
            if ($data['count'] > 5) {
                $frequentPatterns[$key] = $data['count'];
            }
        }
        
        return [
            'type_access_count' => $patterns,
            'frequent_patterns' => $frequentPatterns,
            'total_accesses' => array_sum(array_column($this->accessPatterns, 'count'))
        ];
    }
    
    // Métodos auxiliares simplificados
    private function invalidarRelacionamentos(string $type, string|int $id): void
    {
        $pattern = "{$type}:{$id}:relation:";
        foreach (array_keys($this->loadedData) as $key) {
            if (str_starts_with($key, $pattern)) {
                unset($this->loadedData[$key]);
                $this->cache->forget($key);
            }
        }
    }
    
    private function invalidarColecoes(string $type): void
    {
        $pattern = "{$type}:collection:";
        foreach (array_keys($this->loadedData) as $key) {
            if (str_starts_with($key, $pattern)) {
                unset($this->loadedData[$key]);
                $this->cache->forget($key);
            }
        }
    }
    
    private function carregarBelongsTo(string $parentType, string|int $parentId, string $relation): array
    {
        // Implementação simplificada
        return [];
    }
    
    private function carregarHasOne(string $parentType, string|int $parentId, string $relation): array
    {
        // Implementação simplificada
        return [];
    }
    
    private function carregarHasMany(string $parentType, string|int $parentId, string $relation): array
    {
        // Implementação simplificada
        return [];
    }
    
    private function adicionarFilaPreload(string $type, ?string $id, array $context): void
    {
        $this->loadingQueue[] = [
            'type' => $type,
            'id' => $id,
            'context' => $context,
            'timestamp' => time()
        ];
    }
    
    private function executarPreloadBackground(): void
    {
        // Implementação básica - em produção usar queue system
        foreach (array_slice($this->loadingQueue, 0, 5) as $item) {
            if ($item['type'] && $item['id']) {
                $loader = $this->load($item['type'], $item['id']);
                $loader(); // Executar lazy loader
            }
        }
    }
    
    private function criarEstrategiaPreload(string $pattern): void
    {
        // Implementação simplificada
    }
    
    private function limparDadosPoucoAcessados(): void
    {
        $tempoLimite = time() - 600; // 10 minutos
        
        foreach ($this->accessPatterns as $key => $pattern) {
            if ($pattern['last_access'] < $tempoLimite && $pattern['count'] < 3) {
                unset($this->loadedData[$key]);
                unset($this->accessPatterns[$key]);
            }
        }
    }
    
    private function ajustarTtlsDinamicamente(): void
    {
        // Implementação simplificada
    }
    
    private function calcularHitRateMemoria(): float
    {
        $totalAccesses = array_sum(array_column($this->accessPatterns, 'count'));
        $memoryHits = count($this->loadedData);
        
        return $totalAccesses > 0 ? round($memoryHits / $totalAccesses, 3) : 0;
    }
    
    private function gerarRecomendacoes(): array
    {
        $recomendacoes = [];
        
        if (count($this->loadedData) > 1000) {
            $recomendacoes[] = 'Muitos dados em memória - implementar limpeza mais agressiva';
        }
        
        if (count($this->loadingQueue) > 100) {
            $recomendacoes[] = 'Fila de preload muito grande - otimizar estratégias';
        }
        
        return $recomendacoes;
    }
}
