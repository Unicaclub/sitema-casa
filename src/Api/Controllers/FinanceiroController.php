<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use Carbon\Carbon;

/**
 * Controlador API Financeiro
 * 
 * Gerencia operações financeiras do sistema
 * 
 * @package ERP\Api\Controllers
 */
final class FinanceiroController extends BaseController
{
    /**
     * Obter fluxo de caixa
     * GET /api/financeiro/fluxo
     */
    public function fluxo(Request $request): Response
    {
        $this->authorize('financeiro.visualizar');
        
        $periodo = $request->query('periodo', '30'); // dias
        $dataInicio = Carbon::now()->subDays((int)$periodo)->startOfDay();
        $dataFim = Carbon::now()->endOfDay();
        
        return $this->cached("fluxo_caixa_{$periodo}", function() use ($dataInicio, $dataFim) {
            // Receitas
            $receitas = $this->database->table('financeiro_transacoes')
                ->where('tipo', 'receita')
                ->where('status', 'pago')
                ->whereBetween('data_transacao', [$dataInicio, $dataFim]);
            
            $this->aplicarFiltroTenant($receitas);
            
            $totalReceitas = $receitas->sum('valor') ?? 0;
            $receitasPendentes = $this->database->table('financeiro_transacoes')
                ->where('tipo', 'receita')
                ->where('status', 'pendente')
                ->whereBetween('data_vencimento', [$dataInicio, $dataFim]);
            
            $this->aplicarFiltroTenant($receitasPendentes);
            $valorReceitasPendentes = $receitasPendentes->sum('valor') ?? 0;
            
            // Despesas
            $despesas = $this->database->table('financeiro_transacoes')
                ->where('tipo', 'despesa')
                ->where('status', 'pago')
                ->whereBetween('data_transacao', [$dataInicio, $dataFim]);
            
            $this->aplicarFiltroTenant($despesas);
            
            $totalDespesas = $despesas->sum('valor') ?? 0;
            $despesasPendentes = $this->database->table('financeiro_transacoes')
                ->where('tipo', 'despesa')
                ->where('status', 'pendente')
                ->whereBetween('data_vencimento', [$dataInicio, $dataFim]);
            
            $this->aplicarFiltroTenant($despesasPendentes);
            $valorDespesasPendentes = $despesasPendentes->sum('valor') ?? 0;
            
            // Fluxo diário
            $fluxoDiario = $this->obterFluxoDiario($dataInicio, $dataFim);
            
            return [
                'periodo' => [
                    'data_inicio' => $dataInicio->toDateString(),
                    'data_fim' => $dataFim->toDateString(),
                ],
                'resumo' => [
                    'total_receitas' => $totalReceitas,
                    'total_despesas' => $totalDespesas,
                    'saldo_liquido' => $totalReceitas - $totalDespesas,
                    'receitas_pendentes' => $valorReceitasPendentes,
                    'despesas_pendentes' => $valorDespesasPendentes,
                ],
                'fluxo_diario' => $fluxoDiario,
                'projecao' => $this->calcularProjecao($dataInicio, $dataFim),
            ];
        }, 600); // Cache por 10 minutos
    }
    
    /**
     * Listar contas a pagar/receber
     * GET /api/financeiro/contas
     */
    public function contas(Request $request): Response
    {
        $this->authorize('financeiro.visualizar');
        
        $query = $this->database->table('financeiro_transacoes')
            ->select([
                'id',
                'tipo',
                'descricao',
                'valor',
                'status',
                'categoria_id',
                'data_vencimento',
                'data_transacao',
                'forma_pagamento',
                'observacoes',
                'created_at'
            ])
            ->leftJoin('financeiro_categorias', 'financeiro_transacoes.categoria_id', '=', 'financeiro_categorias.id')
            ->addSelect('financeiro_categorias.nome as categoria_nome');
        
        $this->aplicarFiltroTenant($query, 'financeiro_transacoes.tenant_id');
        
        // Aplicar filtros
        $this->aplicarFiltrosFinanceiros($query, $request);
        
        // Aplicar busca
        $this->aplicarBusca($query, $request, [
            'financeiro_transacoes.descricao', 'financeiro_categorias.nome'
        ]);
        
        // Aplicar intervalo de datas
        $this->aplicarIntervaloData($query, $request, 'financeiro_transacoes.data_vencimento');
        
        // Ordenação
        $ordenarPor = $request->query('ordenar_por', 'data_vencimento');
        $ordenacao = $request->query('ordenacao', 'asc');
        $query->orderBy($ordenarPor, $ordenacao);
        
        $resultado = $this->obterResultadosPaginados($query, $request);
        
        // Adicionar estatísticas
        $resultado['estatisticas'] = $this->obterEstatisticasContas();
        
        return $this->paginado($resultado['dados'], $resultado['paginacao']);
    }
    
