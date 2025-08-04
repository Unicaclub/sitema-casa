<?php

declare(strict_types=1);

namespace ERP\Modules\Estoque;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Cache\CacheInterface;
use ERP\Core\Excecoes\ExcecaoValidacao;
use Carbon\Carbon;

/**
 * Serviço de Gestão de Estoque
 * 
 * Lógica de negócio para controle de estoque e produtos
 * 
 * @package ERP\Modules\Estoque
 */
final class ServicoEstoque
{
    public function __construct(
        private DatabaseManager $database,
        private CacheInterface $cache
    ) {}
    
    /**
     * Registrar movimentação de estoque
     */
    public function registrarMovimentacao(array $dados, string $tenantId): array
    {
        $this->validarDadosMovimentacao($dados);
        
        // Verificar se produto existe
        $produto = $this->obterProdutoPorId($dados['produto_id'], $tenantId);
        if (!$produto) {
            throw new ExcecaoValidacao('Produto não encontrado');
        }
        
        $tipoMovimentacao = $dados['tipo']; // 'entrada' ou 'saida'
        $quantidade = (int) $dados['quantidade'];
        $valorUnitario = (float) ($dados['valor_unitario'] ?? 0);
        
        // Verificar se há estoque suficiente para saída
        if ($tipoMovimentacao === 'saida' && $produto['quantidade_atual'] < $quantidade) {
            throw new ExcecaoValidacao('Estoque insuficiente para esta operação');
        }
        
        // Iniciar transação
        $this->database->beginTransaction();
        
        try {
            // Registrar movimentação
            $movimentacaoId = $this->database->table('movimentacoes_estoque')->insertGetId([
                'tenant_id' => $tenantId,
                'produto_id' => $dados['produto_id'],
                'tipo' => $tipoMovimentacao,
                'quantidade' => $quantidade,
                'valor_unitario' => $valorUnitario,
                'valor_total' => $quantidade * $valorUnitario,
                'motivo' => $dados['motivo'] ?? 'Movimentação manual',
                'observacoes' => $dados['observacoes'] ?? null,
                'usuario_id' => $dados['usuario_id'] ?? null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            
            // Atualizar quantidade do produto
            $novaQuantidade = $tipoMovimentacao === 'entrada' 
                ? $produto['quantidade_atual'] + $quantidade
                : $produto['quantidade_atual'] - $quantidade;
            
            $this->database->table('produtos')
                ->where('id', $dados['produto_id'])
                ->where('tenant_id', $tenantId)
                ->update([
                    'quantidade_atual' => $novaQuantidade,
                    'updated_at' => Carbon::now(),
                ]);
            
            // Verificar se precisa de alerta de estoque baixo
            if ($novaQuantidade <= $produto['estoque_minimo']) {
                $this->criarAlertaEstoqueBaixo($dados['produto_id'], $tenantId);
            }
            
            $this->database->commit();
            
            // Limpar cache
            $this->limparCacheEstoque($tenantId);
            
            return $this->obterMovimentacaoPorId($movimentacaoId, $tenantId);
            
        } catch (\Exception $e) {
            $this->database->rollback();
            throw $e;
        }
    }
    
    /**
     * Obter alertas de estoque baixo
     */
    public function obterAlertasEstoqueBaixo(string $tenantId): array
    {
        $chaveCache = "alertas_estoque_{$tenantId}";
        
        return $this->cache->remember($chaveCache, function() use ($tenantId) {
            return $this->database->table('produtos')
                ->select([
                    'id',
                    'nome',
                    'sku',
                    'quantidade_atual',
                    'estoque_minimo',
                    'categoria',
                    'preco_venda'
                ])
                ->where('tenant_id', $tenantId)
                ->where('status', 'ativo')
                ->whereRaw('quantidade_atual <= estoque_minimo')
                ->orderBy('quantidade_atual', 'asc')
                ->get()
                ->toArray();
        }, 300); // Cache por 5 minutos
    }
    
    /**
     * Obter produtos mais vendidos
     */
    public function obterProdutosMaisVendidos(string $tenantId, int $limite = 10): array
    {
        $chaveCache = "produtos_mais_vendidos_{$tenantId}_{$limite}";
        
        return $this->cache->remember($chaveCache, function() use ($tenantId, $limite) {
            return $this->database->table('vendas_itens')
                ->join('produtos', 'vendas_itens.produto_id', '=', 'produtos.id')
                ->join('vendas', 'vendas_itens.venda_id', '=', 'vendas.id')
                ->select([
                    'produtos.id',
                    'produtos.nome',
                    'produtos.sku',
                    'produtos.preco_venda',
                    $this->database->raw('SUM(vendas_itens.quantidade) as total_vendido'),
                    $this->database->raw('SUM(vendas_itens.valor_total) as receita_total')
                ])
                ->where('produtos.tenant_id', $tenantId)
                ->where('vendas.status', 'concluida')
                ->whereMonth('vendas.created_at', date('m'))
                ->whereYear('vendas.created_at', date('Y'))
                ->groupBy('produtos.id', 'produtos.nome', 'produtos.sku', 'produtos.preco_venda')
                ->orderBy('total_vendido', 'desc')
                ->limit($limite)
                ->get()
                ->toArray();
        }, 1800); // Cache por 30 minutos
    }
    
    /**
     * Calcular valorização do estoque
     */
    public function calcularValorizacaoEstoque(string $tenantId): array
    {
        $chaveCache = "valorizacao_estoque_{$tenantId}";
        
        return $this->cache->remember($chaveCache, function() use ($tenantId) {
            $produtos = $this->database->table('produtos')
                ->select([
                    'categoria',
                    'quantidade_atual',
                    'preco_custo',
                    'preco_venda'
                ])
                ->where('tenant_id', $tenantId)
                ->where('status', 'ativo')
                ->where('quantidade_atual', '>', 0)
                ->get();
            
            $relatorio = [
                'valor_custo_total' => 0,
                'valor_venda_total' => 0,
                'margem_lucro_total' => 0,
                'produtos_em_estoque' => $produtos->count(),
                'por_categoria' => []
            ];
            
            foreach ($produtos as $produto) {
                $valorCusto = $produto->quantidade_atual * $produto->preco_custo;
                $valorVenda = $produto->quantidade_atual * $produto->preco_venda;
                
                $relatorio['valor_custo_total'] += $valorCusto;
                $relatorio['valor_venda_total'] += $valorVenda;
                
                // Agrupar por categoria
                if (!isset($relatorio['por_categoria'][$produto->categoria])) {
                    $relatorio['por_categoria'][$produto->categoria] = [
                        'valor_custo' => 0,
                        'valor_venda' => 0,
                        'quantidade_produtos' => 0,
                    ];
                }
                
                $relatorio['por_categoria'][$produto->categoria]['valor_custo'] += $valorCusto;
                $relatorio['por_categoria'][$produto->categoria]['valor_venda'] += $valorVenda;
                $relatorio['por_categoria'][$produto->categoria]['quantidade_produtos']++;
            }
            
            // Calcular margem de lucro total
            $relatorio['margem_lucro_total'] = $relatorio['valor_venda_total'] - $relatorio['valor_custo_total'];
            $relatorio['percentual_margem'] = $relatorio['valor_custo_total'] > 0 
                ? round(($relatorio['margem_lucro_total'] / $relatorio['valor_custo_total']) * 100, 2)
                : 0;
            
            return $relatorio;
        }, 3600); // Cache por 1 hora
    }
    
    /**
     * Obter histórico de movimentações
     */
    public function obterHistoricoMovimentacoes(string $tenantId, array $filtros = []): array
    {
        $query = $this->database->table('movimentacoes_estoque')
            ->join('produtos', 'movimentacoes_estoque.produto_id', '=', 'produtos.id')
            ->select([
                'movimentacoes_estoque.*',
                'produtos.nome as produto_nome',
                'produtos.sku as produto_sku'
            ])
            ->where('movimentacoes_estoque.tenant_id', $tenantId);
        
        // Aplicar filtros
        if (!empty($filtros['produto_id'])) {
            $query->where('movimentacoes_estoque.produto_id', $filtros['produto_id']);
        }
        
        if (!empty($filtros['tipo'])) {
            $query->where('movimentacoes_estoque.tipo', $filtros['tipo']);
        }
        
        if (!empty($filtros['data_inicio'])) {
            $query->where('movimentacoes_estoque.created_at', '>=', $filtros['data_inicio']);
        }
        
        if (!empty($filtros['data_fim'])) {
            $query->where('movimentacoes_estoque.created_at', '<=', $filtros['data_fim']);
        }
        
        return $query->orderBy('movimentacoes_estoque.created_at', 'desc')
                    ->limit($filtros['limite'] ?? 100)
                    ->get()
                    ->toArray();
    }
    
    /**
     * Validar dados de movimentação
     */
    private function validarDadosMovimentacao(array $dados): void
    {
        $camposObrigatorios = ['produto_id', 'tipo', 'quantidade'];
        
        foreach ($camposObrigatorios as $campo) {
            if (empty($dados[$campo])) {
                throw new ExcecaoValidacao("Campo {$campo} é obrigatório");
            }
        }
        
        if (!in_array($dados['tipo'], ['entrada', 'saida'])) {
            throw new ExcecaoValidacao('Tipo de movimentação deve ser "entrada" ou "saida"');
        }
        
        if ((int) $dados['quantidade'] <= 0) {
            throw new ExcecaoValidacao('Quantidade deve ser maior que zero');
        }
    }
    
    /**
     * Obter produto por ID
     */
    private function obterProdutoPorId(int $produtoId, string $tenantId): ?array
    {
        return $this->database->table('produtos')
            ->where('id', $produtoId)
            ->where('tenant_id', $tenantId)
            ->first()
            ?->toArray();
    }
    
    /**
     * Obter movimentação por ID
     */
    private function obterMovimentacaoPorId(int $movimentacaoId, string $tenantId): array
    {
        return $this->database->table('movimentacoes_estoque')
            ->join('produtos', 'movimentacoes_estoque.produto_id', '=', 'produtos.id')
            ->select([
                'movimentacoes_estoque.*',
                'produtos.nome as produto_nome',
                'produtos.sku as produto_sku'
            ])
            ->where('movimentacoes_estoque.id', $movimentacaoId)
            ->where('movimentacoes_estoque.tenant_id', $tenantId)
            ->first()
            ->toArray();
    }
    
    /**
     * Criar alerta de estoque baixo
     */
    private function criarAlertaEstoqueBaixo(int $produtoId, string $tenantId): void
    {
        // Implementar notificação de estoque baixo
        // Integrar com o sistema de notificações já existente
    }
    
    /**
     * Limpar cache do estoque
     */
    private function limparCacheEstoque(string $tenantId): void
    {
        $this->cache->forget("alertas_estoque_{$tenantId}");
        $this->cache->forget("produtos_mais_vendidos_{$tenantId}_10");
        $this->cache->forget("valorizacao_estoque_{$tenantId}");
    }
}