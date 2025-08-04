<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use Carbon\Carbon;

/**
 * Controlador API Relatórios e BI
 * 
 * Gerencia relatórios e business intelligence
 * 
 * @package ERP\Api\Controllers
 */
final class RelatoriosController extends BaseController
{
    /**
     * Gerar relatório dinâmico
     * GET /api/relatorios/{tipo}
     */
    public function gerarRelatorio(Request $request): Response
    {
        $this->authorize('relatorios.visualizar');
        
        $tipo = $request->getAttribute('tipo');
        $formato = $request->query('formato', 'json');
        
        $dadosRelatorio = match($tipo) {
            'vendas' => $this->relatorioVendas($request),
            'financeiro' => $this->relatorioFinanceiro($request),
            'estoque' => $this->relatorioEstoque($request),
            'clientes' => $this->relatorioClientes($request),
            'produtos' => $this->relatorioProdutos($request),
            'performance' => $this->relatorioPerformance($request),
            default => throw new \InvalidArgumentException("Tipo de relatório '{$tipo}' não suportado")
        };
        
        if ($formato === 'csv') {
            return $this->exportarParaCsv($dadosRelatorio, "relatorio_{$tipo}");
        }
        
        if ($formato === 'pdf') {
            return $this->exportarParaPdf($dadosRelatorio, "relatorio_{$tipo}");
        }
        
        if ($formato === 'excel') {
            return $this->exportarParaExcel($dadosRelatorio, "relatorio_{$tipo}");
        }
        
        return $this->sucesso($dadosRelatorio);
    }
    
    /**
     * Exportar relatório
     * POST /api/relatorios/export
     */
    public function exportar(Request $request): Response
    {
        $this->authorize('relatorios.exportar');
        
        $regras = [
            'tipo' => 'required|in:vendas,financeiro,estoque,clientes,produtos,performance',
            'formato' => 'required|in:csv,pdf,excel',
            'data_inicio' => 'date',
            'data_fim' => 'date|after_or_equal:data_inicio',
            'filtros' => 'array',
        ];
        
        $dados = $this->validar($request, $regras);
        
        // Gerar relatório com filtros específicos
        $dadosRelatorio = $this->gerarRelatorioComFiltros($dados['tipo'], $dados);
        
        return match($dados['formato']) {
            'csv' => $this->exportarParaCsv($dadosRelatorio, "relatorio_{$dados['tipo']}"),
            'pdf' => $this->exportarParaPdf($dadosRelatorio, "relatorio_{$dados['tipo']}"),
            'excel' => $this->exportarParaExcel($dadosRelatorio, "relatorio_{$dados['tipo']}"),
        };
    }
    
    /**
     * Dashboard BI com KPIs avançados
     * GET /api/relatorios/dashboard-bi
     */
    public function dashboardBi(Request $request): Response
    {
        $this->authorize('relatorios.visualizar');
        
        $periodo = $request->query('periodo', '30');
        
        return $this->cached("dashboard_bi_{$periodo}", function() use ($periodo) {
            $dataInicio = Carbon::now()->subDays((int)$periodo)->startOfDay();
            
            return [
                'indicadores_vendas' => $this->obterIndicadoresVendas($dataInicio),
                'indicadores_financeiros' => $this->obterIndicadoresFinanceiros($dataInicio),
                'indicadores_estoque' => $this->obterIndicadoresEstoque(),
                'indicadores_clientes' => $this->obterIndicadoresClientes($dataInicio),
                'tendencias' => $this->analisarTendencias($dataInicio),
                'alertas_bi' => $this->obterAlertasBi(),
                'previsoes' => $this->calcularPrevisoes($dataInicio),
            ];
        }, 1800); // Cache por 30 minutos
    }
    
