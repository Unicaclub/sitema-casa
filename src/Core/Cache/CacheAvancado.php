<?php

declare(strict_types=1);

namespace ERP\Core\Cache;

use Carbon\Carbon;
use Predis\Client as RedisClient;

/**
 * Sistema de Cache Avançado
 * 
 * Implementação completa de cache com múltiplos drivers e estratégias
 * 
 * @package ERP\Core\Cache
 */
final class CacheAvancado implements CacheInterface
{
    private array $configuracao;
    private mixed $driver;
    private string $prefixo;
    private array $tags = [];
    
    public function __construct(array $configuracao = [])
    {
        $this->configuracao = array_merge([
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'prefixo' => 'erp:cache:',
            'ttl_padrao' => 3600,
            'compressao' => true,
            'serializacao' => 'json',
        ], $configuracao);
        
        $this->prefixo = $this->configuracao['prefixo'];
        $this->inicializarDriver();
    }
    
    /**
     * Armazenar item no cache
     */
    public function put(string $chave, mixed $valor, int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->configuracao['ttl_padrao'];
        $chaveCompleta = $this->gerarChaveCompleta($chave);
        
        $dadosCache = [
            'valor' => $this->serializar($valor),
            'tipo' => gettype($valor),
            'criado_em' => Carbon::now()->timestamp,
            'expira_em' => Carbon::now()->addSeconds($ttl)->timestamp,
            'tags' => $this->tags,
            'comprimido' => false,
        ];
        
        // Comprimir dados grandes
        if ($this->configuracao['compressao'] && strlen($dadosCache['valor']) > 1024) {
            $dadosCache['valor'] = gzcompress($dadosCache['valor']);
            $dadosCache['comprimido'] = true;
        }
        
        $resultado = match($this->configuracao['driver']) {
            'redis' => $this->driver->setex($chaveCompleta, $ttl, $this->serializar($dadosCache)),
            'file' => $this->salvarArquivo($chaveCompleta, $dadosCache),
            'memory' => $this->salvarMemoria($chaveCompleta, $dadosCache),
            default => false,
        };
        
        // Armazenar tags para limpeza posterior
        if (! empty($this->tags)) {
            $this->armazenarTags($chave, $this->tags);
            $this->tags = [];
        }
        
        return $resultado;
    }
    
    /**
     * Obter item do cache
     */
    public function get(string $chave, mixed $padrao = null): mixed
    {
        $chaveCompleta = $this->gerarChaveCompleta($chave);
        
        $dados = match($this->configuracao['driver']) {
            'redis' => $this->driver->get($chaveCompleta),
            'file' => $this->lerArquivo($chaveCompleta),
            'memory' => $this->lerMemoria($chaveCompleta),
            default => null,
        };
        
        if ($dados === null || $dados === false) {
            return $padrao;
        }
        
        $dadosCache = $this->desserializar($dados);
        
        // Verificar expiração
        if (isset($dadosCache['expira_em']) && Carbon::now()->timestamp > $dadosCache['expira_em']) {
            $this->forget($chave);
            return $padrao;
        }
        
        $valor = $dadosCache['valor'] ?? $dados;
        
        // Descomprimir se necessário
        if (isset($dadosCache['comprimido']) && $dadosCache['comprimido']) {
            $valor = gzuncompress($valor);
        }
        
        return $this->desserializar($valor);
    }
    
    /**
     * Verificar se item existe no cache
     */
    public function has(string $chave): bool
    {
        $chaveCompleta = $this->gerarChaveCompleta($chave);
        
        return match($this->configuracao['driver']) {
            'redis' => $this->driver->exists($chaveCompleta) > 0,
            'file' => $this->arquivoExiste($chaveCompleta),
            'memory' => $this->memoriaExiste($chaveCompleta),
            default => false,
        };
    }
    
    /**
     * Remover item do cache
     */
    public function forget(string $chave): bool
    {
        $chaveCompleta = $this->gerarChaveCompleta($chave);
        
        return match($this->configuracao['driver']) {
            'redis' => $this->driver->del($chaveCompleta) > 0,
            'file' => $this->removerArquivo($chaveCompleta),
            'memory' => $this->removerMemoria($chaveCompleta),
            default => false,
        };
    }
    
    /**
     * Limpar todo o cache
     */
    public function flush(): bool
    {
        return match($this->configuracao['driver']) {
            'redis' => $this->driver->flushdb(),
            'file' => $this->limparArquivos(),
            'memory' => $this->limparMemoria(),
            default => false,
        };
    }
    