    /**
     * Criar nova transação
     * POST /api/financeiro/transacao/create
     */
    public function criarTransacao(Request $request): Response
    {
        $this->authorize('financeiro.criar');
        
        $regras = [
            'tipo' => 'required|in:receita,despesa',
            'descricao' => 'required|string|max:255',
            'valor' => 'required|numeric|min:0.01',
            'categoria_id' => 'required|integer|exists:financeiro_categorias,id',
            'data_vencimento' => 'required|date',
            'forma_pagamento' => 'required|in:dinheiro,cartao_credito,cartao_debito,pix,boleto,transferencia',
            'status' => 'in:pendente,pago,cancelado',
            'observacoes' => 'string|max:1000',
        ];
        
        $dados = $this->validar($request, $regras);
        
        // Verificar se categoria pertence ao tenant
        $categoria = $this->database->table('financeiro_categorias')
            ->where('id', $dados['categoria_id']);
        
        $this->aplicarFiltroTenant($categoria);
        
        if (!$categoria->exists()) {
            return $this->erro('Categoria não encontrada', 404);
        }
        
        $dados['tenant_id'] = $this->tenantId;
        $dados['usuario_id'] = $this->usuario->getAuthIdentifier();
        $dados['status'] = $dados['status'] ?? 'pendente';
        $dados['created_at'] = Carbon::now();
        $dados['updated_at'] = Carbon::now();
        
        // Se transação já está paga, definir data de transação
        if ($dados['status'] === 'pago') {
            $dados['data_transacao'] = Carbon::now();
        }
        
        $transacaoId = $this->database->table('financeiro_transacoes')->insertGetId($dados);
        
        $transacao = $this->database->table('financeiro_transacoes')
            ->where('id', $transacaoId)
            ->first();
        
        $this->limparCache('financeiro_*');
        
        return $this->sucesso($transacao, 'Transação criada com sucesso', 201);
    }
    
    /**
     * Atualizar transação
     * PUT /api/financeiro/transacao/update/{id}
     */
    public function atualizarTransacao(Request $request): Response
    {
        $this->authorize('financeiro.atualizar');
        
        $id = $request->getAttribute('id');
        
        $regras = [
            'descricao' => 'string|max:255',
            'valor' => 'numeric|min:0.01',
            'categoria_id' => 'integer|exists:financeiro_categorias,id',
            'data_vencimento' => 'date',
            'forma_pagamento' => 'in:dinheiro,cartao_credito,cartao_debito,pix,boleto,transferencia',
            'status' => 'in:pendente,pago,cancelado',
            'observacoes' => 'string|max:1000',
        ];
        
        $dados = $this->validar($request, $regras);
        
        // Verificar se transação existe
        $transacao = $this->database->table('financeiro_transacoes')
            ->where('id', $id);
        
        $this->aplicarFiltroTenant($transacao);
        
        $transacaoExistente = $transacao->first();
        
        if (!$transacaoExistente) {
            return $this->erro('Transação não encontrada', 404);
        }
        
        // Se status mudou para 'pago', definir data de transação
        if (isset($dados['status']) && $dados['status'] === 'pago' && $transacaoExistente->status !== 'pago') {
            $dados['data_transacao'] = Carbon::now();
        }
        
        // Se status mudou de 'pago' para outro, limpar data de transação
        if (isset($dados['status']) && $dados['status'] !== 'pago' && $transacaoExistente->status === 'pago') {
            $dados['data_transacao'] = null;
        }
        
        $dados['updated_at'] = Carbon::now();
        
        $this->database->table('financeiro_transacoes')
            ->where('id', $id)
            ->update($dados);
        
        $transacaoAtualizada = $this->database->table('financeiro_transacoes')
            ->where('id', $id)
            ->first();
        
        $this->limparCache('financeiro_*');
        
        return $this->sucesso($transacaoAtualizada, 'Transação atualizada com sucesso');
    }
    
