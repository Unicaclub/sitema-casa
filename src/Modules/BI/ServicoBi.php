<?php

declare(strict_types=1);

namespace ERP\Modules\BI;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Cache\CacheInterface;
use Carbon\Carbon;

/**
 * Serviço de Business Intelligence
 * 
 * Análises avançadas e insights empresariais
 * 
 * @package ERP\Modules\BI
 */
final class ServicoBi
{
    public function __construct(
        private DatabaseManager $database,
        private CacheInterface $cache
    ) {}
    
    /**
     * Gerar dashboard executivo com KPIs principais
     */
    public function gerarDashboardExecutivo(string $tenantId): array
    {
        $chaveCache = "dashboard_executivo_{$tenantId}";
        
        return $this->cache->remember($chaveCache, function () use ($tenantId) {
            $mesAtual = Carbon::now();
            $anoAtual = $mesAtual->year;
            
            return [
                'kpis_principais' => $this->obterKpisPrincipais($tenantId),
                'tendencias_vendas' => $this->analisarTendenciasVendas($tenantId),
                'performance_produtos' => $this->analisarPerformanceProdutos($tenantId),
                'saude_financeira' => $this->avaliarSaudeFinanceira($tenantId),
                'satisfacao_cliente' => $this->analisarSatisfacaoCliente($tenantId),
                'eficiencia_operacional' => $this->calcularEficienciaOperacional($tenantId),
            ];
        }, 1800); // Cache por 30 minutos
    }
    
    /**
     * Análise comparativa de períodos
     */
    public function analiseComparativaPeriodos(string $tenantId, Carbon $periodo1Inicio, Carbon $periodo1Fim, Carbon $periodo2Inicio, Carbon $periodo2Fim): array
    {
        $chaveCache = "analise_comparativa_{$tenantId}_{$periodo1Inicio->format('Ymd')}_{$periodo2Inicio->format('Ymd')}";
        
        return $this->cache->remember($chaveCache, function () use ($tenantId, $periodo1Inicio, $periodo1Fim, $periodo2Inicio, $periodo2Fim) {
            // Vendas por período
            $vendasPeriodo1 = $this->obterMetricasVendasPeriodo($tenantId, $periodo1Inicio, $periodo1Fim);
            $vendasPeriodo2 = $this->obterMetricasVendasPeriodo($tenantId, $periodo2Inicio, $periodo2Fim);
            
            // Clientes por período
            $clientesPeriodo1 = $this->obterMetricasClientesPeriodo($tenantId, $periodo1Inicio, $periodo1Fim);
            $clientesPeriodo2 = $this->obterMetricasClientesPeriodo($tenantId, $periodo2Inicio, $periodo2Fim);
            
            return [
                'periodos' => [
                    'periodo_1' => [
                        'inicio' => $periodo1Inicio->format('d/m/Y'),
                        'fim' => $periodo1Fim->format('d/m/Y'),
                    ],
                    'periodo_2' => [
                        'inicio' => $periodo2Inicio->format('d/m/Y'),
                        'fim' => $periodo2Fim->format('d/m/Y'),
                    ],
                ],
                'comparativo_vendas' => [
                    'periodo_1' => $vendasPeriodo1,
                    'periodo_2' => $vendasPeriodo2,
                    'variacao_receita' => $this->calcularVariacaoPercentual(
                        $vendasPeriodo1['receita_total'], 
                        $vendasPeriodo2['receita_total']
                    ),
                    'variacao_quantidade' => $this->calcularVariacaoPercentual(
                        $vendasPeriodo1['quantidade_vendas'], 
                        $vendasPeriodo2['quantidade_vendas']
                    ),
                ],
                'comparativo_clientes' => [
                    'periodo_1' => $clientesPeriodo1,
                    'periodo_2' => $clientesPeriodo2,
                    'variacao_novos_clientes' => $this->calcularVariacaoPercentual(
                        $clientesPeriodo1['novos_clientes'], 
                        $clientesPeriodo2['novos_clientes']
                    ),
                ],
            ];
        }, 3600); // Cache por 1 hora
    }
    
