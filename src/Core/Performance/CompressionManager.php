<?php

declare(strict_types=1);

namespace ERP\Core\Performance;

/**
 * Gerenciador de Compressão Avançado
 * 
 * Sistema de compressão inteligente para otimização de performance
 * 
 * @package ERP\Core\Performance
 */
final class CompressionManager
{
    private array $estatisticasCompressao = [];
    private array $configuracoes = [
        'nivel_compressao' => 6, // Balanceamento entre velocidade e compressão
        'tamanho_minimo' => 1024, // 1KB mínimo para comprimir
        'algoritmo_padrao' => 'gzip',
        'cache_resultados' => true
    ];
    
    /**
     * Compressão inteligente baseada no tipo de dados
     */
    public function comprimir(mixed $dados, ?string $algoritmo = null, ?int $nivel = null): array
    {
        $inicioTempo = microtime(true);
        $dadosSerializados = serialize($dados);
        $tamanhoOriginal = strlen($dadosSerializados);
        
        // Não comprimir se muito pequeno
        if ($tamanhoOriginal < $this->configuracoes['tamanho_minimo']) {
            return [
                'dados' => $dadosSerializados,
                'comprimido' => false,
                'algoritmo' => null,
                'taxa_compressao' => 0,
                'tempo_processamento' => microtime(true) - $inicioTempo
            ];
        }
        
        $algoritmo = $algoritmo ?? $this->escolherAlgoritmoOtimo($dados);
        $nivel = $nivel ?? $this->configuracoes['nivel_compressao'];
        
        $dadosComprimidos = $this->executarCompressao($dadosSerializados, $algoritmo, $nivel);
        $tamanhoComprimido = strlen($dadosComprimidos);
        
        $taxaCompressao = (1 - ($tamanhoComprimido / $tamanhoOriginal)) * 100;
        $tempoProcessamento = microtime(true) - $inicioTempo;
        
        // Estatísticas
        $this->registrarEstatisticas($algoritmo, $tamanhoOriginal, $tamanhoComprimido, $tempoProcessamento);
        
        return [
            'dados' => $dadosComprimidos,
            'comprimido' => true,
            'algoritmo' => $algoritmo,
            'taxa_compressao' => round($taxaCompressao, 2),
            'tamanho_original' => $tamanhoOriginal,
            'tamanho_comprimido' => $tamanhoComprimido,
            'tempo_processamento' => $tempoProcessamento
        ];
    }
    
    /**
     * Descompressão inteligente
     */
    public function descomprimir(array $dadosComprimidos): mixed
    {
        if (!$dadosComprimidos['comprimido']) {
            return unserialize($dadosComprimidos['dados']);
        }
        
        $inicioTempo = microtime(true);
        $dadosDescomprimidos = $this->executarDescompressao(
            $dadosComprimidos['dados'],
            $dadosComprimidos['algoritmo']
        );
        
        $resultado = unserialize($dadosDescomprimidos);
        
        // Registrar tempo de descompressão
        $this->registrarDescompressao($dadosComprimidos['algoritmo'], microtime(true) - $inicioTempo);
        
        return $resultado;
    }
    
    /**
     * Compressão de arquivos estáticos (CSS, JS)
     */
    public function comprimirArquivoEstatico(string $caminhoArquivo): array
    {
        if (!file_exists($caminhoArquivo)) {
            throw new \InvalidArgumentException("Arquivo não encontrado: {$caminhoArquivo}");
        }
        
        $conteudo = file_get_contents($caminhoArquivo);
        $extensao = pathinfo($caminhoArquivo, PATHINFO_EXTENSION);
        
        // Minificação baseada no tipo
        $conteudoMinificado = match($extensao) {
            'css' => $this->minificarCSS($conteudo),
            'js' => $this->minificarJS($conteudo),
            'html' => $this->minificarHTML($conteudo),
            default => $conteudo
        };
        
        // Compressão GZIP
        $conteudoComprimido = gzcompress($conteudoMinificado, 9);
        
        $caminhoComprimido = $caminhoArquivo . '.gz';
        file_put_contents($caminhoComprimido, $conteudoComprimido);
        
        return [
            'arquivo_original' => $caminhoArquivo,
            'arquivo_comprimido' => $caminhoComprimido,
            'tamanho_original' => strlen($conteudo),
            'tamanho_minificado' => strlen($conteudoMinificado),
            'tamanho_comprimido' => strlen($conteudoComprimido),
            'taxa_reducao_total' => round((1 - (strlen($conteudoComprimido) / strlen($conteudo))) * 100, 2)
        ];
    }
    