    /**
     * Incrementar valor numérico
     */
    public function increment(string $chave, int $valor = 1): int
    {
        $chaveCompleta = $this->gerarChaveCompleta($chave);
        
        return match($this->configuracao['driver']) {
            'redis' => $this->driver->incrby($chaveCompleta, $valor),
            default => $this->incrementarGenerico($chave, $valor),
        };
    }
    
    /**
     * Decrementar valor numérico
     */
    public function decrement(string $chave, int $valor = 1): int
    {
        $chaveCompleta = $this->gerarChaveCompleta($chave);
        
        return match($this->configuracao['driver']) {
            'redis' => $this->driver->decrby($chaveCompleta, $valor),
            default => $this->decrementarGenerico($chave, $valor),
        };
    }
    
    /**
     * Obter múltiplos itens do cache
     */
    public function many(array $chaves): array
    {
        $resultado = [];
        
        foreach ($chaves as $chave) {
            $resultado[$chave] = $this->get($chave);
        }
        
        return $resultado;
    }
    
    /**
     * Armazenar múltiplos itens no cache
     */
    public function putMany(array $valores, int $ttl = null): bool
    {
        $sucesso = true;
        
        foreach ($valores as $chave => $valor) {
            if (! $this->put($chave, $valor, $ttl)) {
                $sucesso = false;
            }
        }
        
        return $sucesso;
    }
    