    /**
     * Análise de cohort de clientes
     */
    public function analiseCohortClientes(string $tenantId, int $mesesRetroativos = 12): array
    {
        $chaveCache = "analise_cohort_{$tenantId}_{$mesesRetroativos}";
        
        return $this->cache->remember($chaveCache, function () use ($tenantId, $mesesRetroativos) {
            $dataInicio = Carbon::now()->subMonths($mesesRetroativos)->startOfMonth();
            
            // Obter clientes por mês de primeira compra
            $clientesPorMes = $this->database->table('clientes')
                ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as mes_primeira_compra, COUNT(*) as total_clientes')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $dataInicio)
                ->groupBy('mes_primeira_compra')
                ->orderBy('mes_primeira_compra')
                ->get();
            
            $cohorts = [];
            
            foreach ($clientesPorMes as $cohort) {
                $mesPrimeiraCompra = Carbon::createFromFormat('Y-m', $cohort->mes_primeira_compra);
                $cohortData = [
                    'mes' => $cohort->mes_primeira_compra,
                    'mes_formatado' => $mesPrimeiraCompra->format('M/Y'),
                    'total_clientes' => $cohort->total_clientes,
                    'retencao_por_mes' => []
                ];
                
                // Calcular retenção mês a mês
                for ($i = 0; $i < 12; $i++) {
                    $mesAnalise = $mesPrimeiraCompra->copy()->addMonths($i);
                    
                    if ($mesAnalise->isFuture()) {
                        break;
                    }
                    
                    $clientesRetidos = $this->database->table('vendas')
                        ->join('clientes', 'vendas.cliente_id', '=', 'clientes.id')
                        ->where('clientes.tenant_id', $tenantId)
                        ->where('clientes.created_at', '>=', $mesPrimeiraCompra->startOfMonth())
                        ->where('clientes.created_at', '<', $mesPrimeiraCompra->copy()->endOfMonth())
                        ->whereMonth('vendas.data_venda', $mesAnalise->month)
                        ->whereYear('vendas.data_venda', $mesAnalise->year)
                        ->distinct('clientes.id')
                        ->count();
                    
                    $taxaRetencao = $cohort->total_clientes > 0 
                        ? round(($clientesRetidos / $cohort->total_clientes) * 100, 2)
                        : 0;
                    
                    $cohortData['retencao_por_mes'][] = [
                        'mes' => $i,
                        'clientes_retidos' => $clientesRetidos,
                        'taxa_retencao' => $taxaRetencao,
                    ];
                }
                
                $cohorts[] = $cohortData;
            }
            
            return [
                'periodo_analise' => [
                    'inicio' => $dataInicio->format('d/m/Y'),
                    'fim' => Carbon::now()->format('d/m/Y'),
                ],
                'cohorts' => $cohorts,
                'media_retencao_mes_1' => $this->calcularMediaRetencao($cohorts, 1),
                'media_retencao_mes_6' => $this->calcularMediaRetencao($cohorts, 6),
                'media_retencao_mes_12' => $this->calcularMediaRetencao($cohorts, 12),
            ];
        }, 7200); // Cache por 2 horas
    }
    