    /**
     * Remover transação
     * DELETE /api/financeiro/transacao/delete/{id}
     */
    public function removerTransacao(Request $request): Response
    {
        $this->authorize('financeiro.excluir');
        
        $id = $request->getAttribute('id');
        
        $transacao = $this->database->table('financeiro_transacoes')
            ->where('id', $id);
        
        $this->aplicarFiltroTenant($transacao);
        
        if (!$transacao->exists()) {
            return $this->erro('Transação não encontrada', 404);
        }
        
        $this->database->table('financeiro_transacoes')
            ->where('id', $id)
            ->delete();
        
        $this->limparCache('financeiro_*');
        
        return $this->sucesso(null, 'Transação removida com sucesso');
    }
    
    /**
     * Conciliação bancária
     * POST /api/financeiro/concilia
     */
    public function concilia(Request $request): Response
    {
        $this->authorize('financeiro.conciliar');
        
        $regras = [
            'conta_bancaria_id' => 'required|integer|exists:financeiro_contas_bancarias,id',
            'data_inicio' => 'required|date',
            'data_fim' => 'required|date|after_or_equal:data_inicio',
            'saldo_inicial' => 'required|numeric',
            'saldo_final' => 'required|numeric',
        ];
        
        $dados = $this->validar($request, $regras);
        
        // Buscar transações do período
        $transacoes = $this->database->table('financeiro_transacoes')
            ->where('conta_bancaria_id', $dados['conta_bancaria_id'])
            ->where('status', 'pago')
            ->whereBetween('data_transacao', [$dados['data_inicio'], $dados['data_fim']]);
        
        $this->aplicarFiltroTenant($transacoes);
        
        $listaTransacoes = $transacoes->get();
        
        $saldoCalculado = $dados['saldo_inicial'];
        $diferencas = [];
        
        foreach ($listaTransacoes as $transacao) {
            if ($transacao->tipo === 'receita') {
                $saldoCalculado += $transacao->valor;
            } else {
                $saldoCalculado -= $transacao->valor;
            }
        }
        
        $diferenca = $dados['saldo_final'] - $saldoCalculado;
        
        // Criar registro de conciliação
        $conciliacaoId = $this->database->table('financeiro_conciliacoes')->insertGetId([
            'conta_bancaria_id' => $dados['conta_bancaria_id'],
            'data_inicio' => $dados['data_inicio'],
            'data_fim' => $dados['data_fim'],
            'saldo_inicial' => $dados['saldo_inicial'],
            'saldo_final' => $dados['saldo_final'],
            'saldo_calculado' => $saldoCalculado,
            'diferenca' => $diferenca,
            'status' => abs($diferenca) < 0.01 ? 'conciliado' : 'divergente',
            'usuario_id' => $this->usuario->getAuthIdentifier(),
            'tenant_id' => $this->tenantId,
            'created_at' => Carbon::now(),
        ]);
        
        $conciliacao = $this->database->table('financeiro_conciliacoes')
            ->where('id', $conciliacaoId)
            ->first();
        
        return $this->sucesso([
            'conciliacao' => $conciliacao,
            'transacoes' => $listaTransacoes->toArray(),
            'resumo' => [
                'total_transacoes' => $listaTransacoes->count(),
                'saldo_calculado' => $saldoCalculado,
                'diferenca' => $diferenca,
                'status' => abs($diferenca) < 0.01 ? 'conciliado' : 'divergente',
            ]
        ], 'Conciliação realizada com sucesso');
    }
    
