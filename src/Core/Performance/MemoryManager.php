<?php

declare(strict_types=1);

namespace ERP\Core\Performance;

/**
 * Gerenciador de Memória Avançado
 * 
 * Otimização suprema de uso de memória com garbage collection inteligente
 * 
 * @package ERP\Core\Performance
 */
final class MemoryManager
{
    private array $poolObjetos = [];
    private array $estatisticasMemoria = [];
    private int $limiteMemoria;
    private float $limiteCritico = 0.85; // 85% da memória disponível
    
    public function __construct()
    {
        $this->limiteMemoria = $this->obterLimiteMemoria();
        $this->iniciarMonitoramento();
    }
    
    /**
     * Pool de objetos reutilizáveis para evitar criações desnecessárias
     */
    public function obterObjetoDoPool(string $classe, array $params = []): object
    {
        $chave = $classe . '_' . md5(serialize($params));
        
        if (isset($this->poolObjetos[$chave]) && !empty($this->poolObjetos[$chave])) {
            $objeto = array_pop($this->poolObjetos[$chave]);
            $this->resetarObjeto($objeto);
            return $objeto;
        }
        
        // Criar novo objeto se pool vazio
        return new $classe(...$params);
    }
    
    /**
     * Retornar objeto para o pool
     */
    public function retornarObjetoParaPool(object $objeto, string $classe): void
    {
        $chave = $classe . '_' . md5('');
        
        if (! isset($this->poolObjetos[$chave])) {
            $this->poolObjetos[$chave] = [];
        }
        
        // Limitar tamanho do pool
        if (count($this->poolObjetos[$chave]) < 10) {
            $this->poolObjetos[$chave][] = $objeto;
        }
    }
    
    /**
     * Otimização de arrays grandes com streaming
     */
    public function processarArrayOtimizado(array $dados, callable $processador): \Generator
    {
        $tamanhoChunk = $this->calcularTamanhoChunkOtimo(count($dados));
        
        for ($i = 0; $i < count($dados); $i += $tamanhoChunk) {
            $chunk = array_slice($dados, $i, $tamanhoChunk);
            
            yield $processador($chunk);
            
            // Forçar garbage collection se necessário
            if ($this->memoriaProximaLimite()) {
                $this->executarGarbageCollection();
            }
        }
    }
    
    /**
     * Cache inteligente com compressão automática
     */
    public function cacheInteligente(string $chave, mixed $dados): void
    {
        $tamanho = strlen(serialize($dados));
        
        // Aplicar compressão se dados grandes
        if ($tamanho > 10240) { // 10KB
            $dados = gzcompress(serialize($dados), 9);
            $chave .= '_compressed';
        }
        
        // Cache em diferentes níveis baseado no tamanho
        if ($tamanho < 1024) { // < 1KB - memory cache
            static $memoryCache = [];
            $memoryCache[$chave] = $dados;
        } else { // > 1KB - file cache ou redis
            file_put_contents(
                sys_get_temp_dir() . '/cache_' . md5($chave),
                serialize($dados)
            );
        }
    }
    
    /**
     * Lazy loading avançado com preload inteligente
     */
    public function lazyLoader(string $identificador, callable $carregador): callable
    {
        static $cache = [];
        static $preloadQueue = [];
        
        return function () use ($identificador, $carregador, &$cache, &$preloadQueue) {
            // Verificar cache primeiro
            if (isset($cache[$identificador])) {
                return $cache[$identificador];
            }
            
            // Carregar dados
            $dados = $carregador();
            $cache[$identificador] = $dados;
            
            // Adicionar relacionados à queue de preload
            $this->adicionarPreloadQueue($identificador, $preloadQueue);
            
            return $dados;
        };
    }
    
    /**
     * Monitoramento contínuo de memória
     */
    public function monitorarMemoria(): array
    {
        $usoAtual = memory_get_usage(true);
        $picoUso = memory_get_peak_usage(true);
        $usoPercentual = ($usoAtual / $this->limiteMemoria) * 100;
        
        $status = 'normal';
        if ($usoPercentual > 85) $status = 'critico';
        elseif ($usoPercentual > 70) $status = 'alto';
        elseif ($usoPercentual > 50) $status = 'moderado';
        
        $metricas = [
            'uso_atual' => $usoAtual,
            'uso_atual_mb' => round($usoAtual / 1024 / 1024, 2),
            'pico_uso' => $picoUso,
            'pico_uso_mb' => round($picoUso / 1024 / 1024, 2),
            'limite_total' => $this->limiteMemoria,
            'limite_total_mb' => round($this->limiteMemoria / 1024 / 1024, 2),
            'uso_percentual' => round($usoPercentual, 2),
            'status' => $status,
            'objetos_pool' => array_sum(array_map('count', $this->poolObjetos)),
            'recomendacoes' => $this->gerarRecomendacoes($usoPercentual)
        ];
        
        $this->estatisticasMemoria[] = $metricas;
        
        return $metricas;
    }
    
