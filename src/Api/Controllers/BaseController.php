<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use ERP\Core\Auth\AuthManager;
use ERP\Core\Database\DatabaseManager;
use ERP\Core\Cache\CacheManager;
use ERP\Core\Validation\Validator;

/**
 * Controlador Base da API
 * 
 * Fornece funcionalidades comuns para todos os controladores da API
 * 
 * @package ERP\Api\Controllers
 */
abstract class ControladorBase
{
    protected ?string $tenantId = null;
    protected ?object $usuario = null;
    
    public function __construct(
        protected AuthManager $auth,
        protected DatabaseManager $database,
        protected CacheManager $cache,
        protected Validator $validador
    ) {
        $this->usuario = $this->auth->user();
        $this->tenantId = $this->auth->getTenant();
    }
    
    /**
     * Retornar resposta de sucesso
     */
    protected function sucesso(mixed $dados = null, string $mensagem = null, int $status = 200): Response
    {
        return Response::success($dados, $mensagem, $status);
    }
    
    /**
     * Retornar resposta de erro
     */
    protected function erro(string $mensagem, int $status = 400, array $erros = []): Response
    {
        return Response::error($mensagem, $status, $erros);
    }
    
    /**
     * Retornar resposta de erro de validação
     */
    protected function erroValidacao(array $erros, string $mensagem = 'Falha na validação'): Response
    {
        return Response::validationError($erros, $mensagem);
    }
    
    /**
     * Retornar resposta paginada
     */
    protected function paginado(array $dados, array $paginacao, string $mensagem = null): Response
    {
        return Response::paginated($dados, $paginacao, $mensagem);
    }
    
    /**
     * Validar dados da requisição
     */
    protected function validar(Request $request, array $regras): array
    {
        return $this->validador->validate($request->all(), $regras);
    }
    
    /**
     * Verificar permissão do usuário
     */
    protected function autorizar(string $permissao): void
    {
        if (! $this->auth->can($permissao)) {
            throw new \ERP\Core\Excecoes\ExcecaoAutorizacao("Permissões insuficientes: {$permissao}");
        }
    }
    
    /**
     * Obter resultados paginados
     */
    protected function obterResultadosPaginados($query, Request $request): array
    {
        $pagina = max(1, (int) $request->query('page', 1));
        $porPagina = min(100, max(1, (int) $request->query('per_page', 15)));
        $offset = ($pagina - 1) * $porPagina;
        
        // Obter total de registros
        $total = $query->count();
        
        // Obter dados paginados
        $dados = $query->offset($offset)->limit($porPagina)->get()->toArray();
        
        return [
            'dados' => $dados,
            'paginacao' => [
                'pagina_atual' => $pagina,
                'por_pagina' => $porPagina,
                'total' => $total,
                'ultima_pagina' => (int) ceil($total / $porPagina),
                'de' => $offset + 1,
                'ate' => min($offset + $porPagina, $total),
                'tem_mais' => $pagina * $porPagina < $total,
            ]
        ];
    }
    
    /**
     * Aplicar filtros de busca à consulta
     */
    protected function aplicarBusca($query, Request $request, array $camposPesquisaveis): void
    {
        $busca = $request->query('search');
        
        if ($busca && !empty($camposPesquisaveis)) {
            $query->where(function($q) use ($busca, $camposPesquisaveis) {
                foreach ($camposPesquisaveis as $campo) {
                    $q->orWhere($campo, 'LIKE', "%{$busca}%");
                }
            });
        }
    }
    
    /**
     * Aplicar filtro de intervalo de datas
     */
    protected function aplicarIntervaloDatas($query, Request $request, string $campoData = 'created_at'): void
    {
        $dataInicio = $request->query('start_date');
        $dataFim = $request->query('end_date');
        
        if ($dataInicio) {
            $query->where($campoData, '>=', $dataInicio);
        }
        
        if ($dataFim) {
            $query->where($campoData, '<=', $dataFim . ' 23:59:59');
        }
    }
    
    /**
     * Aplicar filtro de tenant à consulta
     */
    protected function aplicarFiltroTenant($query, string $campoTenant = 'tenant_id'): void
    {
        if ($this->tenantId) {
            $query->where($campoTenant, $this->tenantId);
        }
    }
    
    /**
     * Obter dados do cache ou executar callback
     */
    protected function cacheado(string $chave, callable $callback, int $ttl = 3600): mixed
    {
        $chaveCache = "tenant:{$this->tenantId}:{$chave}";
        
        if ($this->cache->has($chaveCache)) {
            return $this->cache->get($chaveCache);
        }
        
        $dados = $callback();
        $this->cache->put($chaveCache, $dados, $ttl);
        
        return $dados;
    }
    
    /**
     * Limpar cache do tenant para padrão de chave
     */
    protected function limparCache(string $padrao): void
    {
        $chaveCache = "tenant:{$this->tenantId}:{$padrao}";
        $this->cache->forget($chaveCache);
    }
}