    /**
     * Definir tags para o próximo item
     */
    public function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }
    
    /**
     * Limpar cache por tags
     */
    public function limparPorTags(array $tags): bool
    {
        $chaves = [];
        
        foreach ($tags as $tag) {
            $chavesTag = $this->obterChavesPorTag($tag);
            $chaves = array_merge($chaves, $chavesTag);
        }
        
        $sucesso = true;
        foreach (array_unique($chaves) as $chave) {
            if (! $this->forget($chave)) {
                $sucesso = false;
            }
        }
        
        return $sucesso;
    }
    
    /**
     * Obter ou definir item no cache
     */
    public function remember(string $chave, callable $callback, int $ttl = null): mixed
    {
        $valor = $this->get($chave);
        
        if ($valor !== null) {
            return $valor;
        }
        
        $valor = $callback();
        $this->put($chave, $valor, $ttl);
        
        return $valor;
    }
    
    /**
     * Obter estatísticas do cache
     */
    public function obterEstatisticas(): array
    {
        return match($this->configuracao['driver']) {
            'redis' => $this->estatisticasRedis(),
            'file' => $this->estatisticasArquivo(),
            'memory' => $this->estatisticasMemoria(),
            default => [],
        };
    }
    
    /**
     * Inicializar driver de cache
     */
    private function inicializarDriver(): void
    {
        $this->driver = match($this->configuracao['driver']) {
            'redis' => new RedisClient([
                'scheme' => 'tcp',
                'host' => $this->configuracao['host'],
                'port' => $this->configuracao['port'],
                'database' => $this->configuracao['database'],
            ]),
            'file' => $this->inicializarDriverArquivo(),
            'memory' => [],
            default => throw new \InvalidArgumentException("Driver de cache '{$this->configuracao['driver']}' não suportado"),
        };
    }
    
    /**
     * Gerar chave completa com prefixo
     */
    private function gerarChaveCompleta(string $chave): string
    {
        return $this->prefixo . $chave;
    }
    
    /**
     * Serializar dados
     */
    private function serializar(mixed $dados): string
    {
        return match($this->configuracao['serializacao']) {
            'json' => json_encode($dados),
            'serialize' => serialize($dados),
            'igbinary' => extension_loaded('igbinary') ? igbinary_serialize($dados) : serialize($dados),
            default => json_encode($dados),
        };
    }
    
    /**
     * Desserializar dados
     */
    private function desserializar(string $dados): mixed
    {
        return match($this->configuracao['serializacao']) {
            'json' => json_decode($dados, true),
            'serialize' => unserialize($dados),
            'igbinary' => extension_loaded('igbinary') ? igbinary_unserialize($dados) : unserialize($dados),
            default => json_decode($dados, true),
        };
    }
    
    /**
     * Armazenar tags
     */
    private function armazenarTags(string $chave, array $tags): void
    {
        foreach ($tags as $tag) {
            $chaveTag = $this->prefixo . 'tags:' . $tag;
            $chavesExistentes = $this->get($chaveTag, []);
            
            if (! in_array($chave, $chavesExistentes)) {
                $chavesExistentes[] = $chave;
                $this->put($chaveTag, $chavesExistentes, 86400); // 24 horas
            }
        }
    }
    
    /**
     * Obter chaves por tag
     */
    private function obterChavesPorTag(string $tag): array
    {
        $chaveTag = $this->prefixo . 'tags:' . $tag;
        return $this->get($chaveTag, []);
    }
    
    /**
     * Incrementar genérico
     */
    private function incrementarGenerico(string $chave, int $valor): int
    {
        $valorAtual = (int) $this->get($chave, 0);
        $novoValor = $valorAtual + $valor;
        $this->put($chave, $novoValor);
        
        return $novoValor;
    }
    
    /**
     * Decrementar genérico
     */
    private function decrementarGenerico(string $chave, int $valor): int
    {
        $valorAtual = (int) $this->get($chave, 0);
        $novoValor = $valorAtual - $valor;
        $this->put($chave, $novoValor);
        
        return $novoValor;
    }
    
    // Métodos específicos para driver de arquivo
    private function inicializarDriverArquivo(): string
    {
        $diretorio = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp_cache';
        if (! is_dir($diretorio)) {
            mkdir($diretorio, 0755, true);
        }
        return $diretorio;
    }
    
    private function salvarArquivo(string $chave, array $dados): bool
    {
        $arquivo = $this->driver . DIRECTORY_SEPARATOR . md5($chave) . '.cache';
        return file_put_contents($arquivo, $this->serializar($dados)) !== false;
    }
    
    private function lerArquivo(string $chave): ?array
    {
        $arquivo = $this->driver . DIRECTORY_SEPARATOR . md5($chave) . '.cache';
        if (! file_exists($arquivo)) {
            return null;
        }
        
        $conteudo = file_get_contents($arquivo);
        return $conteudo ? $this->desserializar($conteudo) : null;
    }
    
    private function arquivoExiste(string $chave): bool
    {
        $arquivo = $this->driver . DIRECTORY_SEPARATOR . md5($chave) . '.cache';
        return file_exists($arquivo);
    }
    
    private function removerArquivo(string $chave): bool
    {
        $arquivo = $this->driver . DIRECTORY_SEPARATOR . md5($chave) . '.cache';
        return file_exists($arquivo) ? unlink($arquivo) : true;
    }
    
    private function limparArquivos(): bool
    {
        $arquivos = glob($this->driver . DIRECTORY_SEPARATOR . '*.cache');
        foreach ($arquivos as $arquivo) {
            unlink($arquivo);
        }
        return true;
    }
    
    // Métodos específicos para driver de memória
    private function salvarMemoria(string $chave, array $dados): bool
    {
        $this->driver[$chave] = $dados;
        return true;
    }
    
    private function lerMemoria(string $chave): ?array
    {
        return $this->driver[$chave] ?? null;
    }
    
    private function memoriaExiste(string $chave): bool
    {
        return isset($this->driver[$chave]);
    }
    
    private function removerMemoria(string $chave): bool
    {
        unset($this->driver[$chave]);
        return true;
    }
    
    private function limparMemoria(): bool
    {
        $this->driver = [];
        return true;
    }
    
    // Métodos de estatísticas
    private function estatisticasRedis(): array
    {
        $info = $this->driver->info();
        return [
            'driver' => 'redis',
            'memoria_usada' => $info['used_memory_human'] ?? 'N/A',
            'chaves_totais' => $info['db0']['keys'] ?? 0,
            'hits' => $info['keyspace_hits'] ?? 0,
            'misses' => $info['keyspace_misses'] ?? 0,
            'conexoes' => $info['connected_clients'] ?? 0,
        ];
    }
    
    private function estatisticasArquivo(): array
    {
        $arquivos = glob($this->driver . DIRECTORY_SEPARATOR . '*.cache');
        $tamanhoTotal = 0;
        
        foreach ($arquivos as $arquivo) {
            $tamanhoTotal += filesize($arquivo);
        }
        
        return [
            'driver' => 'file',
            'total_arquivos' => count($arquivos),
            'tamanho_total' => $this->formatarBytes($tamanhoTotal),
            'diretorio' => $this->driver,
        ];
    }
    
    private function estatisticasMemoria(): array
    {
        return [
            'driver' => 'memory',
            'total_chaves' => count($this->driver),
            'memoria_usada' => $this->formatarBytes(memory_get_usage()),
            'memoria_pico' => $this->formatarBytes(memory_get_peak_usage()),
        ];
    }
    
    /**
     * Formatar bytes para leitura humana
     */
    private function formatarBytes(int $bytes): string
    {
        $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($unidades) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $unidades[$i];
    }
}
