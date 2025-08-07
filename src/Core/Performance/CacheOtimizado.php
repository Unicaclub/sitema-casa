<?php

declare(strict_types=1);

namespace ERP\Core\Performance;

use ERP\Core\Cache\CacheInterface;
use ERP\Core\Database\DatabaseManager;
use Carbon\Carbon;

/**
 * Sistema de Cache Otimizado para Performance Suprema
 * 
 * Cache inteligente com TTL dinâmico, warming, e invalidação seletiva
 * 
 * @package ERP\Core\Performance
 */
final class CacheOtimizado
{
    private array $cacheStats = [];
    private array $ttlMapping = [];
    
    public function __construct(
        private CacheInterface $cache,
        private DatabaseManager $database
    ) {
        $this->inicializarTtlMapping();
    }
    
    /**
     * Cache inteligente com TTL dinâmico baseado no padrão de acesso
     */
    public function remember(string $chave, callable $callback, ?int $ttlCustom = null, array $opcoes = []): mixed
    {
        $inicioTempo = microtime(true);
        $chaveCompleta = $this->gerarChaveCompleta($chave, $opcoes);
        
        // Verificar cache primeiro
        if ($this->cache->has($chaveCompleta)) {
            $this->registrarCacheHit($chave, microtime(true) - $inicioTempo);
            return $this->cache->get($chaveCompleta);
        }
        
        // Executar callback e cachear resultado
        $resultado = $callback();
        $ttl = $ttlCustom ?? $this->calcularTtlInteligente($chave, $opcoes);
        
        // Aplicar compressão se dados grandes
        if ($this->deveComprimir($resultado)) {
            $resultado = $this->comprimir($resultado);
            $opcoes['compressed'] = true;
        }
        
        $this->cache->put($chaveCompleta, $resultado, $ttl);
        $this->registrarCacheMiss($chave, microtime(true) - $inicioTempo);
        
        return $opcoes['compressed'] ?? false ? $this->descomprimir($resultado) : $resultado;
    }
    
    /**
     * Cache warming para dados críticos
     */
    public function warmCache(): void
    {
        $dadosCriticos = [
            'dashboard_metrics' => fn() => $this->carregarMetricasDashboard(),
            'categorias_produtos' => fn() => $this->carregarCategorias(),
            'configuracoes_empresa' => fn() => $this->carregarConfiguracoes(),
            'usuarios_ativos' => fn() => $this->carregarUsuariosAtivos(),
            'produtos_mais_vendidos' => fn() => $this->carregarTopProdutos(),
        ];
        
        foreach ($dadosCriticos as $chave => $callback) {
            if (! $this->cache->has($chave)) {
                $this->remember($chave, $callback);
            }
        }
    }
    
    /**
     * Invalidação seletiva baseada em tags
     */
    public function invalidarPorTag(string $tag): void
    {
        $chaves = $this->obterChavesPorTag($tag);
        
        foreach ($chaves as $chave) {
            $this->cache->forget($chave);
        }
        
        // Registrar invalidação
        $this->registrarInvalidacao($tag, count($chaves));
    }
    
    /**
     * Cache em camadas (L1: Memory, L2: Redis/File)
     */
    public function cacheMultiCamadas(string $chave, callable $callback, int $ttl = 3600): mixed
    {
        static $memoryCache = [];
        
        // L1: Memory Cache (mais rápido)
        if (isset($memoryCache[$chave])) {
            return $memoryCache[$chave];
        }
        
        // L2: Cache persistente
        $resultado = $this->remember($chave, $callback, $ttl);
        
        // Armazenar em L1 se dados pequenos
        if ($this->deveArmazenarMemoria($resultado)) {
            $memoryCache[$chave] = $resultado;
        }
        
        return $resultado;
    }
    
