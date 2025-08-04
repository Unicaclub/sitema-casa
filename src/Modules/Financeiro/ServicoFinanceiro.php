<?php

declare(strict_types=1);

namespace ERP\Modules\Financeiro;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Cache\CacheInterface;
use ERP\Core\Excecoes\ExcecaoValidacao;
use Carbon\Carbon;

/**
 * Serviço de Gestão Financeira
 * 
 * Lógica de negócio para operações financeiras e contábeis
 * 
 * @package ERP\Modules\Financeiro
 */
final class ServicoFinanceiro
{
    public function __construct(
        private DatabaseManager $database,
        private CacheInterface $cache
    ) {}
    
    /**
     * Calcular fluxo de caixa projetado
     */
    public function calcularFluxoCaixaProjetado(string $tenantId, int $diasProjecao = 30): array
    {
        $chaveCache = "fluxo_caixa_projetado_{$tenantId}_{$diasProjecao}";
        
        return $this->cache->remember($chaveCache, function() use ($tenantId, $diasProjecao) {
            $dataInicio = Carbon::now()->startOfDay();
            $dataFim = $dataInicio->copy()->addDays($diasProjecao);
            
            // Obter saldo atual
            $saldoAtual = $this->obterSaldoAtual($tenantId);
            
            // Projetar entradas (contas a receber)
            $entradasProjetadas = $this->database->table('contas_receber')
                ->selectRaw('DATE(data_vencimento) as data, SUM(valor_pendente) as valor')
                ->where('tenant_id', $tenantId)
                ->where('status', 'pendente')
                ->whereBetween('data_vencimento', [$dataInicio, $dataFim])
                ->groupBy('data')
                ->orderBy('data')
                ->get();
            
            // Projetar saídas (contas a pagar)
            $saidasProjetadas = $this->database->table('contas_pagar')
                ->selectRaw('DATE(data_vencimento) as data, SUM(valor_pendente) as valor')
                ->where('tenant_id', $tenantId)
                ->where('status', 'pendente')
                ->whereBetween('data_vencimento', [$dataInicio, $dataFim])
                ->groupBy('data')
                ->orderBy('data')
                ->get();
            
            // Construir projeção dia a dia
            $projecao = [];
            $saldoAcumulado = $saldoAtual;
            
            for ($i = 0; $i <= $diasProjecao; $i++) {
                $data = $dataInicio->copy()->addDays($i);
                $dataStr = $data->format('Y-m-d');
                
                $entrada = $entradasProjetadas->firstWhere('data', $dataStr)->valor ?? 0;
                $saida = $saidasProjetadas->firstWhere('data', $dataStr)->valor ?? 0;
                
                $saldoAcumulado += ($entrada - $saida);
                
                $projecao[] = [
                    'data' => $dataStr,
                    'data_formatada' => $data->format('d/m/Y'),
                    'entradas' => (float) $entrada,
                    'saidas' => (float) $saida,
                    'saldo_dia' => (float) ($entrada - $saida),
                    'saldo_acumulado' => (float) $saldoAcumulado,
                ];
            }
            
            return [
                'saldo_inicial' => $saldoAtual,
                'saldo_final_projetado' => $saldoAcumulado,
                'total_entradas' => array_sum(array_column($projecao, 'entradas')),
                'total_saidas' => array_sum(array_column($projecao, 'saidas')),
                'projecao_diaria' => $projecao,
            ];
        }, 1800); // Cache por 30 minutos
    }
    