    /**
     * Compressão de resposta HTTP
     */
    public function comprimirRespostaHTTP(string $conteudo, array $cabecalhos = []): array
    {
        $aceita = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        
        if (str_contains($aceita, 'br') && function_exists('brotli_compress')) {
            // Brotli - melhor compressão
            $conteudoComprimido = brotli_compress($conteudo, 6);
            $encoding = 'br';
        } elseif (str_contains($aceita, 'gzip')) {
            // GZIP - amplamente suportado
            $conteudoComprimido = gzencode($conteudo, 6);
            $encoding = 'gzip';
        } elseif (str_contains($aceita, 'deflate')) {
            // Deflate - fallback
            $conteudoComprimido = gzdeflate($conteudo, 6);
            $encoding = 'deflate';
        } else {
            // Sem compressão
            return [
                'conteudo' => $conteudo,
                'cabecalhos' => $cabecalhos,
                'comprimido' => false
            ];
        }
        
        $cabecalhos['Content-Encoding'] = $encoding;
        $cabecalhos['Content-Length'] = strlen($conteudoComprimido);
        $cabecalhos['Vary'] = 'Accept-Encoding';
        
        return [
            'conteudo' => $conteudoComprimido,
            'cabecalhos' => $cabecalhos,
            'comprimido' => true,
            'algoritmo' => $encoding,
            'taxa_compressao' => round((1 - (strlen($conteudoComprimido) / strlen($conteudo))) * 100, 2)
        ];
    }
    
    /**
     * Compressão de dados de cache
     */
    public function comprimirCache(string $chave, mixed $dados, int $ttl = 3600): bool
    {
        $resultadoCompressao = $this->comprimir($dados);
        
        $metadados = [
            'timestamp' => time(),
            'ttl' => $ttl,
            'compressao' => $resultadoCompressao
        ];
        
        $caminhoCache = $this->gerarCaminhoCache($chave);
        return file_put_contents($caminhoCache, serialize($metadados)) !== false;
    }
    
    /**
     * Recuperação de dados de cache comprimidos
     */
    public function recuperarCache(string $chave): mixed
    {
        $caminhoCache = $this->gerarCaminhoCache($chave);
        
        if (!file_exists($caminhoCache)) {
            return null;
        }
        
        $metadados = unserialize(file_get_contents($caminhoCache));
        
        // Verificar TTL
        if ((time() - $metadados['timestamp']) > $metadados['ttl']) {
            unlink($caminhoCache);
            return null;
        }
        
        return $this->descomprimir($metadados['compressao']);
    }
    
    /**
     * Análise de performance de compressão
     */
    public function analisarPerformanceCompressao(): array
    {
        if (empty($this->estatisticasCompressao)) {
            return ['status' => 'sem_dados'];
        }
        
        $estatisticas = $this->estatisticasCompressao;
        
        return [
            'total_operacoes' => count($estatisticas),
            'taxa_compressao_media' => array_sum(array_column($estatisticas, 'taxa_compressao')) / count($estatisticas),
            'tempo_medio_compressao' => array_sum(array_column($estatisticas, 'tempo_compressao')) / count($estatisticas),
            'tempo_medio_descompressao' => array_sum(array_column($estatisticas, 'tempo_descompressao')) / count($estatisticas),
            'algoritmos_usados' => array_count_values(array_column($estatisticas, 'algoritmo')),
            'economias_espaco' => [
                'total_original' => array_sum(array_column($estatisticas, 'tamanho_original')),
                'total_comprimido' => array_sum(array_column($estatisticas, 'tamanho_comprimido')),
                'economia_total_mb' => round((array_sum(array_column($estatisticas, 'tamanho_original')) - 
                                           array_sum(array_column($estatisticas, 'tamanho_comprimido'))) / 1024 / 1024, 2)
            ],
            'recomendacoes' => $this->gerarRecomendacoes()
        ];
    }
    
    /**
     * Otimização automática dos parâmetros
     */
    public function otimizarParametros(): void
    {
        $analise = $this->analisarPerformanceCompressao();
        
        if ($analise['status'] === 'sem_dados') {
            return;
        }
        
        // Ajustar nível de compressão baseado na performance
        if ($analise['tempo_medio_compressao'] > 0.1) { // > 100ms
            $this->configuracoes['nivel_compressao'] = max(1, $this->configuracoes['nivel_compressao'] - 1);
        } elseif ($analise['tempo_medio_compressao'] < 0.01) { // < 10ms
            $this->configuracoes['nivel_compressao'] = min(9, $this->configuracoes['nivel_compressao'] + 1);
        }
        
        // Ajustar tamanho mínimo baseado na eficiência
        if ($analise['taxa_compressao_media'] < 20) { // < 20% de compressão
            $this->configuracoes['tamanho_minimo'] *= 2;
        }
    }
    
    /**
     * Limpeza de cache comprimido
     */
    public function limparCacheExpirado(): int
    {
        $diretorioCache = sys_get_temp_dir() . '/erp_compressed_cache/';
        
        if (!is_dir($diretorioCache)) {
            return 0;
        }
        
        $arquivosLimpos = 0;
        $arquivos = glob($diretorioCache . '*.cache');
        
        foreach ($arquivos as $arquivo) {
            if (file_exists($arquivo)) {
                $metadados = unserialize(file_get_contents($arquivo));
                
                if ((time() - $metadados['timestamp']) > $metadados['ttl']) {
                    unlink($arquivo);
                    $arquivosLimpos++;
                }
            }
        }
        
        return $arquivosLimpos;
    }
    