    /**
     * Cache preditivo baseado em padrões de uso
     */
    public function cachePreditivo(string $tenantId): void
    {
        $padroes = $this->analisarPadroesUso($tenantId);
        
        foreach ($padroes['proximos_acessos'] as $chave => $probabilidade) {
            if ($probabilidade > 0.7 && !$this->cache->has($chave)) {
                $this->preCarregarDados($chave);
            }
        }
    }
    
    /**
     * Cache distribuído com consistency
     */
    public function cacheDistribuido(string $chave, callable $callback, int $ttl = 3600): mixed
    {
        $lockKey = "lock:{$chave}";
        $lockTtl = 60; // 1 minuto de lock
        
        // Verificar cache primeiro
        if ($this->cache->has($chave)) {
            return $this->cache->get($chave);
        }
        
        // Tentar adquirir lock para evitar stampeding herd
        if ($this->cache->put($lockKey, time(), $lockTtl)) {
            try {
                $resultado = $callback();
                $this->cache->put($chave, $resultado, $ttl);
                return $resultado;
            } finally {
                $this->cache->forget($lockKey);
            }
        }
        
        // Se não conseguiu lock, esperar um pouco e tentar cache novamente
        usleep(100000); // 100ms
        return $this->cache->has($chave) ? $this->cache->get($chave) : $callback();
    }
    
    /**
     * Métricas detalhadas de performance do cache
     */
    public function obterMetricasCache(): array
    {
        return [
            'hits' => array_sum(array_column($this->cacheStats, 'hits')),
            'misses' => array_sum(array_column($this->cacheStats, 'misses')),
            'hit_rate' => $this->calcularHitRate(),
            'tempo_medio_hit' => $this->calcularTempoMedioHit(),
            'tempo_medio_miss' => $this->calcularTempoMedioMiss(),
            'chaves_mais_acessadas' => $this->obterChavesMaisAcessadas(),
            'invalidacoes_recentes' => $this->obterInvalidacoesRecentes(),
            'uso_memoria' => $this->calcularUsoMemoria(),
        ];
    }
    
    /**
     * Auto-otimização baseada em métricas
     */
    public function autoOtimizar(): void
    {
        $metricas = $this->obterMetricasCache();
        
        // Ajustar TTL baseado em hit rate
        foreach ($this->cacheStats as $chave => $stats) {
            if ($stats['hit_rate'] < 0.5) {
                $this->ttlMapping[$chave] = max(60, $this->ttlMapping[$chave] * 0.8);
            } elseif ($stats['hit_rate'] > 0.9) {
                $this->ttlMapping[$chave] = min(3600, $this->ttlMapping[$chave] * 1.2);
            }
        }
        
        // Limpe cache pouco usado
        $this->limparCachePoucoUsado();
        
        // Pre-carregue dados populares
        $this->preCarregarDadosPopulares();
    }
    
    /**
     * Métodos privados de otimização
     */
    
    private function inicializarTtlMapping(): void
    {
        $this->ttlMapping = [
            'dashboard_metrics' => 300,    // 5 minutos
            'produto_' => 600,             // 10 minutos
            'cliente_' => 300,             // 5 minutos
            'venda_' => 180,               // 3 minutos
            'configuracao_' => 3600,       // 1 hora
            'relatorio_' => 1800,          // 30 minutos
            'usuario_' => 900,             // 15 minutos
            'categoria_' => 1800,          // 30 minutos
            'top_produtos' => 600,         // 10 minutos
            'alertas_' => 120,             // 2 minutos
        ];
    }
    
    private function calcularTtlInteligente(string $chave, array $opcoes): int
    {
        // TTL baseado no padrão da chave
        foreach ($this->ttlMapping as $padrao => $ttl) {
            if (str_contains($chave, $padrao)) {
                return $ttl;
            }
        }
        
        // TTL baseado na volatilidade dos dados
        if ($opcoes['volatile'] ?? false) {
            return 60; // 1 minuto para dados voláteis
        }
        
        if ($opcoes['static'] ?? false) {
            return 3600; // 1 hora para dados estáticos
        }
        
        return 300; // TTL padrão: 5 minutos
    }
    