    /**
     * Análise comparativa de períodos
     * GET /api/relatorios/comparativo
     */
    public function comparativo(Request $request): Response
    {
        $this->authorize('relatorios.visualizar');
        
        $regras = [
            'periodo_atual_inicio' => 'required|date',
            'periodo_atual_fim' => 'required|date|after_or_equal:periodo_atual_inicio',
            'periodo_anterior_inicio' => 'required|date',
            'periodo_anterior_fim' => 'required|date|after_or_equal:periodo_anterior_inicio',
            'metricas' => 'array|in:vendas,receitas,despesas,clientes,produtos',
        ];
        
        $dados = $this->validar($request, $regras);
        
        $metricas = $dados['metricas'] ?? ['vendas', 'receitas', 'despesas'];
        
        $periodoAtual = [
            'inicio' => $dados['periodo_atual_inicio'],
            'fim' => $dados['periodo_atual_fim']
        ];
        
        $periodoAnterior = [
            'inicio' => $dados['periodo_anterior_inicio'],
            'fim' => $dados['periodo_anterior_fim']
        ];
        
        $comparacao = [];
        
        foreach ($metricas as $metrica) {
            $valorAtual = $this->calcularMetrica($metrica, $periodoAtual);
            $valorAnterior = $this->calcularMetrica($metrica, $periodoAnterior);
            
            $variacao = $valorAnterior > 0 ? (($valorAtual - $valorAnterior) / $valorAnterior) * 100 : 0;
            
            $comparacao[$metrica] = [
                'periodo_atual' => $valorAtual,
                'periodo_anterior' => $valorAnterior,
                'variacao_absoluta' => $valorAtual - $valorAnterior,
                'variacao_percentual' => $variacao,
                'tendencia' => $variacao > 0 ? 'crescimento' : ($variacao < 0 ? 'declinio' : 'estavel'),
            ];
        }
        
        return $this->sucesso([
            'periodo_atual' => $periodoAtual,
            'periodo_anterior' => $periodoAnterior,
            'comparacao' => $comparacao,
            'resumo' => $this->gerarResumoComparativo($comparacao),
        ]);
    }
    
    /**
     * Relatório de vendas
     */
    private function relatorioVendas(Request $request): array
    {
        $dataInicio = $request->query('data_inicio', Carbon::now()->subDays(30)->toDateString());
        $dataFim = $request->query('data_fim', Carbon::now()->toDateString());
        
        $query = $this->database->table('vendas')
            ->join('clientes', 'vendas.cliente_id', '=', 'clientes.id')
            ->join('users', 'vendas.usuario_id', '=', 'users.id')
            ->select([
                'vendas.numero_venda',
                'vendas.status',
                'vendas.valor_total',
                'vendas.forma_pagamento',
                'vendas.created_at',
                'clientes.nome as cliente_nome',
                'users.name as vendedor_nome'
            ])
            ->whereBetween('vendas.created_at', [$dataInicio, $dataFim . ' 23:59:59']);
        
        $this->aplicarFiltroTenant($query, 'vendas.tenant_id');
        
        $vendas = $query->orderBy('vendas.created_at', 'desc')->get();
        
        // Estatísticas
        $totalVendas = $vendas->count();
        $faturamentoTotal = $vendas->where('status', 'concluida')->sum('valor_total');
        $ticketMedio = $totalVendas > 0 ? $faturamentoTotal / $totalVendas : 0;
        
        return [
            'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
            'estatisticas' => [
                'total_vendas' => $totalVendas,
                'faturamento_total' => $faturamentoTotal,
                'ticket_medio' => $ticketMedio,
            ],
            'vendas' => $vendas->toArray(),
        ];
    }
    
    /**
     * Relatório financeiro
     */
    private function relatorioFinanceiro(Request $request): array
    {
        $dataInicio = $request->query('data_inicio', Carbon::now()->subDays(30)->toDateString());
        $dataFim = $request->query('data_fim', Carbon::now()->toDateString());
        
        $query = $this->database->table('financeiro_transacoes')
            ->join('financeiro_categorias', 'financeiro_transacoes.categoria_id', '=', 'financeiro_categorias.id')
            ->select([
                'financeiro_transacoes.tipo',
                'financeiro_transacoes.descricao',
                'financeiro_transacoes.valor',
                'financeiro_transacoes.status',
                'financeiro_transacoes.data_vencimento',
                'financeiro_transacoes.data_transacao',
                'financeiro_categorias.nome as categoria'
            ])
            ->whereBetween('financeiro_transacoes.data_transacao', [$dataInicio, $dataFim . ' 23:59:59']);
        
        $this->aplicarFiltroTenant($query, 'financeiro_transacoes.tenant_id');
        
        $transacoes = $query->orderBy('financeiro_transacoes.data_transacao', 'desc')->get();
        
        $receitas = $transacoes->where('tipo', 'receita')->where('status', 'pago');
        $despesas = $transacoes->where('tipo', 'despesa')->where('status', 'pago');
        
        return [
            'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
            'resumo' => [
                'total_receitas' => $receitas->sum('valor'),
                'total_despesas' => $despesas->sum('valor'),
                'saldo_liquido' => $receitas->sum('valor') - $despesas->sum('valor'),
            ],
            'transacoes' => $transacoes->toArray(),
        ];
    }
    