    /**
     * Relatório de DRE (Demonstrativo de Resultado)
     * GET /api/financeiro/dre
     */
    public function dre(Request $request): Response
    {
        $this->authorize('financeiro.visualizar');
        
        $dataInicio = $request->query('data_inicio', Carbon::now()->startOfMonth()->toDateString());
        $dataFim = $request->query('data_fim', Carbon::now()->endOfMonth()->toDateString());
        
        return $this->cached("dre_{$dataInicio}_{$dataFim}", function() use ($dataInicio, $dataFim) {
            $query = $this->database->table('financeiro_transacoes')
                ->join('financeiro_categorias', 'financeiro_transacoes.categoria_id', '=', 'financeiro_categorias.id')
                ->where('financeiro_transacoes.status', 'pago')
                ->whereBetween('financeiro_transacoes.data_transacao', [$dataInicio, $dataFim]);
            
            $this->aplicarFiltroTenant($query, 'financeiro_transacoes.tenant_id');
            
            $dados = $query->select([
                'financeiro_transacoes.tipo',
                'financeiro_categorias.nome as categoria',
                'financeiro_transacoes.valor'
            ])->get();
            
            $receitas = [];
            $despesas = [];
            $totalReceitas = 0;
            $totalDespesas = 0;
            
            foreach ($dados as $item) {
                if ($item->tipo === 'receita') {
                    $receitas[$item->categoria] = ($receitas[$item->categoria] ?? 0) + $item->valor;
                    $totalReceitas += $item->valor;
                } else {
                    $despesas[$item->categoria] = ($despesas[$item->categoria] ?? 0) + $item->valor;
                    $totalDespesas += $item->valor;
                }
            }
            
            return [
                'periodo' => [
                    'data_inicio' => $dataInicio,
                    'data_fim' => $dataFim,
                ],
                'receitas' => [
                    'por_categoria' => $receitas,
                    'total' => $totalReceitas,
                ],
                'despesas' => [
                    'por_categoria' => $despesas,
                    'total' => $totalDespesas,
                ],
                'resultado' => [
                    'lucro_bruto' => $totalReceitas,
                    'despesas_totais' => $totalDespesas,
                    'lucro_liquido' => $totalReceitas - $totalDespesas,
                    'margem_liquida' => $totalReceitas > 0 ? (($totalReceitas - $totalDespesas) / $totalReceitas) * 100 : 0,
                ],
            ];
        }, 3600); // Cache por 1 hora
    }
    
    /**
     * Aplicar filtros financeiros específicos
     */
    private function aplicarFiltrosFinanceiros($query, Request $request): void
    {
        if ($tipo = $request->query('tipo')) {
            $query->where('financeiro_transacoes.tipo', $tipo);
        }
        
        if ($status = $request->query('status')) {
            if ($status === 'vencidas') {
                $query->where('financeiro_transacoes.status', 'pendente')
                      ->where('financeiro_transacoes.data_vencimento', '<', Carbon::now()->toDateString());
            } else {
                $query->where('financeiro_transacoes.status', $status);
            }
        }
        
        if ($categoriaId = $request->query('categoria_id')) {
            $query->where('financeiro_transacoes.categoria_id', $categoriaId);
        }
        
        if ($formaPagamento = $request->query('forma_pagamento')) {
            $query->where('financeiro_transacoes.forma_pagamento', $formaPagamento);
        }
        
        if ($valorMin = $request->query('valor_min')) {
            $query->where('financeiro_transacoes.valor', '>=', $valorMin);
        }
        
        if ($valorMax = $request->query('valor_max')) {
            $query->where('financeiro_transacoes.valor', '<=', $valorMax);
        }
    }
    