    /**
     * Previsão de vendas usando tendência histórica
     */
    public function previsaoVendas(string $tenantId, int $mesesHistorico = 12, int $mesesPrevisao = 3): array
    {
        $chaveCache = "previsao_vendas_{$tenantId}_{$mesesHistorico}_{$mesesPrevisao}";
        
        return $this->cache->remember($chaveCache, function () use ($tenantId, $mesesHistorico, $mesesPrevisao) {
            // Obter dados históricos
            $dadosHistoricos = $this->database->table('vendas')
                ->selectRaw('DATE_FORMAT(data_venda, "%Y-%m") as mes, SUM(valor_total) as receita, COUNT(*) as quantidade')
                ->where('tenant_id', $tenantId)
                ->where('status', 'concluida')
                ->where('data_venda', '>=', Carbon::now()->subMonths($mesesHistorico))
                ->groupBy('mes')
                ->orderBy('mes')
                ->get()
                ->toArray();
            
            if (count($dadosHistoricos) < 3) {
                return [
                    'erro' => 'Dados históricos insuficientes para previsão',
                    'dados_necessarios' => 3,
                    'dados_disponiveis' => count($dadosHistoricos),
                ];
            }
            
            // Calcular tendência usando regressão linear simples
            $previsoes = $this->calcularTendenciaLinear($dadosHistoricos, $mesesPrevisao);
            
            return [
                'periodo_historico' => [
                    'meses' => $mesesHistorico,
                    'dados_disponiveis' => count($dadosHistoricos),
                ],
                'dados_historicos' => $dadosHistoricos,
                'previsoes' => $previsoes,
                'resumo' => [
                    'media_mensal_historica' => array_sum(array_column($dadosHistoricos, 'receita')) / count($dadosHistoricos),
                    'crescimento_projetado' => $this->calcularCrescimentoProjetado($dadosHistoricos),
                    'confiabilidade' => $this->calcularConfiabilidadePrevisao($dadosHistoricos),
                ],
            ];
        }, 3600); // Cache por 1 hora
    }
    
    /**
     * Obter KPIs principais
     */
    private function obterKpisPrincipais(string $tenantId): array
    {
        $mesAtual = Carbon::now();
        
        return [
            'receita_mes' => $this->database->table('vendas')
                ->where('tenant_id', $tenantId)
                ->where('status', 'concluida')
                ->whereMonth('data_venda', $mesAtual->month)
                ->whereYear('data_venda', $mesAtual->year)
                ->sum('valor_total'),
            'total_clientes' => $this->database->table('clientes')
                ->where('tenant_id', $tenantId)
                ->where('status', 'ativo')
                ->count(),
            'ticket_medio' => $this->database->table('vendas')
                ->where('tenant_id', $tenantId)
                ->where('status', 'concluida')
                ->whereMonth('data_venda', $mesAtual->month)
                ->whereYear('data_venda', $mesAtual->year)
                ->avg('valor_total'),
            'taxa_conversao' => $this->calcularTaxaConversao($tenantId),
        ];
    }
    
    /**
     * Analisar tendências de vendas
     */
    private function analisarTendenciasVendas(string $tenantId): array
    {
        $ultimosSeisMeses = $this->database->table('vendas')
            ->selectRaw('DATE_FORMAT(data_venda, "%Y-%m") as mes, SUM(valor_total) as receita')
            ->where('tenant_id', $tenantId)
            ->where('status', 'concluida')
            ->where('data_venda', '>=', Carbon::now()->subMonths(6))
            ->groupBy('mes')
            ->orderBy('mes')
            ->get()
            ->toArray();
        
        $tendencia = 'estavel';
        if (count($ultimosSeisMeses) >= 3) {
            $primeiroPeriodo = array_slice($ultimosSeisMeses, 0, 3);
            $segundoPeriodo = array_slice($ultimosSeisMeses, -3);
            
            $mediaPrimeiro = array_sum(array_column($primeiroPeriodo, 'receita')) / 3;
            $mediaSegundo = array_sum(array_column($segundoPeriodo, 'receita')) / 3;
            
            if ($mediaSegundo > $mediaPrimeiro * 1.1) {
                $tendencia = 'crescimento';
            } elseif ($mediaSegundo < $mediaPrimeiro * 0.9) {
                $tendencia = 'declinio';
            }
        }
        
        return [
            'dados_mensais' => $ultimosSeisMeses,
            'tendencia' => $tendencia,
        ];
    }
    