    /**
     * Relatório de estoque
     */
    private function relatorioEstoque(Request $request): array
    {
        $query = $this->database->table('produtos')
            ->leftJoin('categorias', 'produtos.categoria_id', '=', 'categorias.id')
            ->select([
                'produtos.codigo',
                'produtos.nome',
                'produtos.estoque_atual',
                'produtos.estoque_minimo',
                'produtos.preco_custo',
                'produtos.preco_venda',
                'categorias.nome as categoria'
            ])
            ->where('produtos.ativo', true);
        
        $this->aplicarFiltroTenant($query, 'produtos.tenant_id');
        
        $produtos = $query->get();
        
        $valorEstoque = $produtos->sum(function($produto) {
            return $produto->estoque_atual * $produto->preco_custo;
        });
        
        $produtosBaixoEstoque = $produtos->filter(function($produto) {
            return $produto->estoque_atual <= $produto->estoque_minimo;
        });
        
        return [
            'resumo' => [
                'total_produtos' => $produtos->count(),
                'valor_estoque' => $valorEstoque,
                'produtos_baixo_estoque' => $produtosBaixoEstoque->count(),
            ],
            'produtos' => $produtos->toArray(),
            'alertas' => $produtosBaixoEstoque->toArray(),
        ];
    }
    
    /**
     * Relatório de clientes
     */
    private function relatorioClientes(Request $request): array
    {
        $query = $this->database->table('clientes')
            ->select([
                'id',
                'nome',
                'email',
                'telefone',
                'tipo',
                'cidade',
                'estado',
                'status',
                'created_at'
            ]);
        
        $this->aplicarFiltroTenant($query);
        
        $clientes = $query->get();
        
        // Estatísticas de compras por cliente
        $clientesComCompras = $this->database->table('clientes')
            ->join('vendas', 'clientes.id', '=', 'vendas.cliente_id')
            ->select([
                'clientes.id',
                'clientes.nome'
            ])
            ->selectRaw('
                COUNT(vendas.id) as total_compras,
                SUM(vendas.valor_total) as total_gasto,
                AVG(vendas.valor_total) as ticket_medio,
                MAX(vendas.created_at) as ultima_compra
            ')
            ->where('vendas.status', 'concluida');
        
        $this->aplicarFiltroTenant($clientesComCompras, 'clientes.tenant_id');
        
        $estatisticasClientes = $clientesComCompras->groupBy('clientes.id', 'clientes.nome')
                                                  ->orderBy('total_gasto', 'desc')
                                                  ->get();
        
        return [
            'resumo' => [
                'total_clientes' => $clientes->count(),
                'clientes_ativos' => $clientes->where('status', 'ativo')->count(),
                'clientes_com_compras' => $estatisticasClientes->count(),
            ],
            'clientes' => $clientes->toArray(),
            'top_clientes' => $estatisticasClientes->take(20)->toArray(),
        ];
    }
    
    /**
     * Relatório de produtos
     */
    private function relatorioProdutos(Request $request): array
    {
        $dataInicio = $request->query('data_inicio', Carbon::now()->subDays(30)->toDateString());
        $dataFim = $request->query('data_fim', Carbon::now()->toDateString());
        
        $query = $this->database->table('produtos')
            ->leftJoin('venda_itens', 'produtos.id', '=', 'venda_itens.produto_id')
            ->leftJoin('vendas', function($join) use ($dataInicio, $dataFim) {
                $join->on('venda_itens.venda_id', '=', 'vendas.id')
                     ->where('vendas.status', 'concluida')
                     ->whereBetween('vendas.created_at', [$dataInicio, $dataFim . ' 23:59:59']);
            })
            ->select([
                'produtos.codigo',
                'produtos.nome',
                'produtos.preco_venda',
                'produtos.preco_custo',
                'produtos.estoque_atual'
            ])
            ->selectRaw('
                COALESCE(SUM(venda_itens.quantidade), 0) as quantidade_vendida,
                COALESCE(SUM(venda_itens.valor_total), 0) as receita_gerada,
                COUNT(DISTINCT vendas.id) as numero_vendas
            ');
        
        $this->aplicarFiltroTenant($query, 'produtos.tenant_id');
        
        $produtos = $query->groupBy('produtos.id', 'produtos.codigo', 'produtos.nome', 'produtos.preco_venda', 'produtos.preco_custo', 'produtos.estoque_atual')
                          ->orderBy('quantidade_vendida', 'desc')
                          ->get();
        
        $totalReceita = $produtos->sum('receita_gerada');
        $produtosMaisVendidos = $produtos->where('quantidade_vendida', '>', 0)->take(10);
        
        return [
            'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
            'resumo' => [
                'total_produtos' => $produtos->count(),
                'produtos_vendidos' => $produtos->where('quantidade_vendida', '>', 0)->count(),
                'receita_total' => $totalReceita,
            ],
            'produtos' => $produtos->toArray(),
            'mais_vendidos' => $produtosMaisVendidos->toArray(),
        ];
    }
    
    /**
     * Relatório de performance
     */
    private function relatorioPerformance(Request $request): array
    {
        $dataInicio = Carbon::now()->subDays(30)->startOfDay();
        
        return [
            'vendas' => $this->obterIndicadoresVendas($dataInicio),
            'financeiro' => $this->obterIndicadoresFinanceiros($dataInicio),
            'operacional' => $this->obterIndicadoresOperacionais($dataInicio),
            'crescimento' => $this->calcularIndicadoresCrescimento($dataInicio),
        ];
    }
    
    /**
     * Exportar para CSV
     */
    private function exportarParaCsv(array $dados, string $nomeArquivo): Response
    {
        $csv = $this->converterArrayParaCsv($dados);
        
        return Response::download(
            tempnam(sys_get_temp_dir(), 'relatorio'),
            "{$nomeArquivo}_" . date('Y-m-d_H-i-s') . '.csv',
            ['Content-Type' => 'text/csv; charset=utf-8']
        )->setContent("\xEF\xBB\xBF" . $csv); // BOM para UTF-8
    }
    
    /**
     * Exportar para PDF
     */
    private function exportarParaPdf(array $dados, string $nomeArquivo): Response
    {
        // Implementação seria feita com biblioteca como TCPDF ou DomPDF
        $html = $this->gerarHtmlRelatorio($dados, $nomeArquivo);
        
        return $this->sucesso([
            'mensagem' => 'Geração de PDF seria implementada aqui',
            'html_preview' => $html,
            'nome_arquivo' => $nomeArquivo,
        ]);
    }
    
    /**
     * Exportar para Excel
     */
    private function exportarParaExcel(array $dados, string $nomeArquivo): Response
    {
        // Implementação seria feita com PhpSpreadsheet
        return $this->sucesso([
            'mensagem' => 'Geração de Excel seria implementada aqui',
            'dados' => $dados,
            'nome_arquivo' => $nomeArquivo,
        ]);
    }
    
    /**
     * Obter indicadores de vendas
     */
    private function obterIndicadoresVendas(Carbon $dataInicio): array
    {
        $query = $this->database->table('vendas')
            ->where('created_at', '>=', $dataInicio);
        
        $this->aplicarFiltroTenant($query);
        
        $totalVendas = $query->count();
        $vendasConcluidas = $query->where('status', 'concluida')->count();
        $faturamento = $query->where('status', 'concluida')->sum('valor_total') ?? 0;
        
        $taxaConversao = $totalVendas > 0 ? ($vendasConcluidas / $totalVendas) * 100 : 0;
        $ticketMedio = $vendasConcluidas > 0 ? $faturamento / $vendasConcluidas : 0;
        
        return [
            'total_vendas' => $totalVendas,
            'vendas_concluidas' => $vendasConcluidas,
            'faturamento' => $faturamento,
            'taxa_conversao' => $taxaConversao,
            'ticket_medio' => $ticketMedio,
        ];
    }
    
    /**
     * Obter indicadores financeiros
     */
    private function obterIndicadoresFinanceiros(Carbon $dataInicio): array
    {
        $query = $this->database->table('financeiro_transacoes')
            ->where('data_transacao', '>=', $dataInicio)
            ->where('status', 'pago');
        
        $this->aplicarFiltroTenant($query);
        
        $receitas = $query->where('tipo', 'receita')->sum('valor') ?? 0;
        $despesas = $query->where('tipo', 'despesa')->sum('valor') ?? 0;
        $lucroLiquido = $receitas - $despesas;
        $margemLiquida = $receitas > 0 ? ($lucroLiquido / $receitas) * 100 : 0;
        
        return [
            'receitas' => $receitas,
            'despesas' => $despesas,
            'lucro_liquido' => $lucroLiquido,
            'margem_liquida' => $margemLiquida,
        ];
    }
    
    /**
     * Obter indicadores de estoque
     */
    private function obterIndicadoresEstoque(): array
    {
        $query = $this->database->table('produtos')
            ->where('ativo', true);
        
        $this->aplicarFiltroTenant($query);
        
        $totalProdutos = $query->count();
        $produtosBaixoEstoque = $query->where('estoque_atual', '<=', $this->database->raw('estoque_minimo'))->count();
        $produtosSemEstoque = $query->where('estoque_atual', '<=', 0)->count();
        
        return [
            'total_produtos' => $totalProdutos,
            'baixo_estoque' => $produtosBaixoEstoque,
            'sem_estoque' => $produtosSemEstoque,
            'estoque_normal' => $totalProdutos - $produtosBaixoEstoque,
        ];
    }
    
    /**
     * Obter indicadores de clientes
     */
    private function obterIndicadoresClientes(Carbon $dataInicio): array
    {
        $queryClientes = $this->database->table('clientes');
        $this->aplicarFiltroTenant($queryClientes);
        
        $totalClientes = $queryClientes->count();
        $novosClientes = $queryClientes->where('created_at', '>=', $dataInicio)->count();
        
        return [
            'total_clientes' => $totalClientes,
            'novos_clientes' => $novosClientes,
            'taxa_crescimento' => $totalClientes > 0 ? ($novosClientes / $totalClientes) * 100 : 0,
        ];
    }
    
    /**
     * Aplicar filtro de tenant
     */
    private function aplicarFiltroTenant($query, string $campoTenant = 'tenant_id'): void
    {
        if ($this->tenantId) {
            $query->where($campoTenant, $this->tenantId);
        }
    }
    
    /**
     * Converter array para CSV
     */
    private function converterArrayParaCsv(array $dados): string
    {
        if (empty($dados)) {
            return '';
        }
        
        // Se os dados têm estrutura complexa, simplificar
        if (isset($dados['resumo']) || isset($dados['periodo'])) {
            $dados = $this->simplificarDadosParaCsv($dados);
        }
        
        $csv = '';
        $cabecalhos = array_keys($dados[0] ?? []);
        $csv .= implode(',', $cabecalhos) . "\n";
        
        foreach ($dados as $linha) {
            $csvLinha = [];
            foreach ($linha as $valor) {
                $csvLinha[] = '"' . str_replace('"', '""', (string)$valor) . '"';
            }
            $csv .= implode(',', $csvLinha) . "\n";
        }
        
        return $csv;
    }
    
    /**
     * Simplificar dados complexos para CSV
     */
    private function simplificarDadosParaCsv(array $dados): array
    {
        // Extrair apenas os dados tabulares do array complexo
        if (isset($dados['vendas']) && is_array($dados['vendas'])) {
            return $dados['vendas'];
        }
        
        if (isset($dados['transacoes']) && is_array($dados['transacoes'])) {
            return $dados['transacoes'];
        }
        
        if (isset($dados['produtos']) && is_array($dados['produtos'])) {
            return $dados['produtos'];
        }
        
        if (isset($dados['clientes']) && is_array($dados['clientes'])) {
            return $dados['clientes'];
        }
        
        // Se não encontrar dados tabulares, retornar os dados como estão
        return is_array($dados) && !empty($dados) && is_array($dados[0]) ? $dados : [$dados];
    }
    
    /**
     * Gerar HTML para relatório
     */
    private function gerarHtmlRelatorio(array $dados, string $titulo): string
    {
        $html = "<!DOCTYPE html>
        <html>
        <head>
            <title>{$titulo}</title>
            <meta charset='utf-8'>
            <style>
                body { font-family: Arial, sans-serif; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h1>{$titulo}</h1>
            <p>Gerado em: " . date('d/m/Y H:i:s') . "</p>";
        
        // Adicionar resumo se existir
        if (isset($dados['resumo'])) {
            $html .= "<h2>Resumo</h2><ul>";
            foreach ($dados['resumo'] as $chave => $valor) {
                $html .= "<li><strong>{$chave}:</strong> {$valor}</li>";
            }
            $html .= "</ul>";
        }
        
        $html .= "</body></html>";
        
        return $html;
    }
    
    // Métodos auxiliares adicionais seriam implementados aqui
    private function analisarTendencias(Carbon $dataInicio): array { return []; }
    private function obterAlertasBi(): array { return []; }
    private function calcularPrevisoes(Carbon $dataInicio): array { return []; }
    private function gerarRelatorioComFiltros(string $tipo, array $dados): array { return []; }
    private function calcularMetrica(string $metrica, array $periodo): float { return 0.0; }
    private function gerarResumoComparativo(array $comparacao): array { return []; }
    private function obterIndicadoresOperacionais(Carbon $dataInicio): array { return []; }
    private function calcularIndicadoresCrescimento(Carbon $dataInicio): array { return []; }
}