    /**
     * Obter estatísticas das contas
     */
    private function obterEstatisticasContas(): array
    {
        $query = $this->database->table('financeiro_transacoes');
        $this->aplicarFiltroTenant($query);
        
        $pendentes = $query->where('status', 'pendente')->count();
        $pagas = $query->where('status', 'pago')->count();
        $vencidas = $query->where('status', 'pendente')
                          ->where('data_vencimento', '<', Carbon::now()->toDateString())
                          ->count();
        
        return [
            'total' => $query->count(),
            'pendentes' => $pendentes,
            'pagas' => $pagas,
            'vencidas' => $vencidas,
        ];
    }
    
    /**
     * Obter fluxo diário
     */
    private function obterFluxoDiario(Carbon $dataInicio, Carbon $dataFim): array
    {
        $query = $this->database->table('financeiro_transacoes')
            ->selectRaw('
                DATE(data_transacao) as data,
                tipo,
                SUM(valor) as total
            ')
            ->where('status', 'pago')
            ->whereBetween('data_transacao', [$dataInicio, $dataFim]);
        
        $this->aplicarFiltroTenant($query);
        
        $dados = $query->groupBy('DATE(data_transacao)', 'tipo')
                      ->orderBy('data')
                      ->get();
        
        $fluxo = [];
        $saldoAcumulado = 0;
        
        $dataAtual = $dataInicio->copy();
        while ($dataAtual->lte($dataFim)) {
            $dataStr = $dataAtual->toDateString();
            
            $receita = $dados->where('data', $dataStr)->where('tipo', 'receita')->first();
            $despesa = $dados->where('data', $dataStr)->where('tipo', 'despesa')->first();
            
            $valorReceita = $receita ? $receita->total : 0;
            $valorDespesa = $despesa ? $despesa->total : 0;
            $saldoDia = $valorReceita - $valorDespesa;
            $saldoAcumulado += $saldoDia;
            
            $fluxo[] = [
                'data' => $dataStr,
                'receitas' => $valorReceita,
                'despesas' => $valorDespesa,
                'saldo_dia' => $saldoDia,
                'saldo_acumulado' => $saldoAcumulado,
            ];
            
            $dataAtual->addDay();
        }
        
        return $fluxo;
    }
    
    /**
     * Calcular projeção financeira
     */
    private function calcularProjecao(Carbon $dataInicio, Carbon $dataFim): array
    {
        // Calcular médias do período anterior
        $diasPeriodo = $dataInicio->diffInDays($dataFim) + 1;
        $periodoAnterior = $dataInicio->copy()->subDays($diasPeriodo);
        
        $queryAnterior = $this->database->table('financeiro_transacoes')
            ->where('status', 'pago')
            ->whereBetween('data_transacao', [$periodoAnterior, $dataInicio->copy()->subDay()]);
        
        $this->aplicarFiltroTenant($queryAnterior);
        
        $receitaAnterior = $queryAnterior->where('tipo', 'receita')->sum('valor') ?? 0;
        $despesaAnterior = $queryAnterior->where('tipo', 'despesa')->sum('valor') ?? 0;
        
        $mediaReceitaDiaria = $receitaAnterior / $diasPeriodo;
        $mediaDespesaDiaria = $despesaAnterior / $diasPeriodo;
        
        // Projeção para os próximos 30 dias
        $diasProjecao = 30;
        $projecaoReceitas = $mediaReceitaDiaria * $diasProjecao;
        $projecaoDespesas = $mediaDespesaDiaria * $diasProjecao;
        
        return [
            'periodo_projecao' => $diasProjecao,
            'receitas_projetadas' => $projecaoReceitas,
            'despesas_projetadas' => $projecaoDespesas,
            'saldo_projetado' => $projecaoReceitas - $projecaoDespesas,
            'base_calculo' => [
                'media_receita_diaria' => $mediaReceitaDiaria,
                'media_despesa_diaria' => $mediaDespesaDiaria,
            ],
        ];
    }
    
    /**
     * Aplicar filtro de tenant (método renomeado para português)
     */
    private function aplicarFiltroTenant($query, string $campoTenant = 'tenant_id'): void
    {
        if ($this->tenantId) {
            $query->where($campoTenant, $this->tenantId);
        }
    }
}