    /**
     * Gerar DRE (Demonstrativo de Resultado)
     */
    public function gerarDre(string $tenantId, Carbon $dataInicio, Carbon $dataFim): array
    {
        $chaveCache = "dre_{$tenantId}_{$dataInicio->format('Y-m-d')}_{$dataFim->format('Y-m-d')}";
        
        return $this->cache->remember($chaveCache, function() use ($tenantId, $dataInicio, $dataFim) {
            // Receitas Operacionais
            $receitaBruta = $this->database->table('vendas')
                ->where('tenant_id', $tenantId)
                ->where('status', 'concluida')
                ->whereBetween('data_venda', [$dataInicio, $dataFim])
                ->sum('valor_total');
            
            $impostos = $this->database->table('transacoes_financeiras')
                ->where('tenant_id', $tenantId)
                ->where('categoria', 'impostos')
                ->where('tipo', 'saida')
                ->whereBetween('data_transacao', [$dataInicio, $dataFim])
                ->sum('valor');
            
            $receitaLiquida = $receitaBruta - $impostos;
            
            // Custos dos Produtos Vendidos (CPV)
            $custoProdutos = $this->database->table('vendas_itens')
                ->join('vendas', 'vendas_itens.venda_id', '=', 'vendas.id')
                ->join('produtos', 'vendas_itens.produto_id', '=', 'produtos.id')
                ->where('vendas.tenant_id', $tenantId)
                ->where('vendas.status', 'concluida')
                ->whereBetween('vendas.data_venda', [$dataInicio, $dataFim])
                ->sum($this->database->raw('vendas_itens.quantidade * produtos.preco_custo'));
            
            $lucroBruto = $receitaLiquida - $custoProdutos;
            
            // Despesas Operacionais
            $despesasOperacionais = $this->database->table('transacoes_financeiras')
                ->where('tenant_id', $tenantId)
                ->where('tipo', 'saida')
                ->whereIn('categoria', ['despesas_administrativas', 'despesas_vendas', 'despesas_gerais'])
                ->whereBetween('data_transacao', [$dataInicio, $dataFim])
                ->sum('valor');
            
            $lucroOperacional = $lucroBruto - $despesasOperacionais;
            
            // Receitas e Despesas Não Operacionais
            $receitasNaoOperacionais = $this->database->table('transacoes_financeiras')
                ->where('tenant_id', $tenantId)
                ->where('tipo', 'entrada')
                ->where('categoria', 'receitas_nao_operacionais')
                ->whereBetween('data_transacao', [$dataInicio, $dataFim])
                ->sum('valor');
            
            $despesasNaoOperacionais = $this->database->table('transacoes_financeiras')
                ->where('tenant_id', $tenantId)
                ->where('tipo', 'saida')
                ->where('categoria', 'despesas_nao_operacionais')
                ->whereBetween('data_transacao', [$dataInicio, $dataFim])
                ->sum('valor');
            
            $lucroAntesImpostos = $lucroOperacional + $receitasNaoOperacionais - $despesasNaoOperacionais;
            
            // Provisão para Imposto de Renda
            $impostoRenda = $lucroAntesImpostos > 0 ? ($lucroAntesImpostos * 0.15) : 0; // Simplificado
            
            $lucroLiquido = $lucroAntesImpostos - $impostoRenda;
            
            return [
                'periodo' => [
                    'inicio' => $dataInicio->format('d/m/Y'),
                    'fim' => $dataFim->format('d/m/Y'),
                ],
                'receita_bruta' => (float) $receitaBruta,
                'impostos_sobre_vendas' => (float) $impostos,
                'receita_liquida' => (float) $receitaLiquida,
                'custo_produtos_vendidos' => (float) $custoProdutos,
                'lucro_bruto' => (float) $lucroBruto,
                'despesas_operacionais' => (float) $despesasOperacionais,
                'lucro_operacional' => (float) $lucroOperacional,
                'receitas_nao_operacionais' => (float) $receitasNaoOperacionais,
                'despesas_nao_operacionais' => (float) $despesasNaoOperacionais,
                'lucro_antes_impostos' => (float) $lucroAntesImpostos,
                'imposto_renda' => (float) $impostoRenda,
                'lucro_liquido' => (float) $lucroLiquido,
                'margem_bruta' => $receitaLiquida > 0 ? round(($lucroBruto / $receitaLiquida) * 100, 2) : 0,
                'margem_operacional' => $receitaLiquida > 0 ? round(($lucroOperacional / $receitaLiquida) * 100, 2) : 0,
                'margem_liquida' => $receitaLiquida > 0 ? round(($lucroLiquido / $receitaLiquida) * 100, 2) : 0,
            ];
        }, 3600); // Cache por 1 hora
    }
    