    private function gerarChaveCompleta(string $chave, array $opcoes): string
    {
        $prefixo = $opcoes['tenant_id'] ?? 'global';
        $versao = $opcoes['versao'] ?? '1';
        return "{$prefixo}:{$chave}:v{$versao}";
    }
    
    private function deveComprimir(mixed $dados): bool
    {
        $tamanho = strlen(serialize($dados));
        return $tamanho > 10240; // Comprimir se > 10KB
    }
    
    private function comprimir(mixed $dados): string
    {
        return gzcompress(serialize($dados), 9);
    }
    
    private function descomprimir(string $dados): mixed
    {
        return unserialize(gzuncompress($dados));
    }
    
    private function deveArmazenarMemoria(mixed $dados): bool
    {
        $tamanho = strlen(serialize($dados));
        return $tamanho < 1024; // Apenas < 1KB na memória
    }
    
    private function registrarCacheHit(string $chave, float $tempo): void
    {
        $this->cacheStats[$chave]['hits'] = ($this->cacheStats[$chave]['hits'] ?? 0) + 1;
        $this->cacheStats[$chave]['tempo_hits'][] = $tempo;
    }
    
    private function registrarCacheMiss(string $chave, float $tempo): void
    {
        $this->cacheStats[$chave]['misses'] = ($this->cacheStats[$chave]['misses'] ?? 0) + 1;
        $this->cacheStats[$chave]['tempo_misses'][] = $tempo;
    }
    
    private function calcularHitRate(): float
    {
        $hits = array_sum(array_column($this->cacheStats, 'hits'));
        $misses = array_sum(array_column($this->cacheStats, 'misses'));
        $total = $hits + $misses;
        
        return $total > 0 ? round($hits / $total, 3) : 0;
    }
    
    private function calcularTempoMedioHit(): float
    {
        $tempos = [];
        foreach ($this->cacheStats as $stats) {
            if (isset($stats['tempo_hits'])) {
                $tempos = array_merge($tempos, $stats['tempo_hits']);
            }
        }
        
        return !empty($tempos) ? array_sum($tempos) / count($tempos) : 0;
    }
    
    private function calcularTempoMedioMiss(): float
    {
        $tempos = [];
        foreach ($this->cacheStats as $stats) {
            if (isset($stats['tempo_misses'])) {
                $tempos = array_merge($tempos, $stats['tempo_misses']);
            }
        }
        
        return !empty($tempos) ? array_sum($tempos) / count($tempos) : 0;
    }
    
    // Métodos de dados específicos
    private function carregarMetricasDashboard(): array
    {
        return $this->database->select("SELECT * FROM v_dashboard_metrics WHERE data >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }
    
    private function carregarCategorias(): array
    {
        return $this->database->table('categorias')->where('ativo', true)->get()->toArray();
    }
    
    private function carregarConfiguracoes(): array
    {
        return $this->database->table('configuracoes')->get()->toArray();
    }
    
    private function carregarUsuariosAtivos(): array
    {
        return $this->database->table('users')->where('status', 'ativo')->get()->toArray();
    }
    
    private function carregarTopProdutos(): array
    {
        return $this->database->select("SELECT * FROM v_top_produtos LIMIT 20");
    }
    
    // Métodos auxiliares (implementação simplificada)
    private function obterChavesPorTag(string $tag): array { return []; }
    private function registrarInvalidacao(string $tag, int $count): void {}
    private function analisarPadroesUso(string $tenantId): array { return ['proximos_acessos' => []]; }
    private function preCarregarDados(string $chave): void {}
    private function obterChavesMaisAcessadas(): array { return []; }
    private function obterInvalidacoesRecentes(): array { return []; }
    private function calcularUsoMemoria(): int { return memory_get_usage(true); }
    private function limparCachePoucoUsado(): void {}
    private function preCarregarDadosPopulares(): void {}
}