    /**
     * Otimização automática baseada no uso
     */
    public function otimizacaoAutomatica(): void
    {
        $metricas = $this->monitorarMemoria();
        
        // Ações baseadas no status
        switch ($metricas['status']) {
            case 'critico':
                $this->executarLimpezaCritica();
                break;
                
            case 'alto':
                $this->executarLimpezaAgressiva();
                break;
                
            case 'moderado':
                $this->executarLimpezaNormal();
                break;
        }
        
        // Ajustar tamanhos de pool dinamicamente
        $this->ajustarPoolsDinamicamente($metricas['uso_percentual']);
    }
    
    /**
     * Relatório detalhado de performance de memória
     */
    public function gerarRelatorioMemoria(): array
    {
        if (empty($this->estatisticasMemoria)) {
            return ['status' => 'sem_dados'];
        }
        
        $ultimasMetricas = array_slice($this->estatisticasMemoria, -50);
        
        return [
            'metricas_atuais' => end($ultimasMetricas),
            'uso_medio' => array_sum(array_column($ultimasMetricas, 'uso_percentual')) / count($ultimasMetricas),
            'pico_absoluto' => max(array_column($ultimasMetricas, 'pico_uso_mb')),
            'tendencia' => $this->calcularTendencia($ultimasMetricas),
            'alertas_criticos' => count(array_filter($ultimasMetricas, fn($m) => $m['status'] === 'critico')),
            'eficiencia_pools' => $this->calcularEficienciaPools(),
            'recomendacoes_otimizacao' => $this->gerarRecomendacoesOtimizacao()
        ];
    }
    
    /**
     * Métodos privados de otimização
     */
    
    private function obterLimiteMemoria(): int
    {
        $limite = ini_get('memory_limit');
        
        if ($limite === '-1') {
            return 1024 * 1024 * 1024; // 1GB padrão
        }
        
        $valor = (int) $limite;
        $unidade = strtolower(substr($limite, -1));
        
        return match($unidade) {
            'g' => $valor * 1024 * 1024 * 1024,
            'm' => $valor * 1024 * 1024,
            'k' => $valor * 1024,
            default => $valor
        };
    }
    
    private function iniciarMonitoramento(): void
    {
        // Registrar função de shutdown para limpeza
        register_shutdown_function([$this, 'limpezaFinal']);
        
        // Hook para monitoramento contínuo
        if (function_exists('register_tick_function')) {
            register_tick_function([$this, 'verificacaoPeriodicaMemoria']);
        }
    }
    
    private function resetarObjeto(object $objeto): void
    {
        // Reset básico de propriedades se objeto tem método reset
        if (method_exists($objeto, 'reset')) {
            $objeto->reset();
        }
    }
    
    private function calcularTamanhoChunkOtimo(int $totalItens): int
    {
        $memoriaDisponivel = $this->limiteMemoria - memory_get_usage(true);
        $memoriaSegura = $memoriaDisponivel * 0.1; // Usar apenas 10%
        
        // Estimar tamanho por item (aproximação)
        $tamanhoEstimadoPorItem = 1024; // 1KB por item
        $chunkOtimo = max(100, min(10000, (int)($memoriaSegura / $tamanhoEstimadoPorItem)));
        
        return min($chunkOtimo, $totalItens);
    }
    
    private function memoriaProximaLimite(): bool
    {
        $usoAtual = memory_get_usage(true);
        return ($usoAtual / $this->limiteMemoria) > $this->limiteCritico;
    }
    
    private function executarGarbageCollection(): void
    {
        // Forçar garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Limpar pools se necessário
        if ($this->memoriaProximaLimite()) {
            $this->limparPoolsExcessivos();
        }
    }
    
    private function adicionarPreloadQueue(string $identificador, array &$queue): void
    {
        // Lógica de preload baseada em padrões de uso
        $relacionados = $this->identificarDadosRelacionados($identificador);
        
        foreach ($relacionados as $relacionado) {
            if (! isset($queue[$relacionado])) {
                $queue[$relacionado] = time() + 5; // Preload em 5 segundos
            }
        }
    }
    
    private function identificarDadosRelacionados(string $identificador): array
    {
        // Exemplo de relacionamentos comuns
        $padroes = [
            'produto_' => ['categoria_', 'fornecedor_'],
            'venda_' => ['cliente_', 'produto_'],
            'cliente_' => ['endereco_', 'historico_'],
        ];
        
        foreach ($padroes as $padrao => $relacionados) {
            if (str_contains($identificador, $padrao)) {
                return $relacionados;
            }
        }
        
        return [];
    }
    