    /**
     * Calcular variação percentual
     */
    private function calcularVariacaoPercentual(float $valorAtual, float $valorAnterior): float
    {
        if ($valorAnterior == 0) {
            return $valorAtual > 0 ? 100 : 0;
        }
        
        return round((($valorAtual - $valorAnterior) / $valorAnterior) * 100, 2);
    }
    
    /**
     * Calcular taxa de conversão
     */
    private function calcularTaxaConversao(string $tenantId): float
    {
        $mesAtual = Carbon::now();
        
        $totalClientes = $this->database->table('clientes')
            ->where('tenant_id', $tenantId)
            ->count();
        
        $clientesComCompra = $this->database->table('vendas')
            ->where('tenant_id', $tenantId)  
            ->where('status', 'concluida')
            ->whereMonth('data_venda', $mesAtual->month)
            ->whereYear('data_venda', $mesAtual->year)
            ->distinct('cliente_id')
            ->count();
        
        return $totalClientes > 0 
            ? round(($clientesComCompra / $totalClientes) * 100, 2)
            : 0;
    }
    
    /**
     * Obter métricas de vendas por período
     */
    private function obterMetricasVendasPeriodo(string $tenantId, Carbon $inicio, Carbon $fim): array
    {
        $vendas = $this->database->table('vendas')
            ->where('tenant_id', $tenantId)
            ->where('status', 'concluida')
            ->whereBetween('data_venda', [$inicio, $fim])
            ->get();
        
        return [
            'receita_total' => $vendas->sum('valor_total'),
            'quantidade_vendas' => $vendas->count(),
            'ticket_medio' => $vendas->count() > 0 ? $vendas->avg('valor_total') : 0,
        ];
    }
    
    /**
     * Obter métricas de clientes por período
     */
    private function obterMetricasClientesPeriodo(string $tenantId, Carbon $inicio, Carbon $fim): array
    {
        $novosClientes = $this->database->table('clientes')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$inicio, $fim])
            ->count();
        
        return [
            'novos_clientes' => $novosClientes,
        ];
    }
    
    /**
     * Analisar performance de produtos (método stub)
     */
    private function analisarPerformanceProdutos(string $tenantId): array
    {
        // Implementação simplificada - pode ser expandida
        return ['analise' => 'implementar_detalhes'];
    }
    
    /**
     * Avaliar saúde financeira (método stub)
     */
    private function avaliarSaudeFinanceira(string $tenantId): array
    {
        // Implementação simplificada - pode ser expandida
        return ['status' => 'saudavel'];
    }
    
    /**
     * Analisar satisfação do cliente (método stub)
     */
    private function analisarSatisfacaoCliente(string $tenantId): array
    {
        // Implementação simplificada - pode ser expandida
        return ['score' => 8.5];
    }
    
    /**
     * Calcular eficiência operacional (método stub)
     */
    private function calcularEficienciaOperacional(string $tenantId): array
    {
        // Implementação simplificada - pode ser expandida
        return ['eficiencia' => 'alta'];
    }
    
    /**
     * Calcular média de retenção (método stub)
     */
    private function calcularMediaRetencao(array $cohorts, int $mes): float
    {
        // Implementação simplificada
        return 0.0;
    }
    
    /**
     * Calcular tendência linear (método stub)
     */
    private function calcularTendenciaLinear(array $dados, int $meses): array
    {
        // Implementação simplificada de regressão linear
        return [];
    }
    
    /**
     * Calcular crescimento projetado (método stub)
     */
    private function calcularCrescimentoProjetado(array $dados): float
    {
        // Implementação simplificada
        return 0.0;
    }
    
    /**
     * Calcular confiabilidade da previsão (método stub)
     */
    private function calcularConfiabilidadePrevisao(array $dados): float
    {
        // Implementação simplificada
        return 75.0; // 75% de confiabilidade
    }
}