    /**
     * Métodos privados
     */
    
    private function escolherAlgoritmoOtimo(mixed $dados): string
    {
        $tipo = gettype($dados);
        
        return match($tipo) {
            'string' => strlen($dados) > 50000 ? 'lz4' : 'gzip',
            'array' => count($dados) > 1000 ? 'lz4' : 'gzip',
            'object' => 'gzip',
            default => 'gzip'
        };
    }
    
    private function executarCompressao(string $dados, string $algoritmo, int $nivel): string
    {
        return match($algoritmo) {
            'gzip' => gzcompress($dados, $nivel),
            'lz4' => function_exists('lz4_compress') ? lz4_compress($dados) : gzcompress($dados, $nivel),
            'brotli' => function_exists('brotli_compress') ? brotli_compress($dados, $nivel) : gzcompress($dados, $nivel),
            default => gzcompress($dados, $nivel)
        };
    }
    
    private function executarDescompressao(string $dados, string $algoritmo): string
    {
        return match($algoritmo) {
            'gzip' => gzuncompress($dados),
            'lz4' => function_exists('lz4_uncompress') ? lz4_uncompress($dados) : gzuncompress($dados),
            'brotli' => function_exists('brotli_uncompress') ? brotli_uncompress($dados) : gzuncompress($dados),
            default => gzuncompress($dados)
        };
    }
    
    private function minificarCSS(string $css): string
    {
        // Remove comentários
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        // Remove espaços desnecessários
        $css = preg_replace('/\s+/', ' ', $css);
        // Remove espaços ao redor de caracteres especiais
        $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);
        
        return trim($css);
    }
    
    private function minificarJS(string $js): string
    {
        // Remoção básica - para minificação avançada usar JSMin ou similar
        $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js); // Comentários /* */
        $js = preg_replace('/\/\/.*$/m', '', $js); // Comentários //
        $js = preg_replace('/\s+/', ' ', $js); // Múltiplos espaços
        
        return trim($js);
    }
    
    private function minificarHTML(string $html): string
    {
        // Remove comentários HTML (exceto condicionais IE)
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+\]|<!|>))(?:(?!-->).)*-->/s', '', $html);
        // Remove espaços extras entre tags
        $html = preg_replace('/>\s+</', '><', $html);
        // Remove espaços no início e fim das linhas
        $html = preg_replace('/^\s+|\s+$/m', '', $html);
        
        return $html;
    }
    
    private function registrarEstatisticas(string $algoritmo, int $tamanhoOriginal, int $tamanhoComprimido, float $tempo): void
    {
        $this->estatisticasCompressao[] = [
            'timestamp' => time(),
            'algoritmo' => $algoritmo,
            'tamanho_original' => $tamanhoOriginal,
            'tamanho_comprimido' => $tamanhoComprimido,
            'taxa_compressao' => (1 - ($tamanhoComprimido / $tamanhoOriginal)) * 100,
            'tempo_compressao' => $tempo,
            'tempo_descompressao' => 0 // Será preenchido na descompressão
        ];
    }
    
    private function registrarDescompressao(string $algoritmo, float $tempo): void
    {
        // Encontrar o registro mais recente deste algoritmo e atualizar
        for ($i = count($this->estatisticasCompressao) - 1; $i >= 0; $i--) {
            if ($this->estatisticasCompressao[$i]['algoritmo'] === $algoritmo && 
                $this->estatisticasCompressao[$i]['tempo_descompressao'] === 0) {
                $this->estatisticasCompressao[$i]['tempo_descompressao'] = $tempo;
                break;
            }
        }
    }
    
    private function gerarCaminhoCache(string $chave): string
    {
        $diretorioCache = sys_get_temp_dir() . '/erp_compressed_cache/';
        
        if (!is_dir($diretorioCache)) {
            mkdir($diretorioCache, 0755, true);
        }
        
        return $diretorioCache . md5($chave) . '.cache';
    }
    
    private function gerarRecomendacoes(): array
    {
        $analise = $this->analisarPerformanceCompressao();
        $recomendacoes = [];
        
        if ($analise['status'] !== 'sem_dados') {
            if ($analise['taxa_compressao_media'] < 20) {
                $recomendacoes[] = 'Taxa de compressão baixa - considerar algoritmos mais eficientes';
            }
            
            if ($analise['tempo_medio_compressao'] > 0.1) {
                $recomendacoes[] = 'Tempo de compressão alto - reduzir nível de compressão';
            }
            
            if ($analise['economias_espaco']['economia_total_mb'] > 100) {
                $recomendacoes[] = 'Excelente economia de espaço - manter configurações atuais';
            }
        }
        
        return $recomendacoes;
    }
}