    /**
     * Processar conciliação bancária
     */
    public function processarConciliacaoBancaria(string $tenantId, array $extratosBancarios): array
    {
        $resultadoConciliacao = [
            'itens_conciliados' => 0,
            'itens_nao_conciliados' => 0,
            'diferenca_total' => 0,
            'detalhes' => []
        ];
        
        foreach ($extratosBancarios as $extrato) {
            // Buscar transação correspondente
            $transacao = $this->database->table('transacoes_financeiras')
                ->where('tenant_id', $tenantId)
                ->where('valor', abs($extrato['valor']))
                ->where('data_transacao', $extrato['data'])
                ->where('status_conciliacao', 'pendente')
                ->first();
                
            if ($transacao) {
                // Marcar como conciliado
                $this->database->table('transacoes_financeiras')
                    ->where('id', $transacao->id)
                    ->update([
                        'status_conciliacao' => 'conciliado',
                        'data_conciliacao' => Carbon::now(),
                    ]);
                
                $resultadoConciliacao['itens_conciliados']++;
                $resultadoConciliacao['detalhes'][] = [
                    'tipo' => 'conciliado',
                    'transacao_id' => $transacao->id,
                    'valor' => $extrato['valor'],
                    'data' => $extrato['data'],
                    'descricao' => $extrato['descricao'] ?? 'N/A'
                ];
            } else {
                // Item não encontrado
                $resultadoConciliacao['itens_nao_conciliados']++;
                $resultadoConciliacao['diferenca_total'] += $extrato['valor'];
                $resultadoConciliacao['detalhes'][] = [
                    'tipo' => 'nao_conciliado',
                    'valor' => $extrato['valor'],
                    'data' => $extrato['data'],
                    'descricao' => $extrato['descricao'] ?? 'N/A'
                ];
            }
        }
        
        return $resultadoConciliacao;
    }
    
    /**
     * Obter indicadores financeiros principais
     */
    public function obterIndicadoresFinanceiros(string $tenantId): array
    {
        $chaveCache = "indicadores_financeiros_{$tenantId}";
        
        return $this->cache->remember($chaveCache, function() use ($tenantId) {
            $mesAtual = Carbon::now();
            $mesAnterior = $mesAtual->copy()->subMonth();
            
            // Receita do mês atual
            $receitaMesAtual = $this->database->table('vendas')
                ->where('tenant_id', $tenantId)
                ->where('status', 'concluida')
                ->whereMonth('data_venda', $mesAtual->month)
                ->whereYear('data_venda', $mesAtual->year)
                ->sum('valor_total');
            
            // Receita do mês anterior
            $receitaMesAnterior = $this->database->table('vendas')
                ->where('tenant_id', $tenantId)
                ->where('status', 'concluida')
                ->whereMonth('data_venda', $mesAnterior->month)
                ->whereYear('data_venda', $mesAnterior->year)
                ->sum('valor_total');
            
            // Contas a receber vencidas
            $contasReceberVencidas = $this->database->table('contas_receber')
                ->where('tenant_id', $tenantId)
                ->where('status', 'pendente')
                ->where('data_vencimento', '<', Carbon::now())
                ->sum('valor_pendente');
            
            // Contas a pagar vencidas
            $contasPagarVencidas = $this->database->table('contas_pagar')
                ->where('tenant_id', $tenantId)
                ->where('status', 'pendente')
                ->where('data_vencimento', '<', Carbon::now())
                ->sum('valor_pendente');
            
            // Calcular variação percentual
            $variacaoReceita = $receitaMesAnterior > 0 
                ? (($receitaMesAtual - $receitaMesAnterior) / $receitaMesAnterior) * 100
                : 0;
            
            return [
                'receita_mes_atual' => (float) $receitaMesAtual,
                'receita_mes_anterior' => (float) $receitaMesAnterior,
                'variacao_receita_percentual' => round($variacaoReceita, 2),
                'saldo_atual' => $this->obterSaldoAtual($tenantId),
                'contas_receber_vencidas' => (float) $contasReceberVencidas,
                'contas_pagar_vencidas' => (float) $contasPagarVencidas,
                'liquidez_imediata' => $this->calcularLiquidezImediata($tenantId),
            ];
        }, 900); // Cache por 15 minutos
    }
    
    /**
     * Obter saldo atual consolidado
     */
    private function obterSaldoAtual(string $tenantId): float
    {
        $entradas = $this->database->table('transacoes_financeiras')
            ->where('tenant_id', $tenantId)
            ->where('tipo', 'entrada')
            ->sum('valor');
        
        $saidas = $this->database->table('transacoes_financeiras')
            ->where('tenant_id', $tenantId)
            ->where('tipo', 'saida')
            ->sum('valor');
        
        return (float) ($entradas - $saidas);
    }
    
    /**
     * Calcular liquidez imediata
     */
    private function calcularLiquidezImediata(string $tenantId): float
    {
        $saldoAtual = $this->obterSaldoAtual($tenantId);
        
        $contasPagarVencimento30Dias = $this->database->table('contas_pagar')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pendente')
            ->where('data_vencimento', '<=', Carbon::now()->addDays(30))
            ->sum('valor_pendente');
        
        return $contasPagarVencimento30Dias > 0 
            ? round($saldoAtual / $contasPagarVencimento30Dias, 2)
            : 0;
    }
}