    private function gerarRecomendacoes(float $usoPercentual): array
    {
        $recomendacoes = [];
        
        if ($usoPercentual > 80) {
            $recomendacoes[] = 'Executar garbage collection imediato';
            $recomendacoes[] = 'Reduzir tamanho dos pools de objetos';
            $recomendacoes[] = 'Implementar paginação em queries grandes';
        }
        
        if ($usoPercentual > 60) {
            $recomendacoes[] = 'Otimizar cache com compressão';
            $recomendacoes[] = 'Implementar lazy loading em relacionamentos';
        }
        
        if ($usoPercentual < 30) {
            $recomendacoes[] = 'Pode aumentar tamanho dos caches';
            $recomendacoes[] = 'Oportunidade para preload de dados';
        }
        
        return $recomendacoes;
    }
    
    private function executarLimpezaCritica(): void
    {
        // Limpar todos os pools
        $this->poolObjetos = [];
        
        // Forçar garbage collection múltiplas vezes
        for ($i = 0; $i < 3; $i++) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        // Limpar caches temporários
        $this->limparCachesTemporarios();
    }
    
    private function executarLimpezaAgressiva(): void
    {
        // Reduzir pools pela metade
        foreach ($this->poolObjetos as $chave => $pool) {
            $this->poolObjetos[$chave] = array_slice($pool, 0, count($pool) / 2);
        }
        
        gc_collect_cycles();
    }
    
    private function executarLimpezaNormal(): void
    {
        // Garbage collection suave
        gc_collect_cycles();
        
        // Limpar pools antigos
        $this->limparPoolsAntigos();
    }
    
    private function ajustarPoolsDinamicamente(float $usoPercentual): void
    {
        $fatorAjuste = $usoPercentual > 70 ? 0.5 : ($usoPercentual < 40 ? 1.5 : 1.0);
        
        // Aplicar fator aos tamanhos máximos dos pools
        foreach ($this->poolObjetos as $chave => $pool) {
            $tamanhoMaximo = (int)(10 * $fatorAjuste);
            if (count($pool) > $tamanhoMaximo) {
                $this->poolObjetos[$chave] = array_slice($pool, 0, $tamanhoMaximo);
            }
        }
    }
    
    private function calcularTendencia(array $metricas): string
    {
        if (count($metricas) < 2) return 'indefinida';
        
        $primeiro = reset($metricas)['uso_percentual'];
        $ultimo = end($metricas)['uso_percentual'];
        
        $diferenca = $ultimo - $primeiro;
        
        if ($diferenca > 10) return 'crescendo';
        if ($diferenca < -10) return 'decrescendo';
        return 'estavel';
    }
    
    private function calcularEficienciaPools(): float
    {
        $totalObjetos = array_sum(array_map('count', $this->poolObjetos));
        
        if ($totalObjetos === 0) return 1.0;
        
        // Eficiência baseada na reutilização vs criação
        return min(1.0, $totalObjetos / 100); // Máximo 100 objetos nos pools
    }
    
    private function gerarRecomendacoesOtimizacao(): array
    {
        $metricas = $this->monitorarMemoria();
        $recomendacoes = [];
        
        if ($metricas['uso_percentual'] > 75) {
            $recomendacoes[] = 'Aumentar memory_limit no PHP';
            $recomendacoes[] = 'Implementar cache externo (Redis)';
            $recomendacoes[] = 'Otimizar queries com LIMIT';
        }
        
        if (count($this->poolObjetos) > 20) {
            $recomendacoes[] = 'Reduzir número de pools diferentes';
        }
        
        return $recomendacoes;
    }
    
    private function limparPoolsExcessivos(): void
    {
        foreach ($this->poolObjetos as $chave => $pool) {
            $this->poolObjetos[$chave] = array_slice($pool, 0, 2);
        }
    }
    
    private function limparCachesTemporarios(): void
    {
        $tempDir = sys_get_temp_dir();
        $arquivos = glob($tempDir . '/cache_*');
        
        foreach ($arquivos as $arquivo) {
            if (file_exists($arquivo) && filemtime($arquivo) < (time() - 3600)) {
                unlink($arquivo);
            }
        }
    }
    
    private function limparPoolsAntigos(): void
    {
        // Implementação simplificada - remover pools não usados recentemente
        foreach ($this->poolObjetos as $chave => $pool) {
            if (count($pool) > 5) {
                $this->poolObjetos[$chave] = array_slice($pool, 0, 5);
            }
        }
    }
    
    public function verificacaoPeriodicaMemoria(): void
    {
        if ($this->memoriaProximaLimite()) {
            $this->otimizacaoAutomatica();
        }
    }
    
    public function limpezaFinal(): void
    {
        $this->poolObjetos = [];
        gc_collect_cycles();
    }
}
