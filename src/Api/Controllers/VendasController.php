<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use Carbon\Carbon;

/**
 * Controlador da API de Vendas
 * 
 * Gerencia operações de gestão de vendas
 * 
 * @package ERP\Api\Controllers
 */
final class VendasController extends ControladorBase
{
    /**
     * Listar vendas com paginação e filtros
     * GET /api/vendas/list
     */
    public function list(Request $request): Response
    {
        $this->autorizar('vendas.visualizar');
        
        $query = $this->database->table('vendas')
            ->select([
                'vendas.id',
                'vendas.numero_venda',
                'vendas.cliente_id',
                'vendas.status',
                'vendas.valor_subtotal',
                'vendas.valor_desconto',
                'vendas.valor_total',
                'vendas.forma_pagamento',
                'vendas.observacoes',
                'vendas.created_at',
                'vendas.updated_at',
                'clientes.nome as cliente_nome',
                'clientes.email as cliente_email',
            ])
            ->leftJoin('clientes', 'vendas.cliente_id', '=', 'clientes.id');
        
        $this->applyTenantFilter($query, 'vendas.tenant_id');
        
        // Apply filters
        $this->applyFilters($query, $request);
        
        // Apply search
        $this->applySearch($query, $request, [
            'vendas.numero_venda', 'clientes.nome', 'clientes.email'
        ]);
        
        // Apply date range
        $this->applyDateRange($query, $request, 'vendas.created_at');
        
        // Apply sorting
        $sortBy = $request->query('sort_by', 'vendas.created_at');
        $sortOrder = $request->query('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);
        
        $result = $this->getPaginatedResults($query, $request);
        
        // Add sales statistics
        $result['stats'] = $this->getSalesStats();
        
        return $this->paginated($result['data'], $result['pagination']);
    }
    
    /**
     * Get sale by ID
     * GET /api/vendas/{id}
     */
    public function show(Request $request): Response
    {
        $this->authorize('vendas.view');
        
        $id = $request->getAttribute('id');
        
        $sale = $this->database->table('vendas')
            ->leftJoin('clientes', 'vendas.cliente_id', '=', 'clientes.id')
            ->select([
                'vendas.*',
                'clientes.nome as cliente_nome',
                'clientes.email as cliente_email',
                'clientes.telefone as cliente_telefone',
                'clientes.documento as cliente_documento'
            ])
            ->where('vendas.id', $id);
        
        $this->applyTenantFilter($sale, 'vendas.tenant_id');
        
        $saleData = $sale->first();
        
        if (!$saleData) {
            return $this->error('Venda não encontrada', 404);
        }
        
        // Get sale items
        $items = $this->database->table('venda_itens')
            ->join('produtos', 'venda_itens.produto_id', '=', 'produtos.id')
            ->select([
                'venda_itens.*',
                'produtos.codigo as produto_codigo',
                'produtos.nome as produto_nome',
                'produtos.unidade_medida'
            ])
            ->where('venda_itens.venda_id', $id)
            ->get();
        
        // Get payment history
        $payments = $this->database->table('venda_pagamentos')
            ->where('venda_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();
        
        return $this->success([
            'sale' => $saleData,
            'items' => $items->toArray(),
            'payments' => $payments->toArray(),
        ]);
    }
    
    /**
     * Create new sale
     * POST /api/vendas/create
     */
    public function create(Request $request): Response
    {
        $this->authorize('vendas.create');
        
        $rules = [
            'cliente_id' => 'required|integer|exists:clientes,id',
            'items' => 'required|array|min:1',
            'items.*.produto_id' => 'required|integer|exists:produtos,id',
            'items.*.quantidade' => 'required|integer|min:1',
            'items.*.preco_unitario' => 'required|numeric|min:0',
            'items.*.desconto' => 'numeric|min:0|max:100',
            'desconto_geral' => 'numeric|min:0',
            'forma_pagamento' => 'required|in:dinheiro,cartao_credito,cartao_debito,pix,boleto,transferencia',
            'observacoes' => 'string|max:1000',
        ];
        
        $data = $this->validate($request, $rules);
        
        // Verify customer belongs to tenant
        $customer = $this->database->table('clientes')
            ->where('id', $data['cliente_id']);
        
        $this->applyTenantFilter($customer);
        
        if (!$customer->exists()) {
            return $this->error('Cliente não encontrado', 404);
        }
        
        $this->database->beginTransaction();
        
        try {
            // Calculate totals
            $valorSubtotal = 0;
            $items = [];
            
            foreach ($data['items'] as $item) {
                // Verify product exists and has stock
                $product = $this->database->table('produtos')
                    ->where('id', $item['produto_id'])
                    ->where('ativo', true);
                
                $this->applyTenantFilter($product);
                
                $productData = $product->first();
                
                if (!$productData) {
                    throw new \Exception("Produto ID {$item['produto_id']} não encontrado");
                }
                
                if ($productData->estoque_atual < $item['quantidade']) {
                    throw new \Exception("Estoque insuficiente para o produto {$productData->nome}. Disponível: {$productData->estoque_atual}");
                }
                
                $descontoItem = $item['desconto'] ?? 0;
                $valorItem = $item['quantidade'] * $item['preco_unitario'];
                $valorDesconto = $valorItem * ($descontoItem / 100);
                $valorTotal = $valorItem - $valorDesconto;
                
                $items[] = [
                    'produto_id' => $item['produto_id'],
                    'quantidade' => $item['quantidade'],
                    'preco_unitario' => $item['preco_unitario'],
                    'desconto_percentual' => $descontoItem,
                    'valor_desconto' => $valorDesconto,
                    'valor_total' => $valorTotal,
                ];
                
                $valorSubtotal += $valorItem;
            }
            
            $descontoGeral = $data['desconto_geral'] ?? 0;
            $valorDescontoGeral = $valorSubtotal * ($descontoGeral / 100);
            $valorTotal = $valorSubtotal - $valorDescontoGeral;
            
            // Create sale
            $saleData = [
                'numero_venda' => $this->generateSaleNumber(),
                'cliente_id' => $data['cliente_id'],
                'status' => 'pendente',
                'valor_subtotal' => $valorSubtotal,
                'desconto_percentual' => $descontoGeral,
                'valor_desconto' => $valorDescontoGeral,
                'valor_total' => $valorTotal,
                'forma_pagamento' => $data['forma_pagamento'],
                'observacoes' => $data['observacoes'] ?? null,
                'usuario_id' => $this->user->getAuthIdentifier(),
                'tenant_id' => $this->tenantId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
            
            $saleId = $this->database->table('vendas')->insertGetId($saleData);
            
            // Create sale items
            foreach ($items as $item) {
                $item['venda_id'] = $saleId;
                $item['created_at'] = Carbon::now();
                $this->database->table('venda_itens')->insert($item);
            }
            
            $this->database->commit();
            
            $sale = $this->database->table('vendas')
                ->where('id', $saleId)
                ->first();
            
            $this->clearCache('sales_*');
            
            return $this->success($sale, 'Venda criada com sucesso', 201);
            
        } catch (\Exception $e) {
            $this->database->rollback();
            return $this->error('Erro ao criar venda: ' . $e->getMessage(), 400);
        }
    }
    
    /**
     * Update sale
     * PUT /api/vendas/update/{id}
     */
    public function update(Request $request): Response
    {
        $this->authorize('vendas.update');
        
        $id = $request->getAttribute('id');
        
        $rules = [
            'status' => 'in:pendente,processando,concluida,cancelada',
            'observacoes' => 'string|max:1000',
        ];
        
        $data = $this->validate($request, $rules);
        
        // Check if sale exists
        $sale = $this->database->table('vendas')
            ->where('id', $id);
        
        $this->applyTenantFilter($sale);
        
        $saleData = $sale->first();
        
        if (!$saleData) {
            return $this->error('Venda não encontrada', 404);
        }
        
        // If changing status to concluded, update stock
        if (isset($data['status']) && $data['status'] === 'concluida' && $saleData->status !== 'concluida') {
            $this->processStockMovements($id);
        }
        
        // If changing status to cancelled, restore stock if was concluded
        if (isset($data['status']) && $data['status'] === 'cancelada' && $saleData->status === 'concluida') {
            $this->restoreStockMovements($id);
        }
        
        $data['updated_at'] = Carbon::now();
        
        $this->database->table('vendas')
            ->where('id', $id)
            ->update($data);
        
        $updatedSale = $this->database->table('vendas')
            ->where('id', $id)
            ->first();
        
        $this->clearCache('sales_*');
        
        return $this->success($updatedSale, 'Venda atualizada com sucesso');
    }
    
    /**
     * Delete sale
     * DELETE /api/vendas/delete/{id}
     */
    public function delete(Request $request): Response
    {
        $this->authorize('vendas.delete');
        
        $id = $request->getAttribute('id');
        
        $sale = $this->database->table('vendas')
            ->where('id', $id);
        
        $this->applyTenantFilter($sale);
        
        $saleData = $sale->first();
        
        if (!$saleData) {
            return $this->error('Venda não encontrada', 404);
        }
        
        // Can only delete pending sales
        if ($saleData->status !== 'pendente') {
            return $this->error('Apenas vendas pendentes podem ser removidas', 400);
        }
        
        $this->database->beginTransaction();
        
        try {
            // Delete sale items first
            $this->database->table('venda_itens')
                ->where('venda_id', $id)
                ->delete();
            
            // Delete payments
            $this->database->table('venda_pagamentos')
                ->where('venda_id', $id)
                ->delete();
            
            // Delete sale
            $this->database->table('vendas')
                ->where('id', $id)
                ->delete();
            
            $this->database->commit();
            
            $this->clearCache('sales_*');
            
            return $this->success(null, 'Venda removida com sucesso');
            
        } catch (\Exception $e) {
            $this->database->rollback();
            return $this->error('Erro ao remover venda: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get sales goals
     * GET /api/vendas/metas
     */
    public function metas(Request $request): Response
    {
        $this->authorize('vendas.view');
        
        return $this->cached('sales_goals', function() use ($request) {
            $period = $request->query('period', 'month'); // month, quarter, year
            
            $currentDate = Carbon::now();
            
            [$startDate, $endDate, $periodName] = match($period) {
                'month' => [$currentDate->copy()->startOfMonth(), $currentDate->copy()->endOfMonth(), $currentDate->format('M/Y')],
                'quarter' => [$currentDate->copy()->startOfQuarter(), $currentDate->copy()->endOfQuarter(), 'Q' . $currentDate->quarter . '/' . $currentDate->year],
                'year' => [$currentDate->copy()->startOfYear(), $currentDate->copy()->endOfYear(), $currentDate->year],
                default => [$currentDate->copy()->startOfMonth(), $currentDate->copy()->endOfMonth(), $currentDate->format('M/Y')],
            };
            
            // Get sales goals from configuration (this would typically be stored in database)
            $goals = $this->getSalesGoals($period);
            
            // Calculate actual sales
            $actualSales = $this->database->table('vendas')
                ->where('status', 'concluida')
                ->whereBetween('created_at', [$startDate, $endDate]);
            
            $this->applyTenantFilter($actualSales);
            
            $actualRevenue = $actualSales->sum('valor_total') ?? 0;
            $actualCount = $actualSales->count();
            
            // Calculate progress
            $revenueProgress = $goals['revenue'] > 0 ? ($actualRevenue / $goals['revenue']) * 100 : 0;
            $countProgress = $goals['sales_count'] > 0 ? ($actualCount / $goals['sales_count']) * 100 : 0;
            
            return [
                'period' => $period,
                'period_name' => $periodName,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'goals' => $goals,
                'actual' => [
                    'revenue' => $actualRevenue,
                    'sales_count' => $actualCount,
                ],
                'progress' => [
                    'revenue' => min(100, $revenueProgress),
                    'sales_count' => min(100, $countProgress),
                ],
                'remaining_days' => $currentDate->diffInDays($endDate, false),
            ];
        }, 3600); // Cache for 1 hour
    }
    
    /**
     * Generate sales report
     * GET /api/vendas/report
     */
    public function report(Request $request): Response
    {
        $this->authorize('vendas.view');
        
        $format = $request->query('format', 'json');
        $reportType = $request->query('type', 'summary');
        
        $data = $this->generateSalesReport($request, $reportType);
        
        if ($format === 'csv') {
            return $this->exportReportToCsv($data, "vendas_{$reportType}");
        }
        
        if ($format === 'pdf') {
            return $this->exportReportToPdf($data, "vendas_{$reportType}");
        }
        
        return $this->success($data);
    }
    
    /**
     * Get sales by salesperson
     * GET /api/vendas/por-vendedor
     */
    public function porVendedor(Request $request): Response
    {
        $this->authorize('vendas.view');
        
        $period = (int) $request->query('period', 30); // days
        $startDate = Carbon::now()->subDays($period)->startOfDay();
        
        return $this->cached("sales_by_salesperson_{$period}", function() use ($startDate) {
            $query = $this->database->table('vendas')
                ->join('users', 'vendas.usuario_id', '=', 'users.id')
                ->select([
                    'users.id as vendedor_id',
                    'users.name as vendedor_nome',
                ])
                ->selectRaw('
                    COUNT(*) as total_vendas,
                    SUM(CASE WHEN vendas.status = "concluida" THEN vendas.valor_total ELSE 0 END) as total_faturamento,
                    AVG(CASE WHEN vendas.status = "concluida" THEN vendas.valor_total ELSE NULL END) as ticket_medio,
                    COUNT(CASE WHEN vendas.status = "concluida" THEN 1 ELSE NULL END) as vendas_concluidas
                ')
                ->where('vendas.created_at', '>=', $startDate);
            
            $this->applyTenantFilter($query, 'vendas.tenant_id');
            
            return $query->groupBy('users.id', 'users.name')
                         ->orderBy('total_faturamento', 'desc')
                         ->get()
                         ->toArray();
        }, 1800); // Cache for 30 minutes
    }
    
    /**
     * Apply filters to query
     */
    private function applyFilters($query, Request $request): void
    {
        if ($status = $request->query('status')) {
            $query->where('vendas.status', $status);
        }
        
        if ($clientId = $request->query('cliente_id')) {
            $query->where('vendas.cliente_id', $clientId);
        }
        
        if ($paymentMethod = $request->query('forma_pagamento')) {
            $query->where('vendas.forma_pagamento', $paymentMethod);
        }
        
        if ($minValue = $request->query('valor_min')) {
            $query->where('vendas.valor_total', '>=', $minValue);
        }
        
        if ($maxValue = $request->query('valor_max')) {
            $query->where('vendas.valor_total', '<=', $maxValue);
        }
    }
    
    /**
     * Get sales statistics
     */
    private function getSalesStats(): array
    {
        $query = $this->database->table('vendas');
        $this->applyTenantFilter($query);
        
        $total = $query->count();
        $pendentes = $query->where('status', 'pendente')->count();
        $concluidas = $query->where('status', 'concluida')->count();
        $canceladas = $query->where('status', 'cancelada')->count();
        
        return [
            'total' => $total,
            'pendentes' => $pendentes,
            'concluidas' => $concluidas,
            'canceladas' => $canceladas,
        ];
    }
    
    /**
     * Generate unique sale number
     */
    private function generateSaleNumber(): string
    {
        $year = date('Y');
        $month = date('m');
        
        $lastSale = $this->database->table('vendas')
            ->where('numero_venda', 'LIKE', "{$year}{$month}%")
            ->orderBy('numero_venda', 'desc')
            ->first();
        
        if ($lastSale) {
            $lastNumber = (int) substr($lastSale->numero_venda, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }
        
        return $year . $month . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Process stock movements for concluded sale
     */
    private function processStockMovements(int $saleId): void
    {
        $items = $this->database->table('venda_itens')
            ->where('venda_id', $saleId)
            ->get();
        
        foreach ($items as $item) {
            // Update product stock
            $this->database->table('produtos')
                ->where('id', $item->produto_id)
                ->decrement('estoque_atual', $item->quantidade);
            
            // Create stock movement record
            $this->database->table('estoque_movimentacoes')->insert([
                'produto_id' => $item->produto_id,
                'tipo' => 'saida',
                'quantidade' => $item->quantidade,
                'motivo' => "Venda #{$saleId}",
                'venda_id' => $saleId,
                'usuario_id' => $this->user->getAuthIdentifier(),
                'tenant_id' => $this->tenantId,
                'data_movimentacao' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);
        }
    }
    
    /**
     * Restore stock movements for cancelled sale
     */
    private function restoreStockMovements(int $saleId): void
    {
        $items = $this->database->table('venda_itens')
            ->where('venda_id', $saleId)
            ->get();
        
        foreach ($items as $item) {
            // Restore product stock
            $this->database->table('produtos')
                ->where('id', $item->produto_id)
                ->increment('estoque_atual', $item->quantidade);
            
            // Create stock movement record
            $this->database->table('estoque_movimentacoes')->insert([
                'produto_id' => $item->produto_id,
                'tipo' => 'entrada',
                'quantidade' => $item->quantidade,
                'motivo' => "Cancelamento venda #{$saleId}",
                'venda_id' => $saleId,
                'usuario_id' => $this->user->getAuthIdentifier(),
                'tenant_id' => $this->tenantId,
                'data_movimentacao' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);
        }
    }
    
    /**
     * Get sales goals from configuration
     */
    private function getSalesGoals(string $period): array
    {
        // This would typically be retrieved from a configuration table
        // For now, return mock data
        return match($period) {
            'month' => [
                'revenue' => 50000,
                'sales_count' => 100,
            ],
            'quarter' => [
                'revenue' => 150000,
                'sales_count' => 300,
            ],
            'year' => [
                'revenue' => 600000,
                'sales_count' => 1200,
            ],
            default => [
                'revenue' => 50000,
                'sales_count' => 100,
            ],
        };
    }
    
    /**
     * Generate sales report data
     */
    private function generateSalesReport(Request $request, string $type): array
    {
        $startDate = $request->query('start_date', Carbon::now()->subDays(30)->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->toDateString());
        
        return match($type) {
            'summary' => $this->generateSummaryReport($startDate, $endDate),
            'detailed' => $this->generateDetailedReport($startDate, $endDate),
            'products' => $this->generateProductsReport($startDate, $endDate),
            default => $this->generateSummaryReport($startDate, $endDate),
        };
    }
    
    /**
     * Generate summary report
     */
    private function generateSummaryReport(string $startDate, string $endDate): array
    {
        $query = $this->database->table('vendas')
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59']);
        
        $this->applyTenantFilter($query);
        
        $total = $query->count();
        $revenue = $query->where('status', 'concluida')->sum('valor_total') ?? 0;
        $average = $total > 0 ? $revenue / $total : 0;
        
        $byStatus = $query->groupBy('status')
                         ->selectRaw('status, COUNT(*) as count, SUM(valor_total) as total')
                         ->get();
        
        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'summary' => [
                'total_sales' => $total,
                'total_revenue' => $revenue,
                'average_sale' => $average,
            ],
            'by_status' => $byStatus->toArray(),
        ];
    }
    
    /**
     * Generate detailed report
     */
    private function generateDetailedReport(string $startDate, string $endDate): array
    {
        $query = $this->database->table('vendas')
            ->join('clientes', 'vendas.cliente_id', '=', 'clientes.id')
            ->select([
                'vendas.*',
                'clientes.nome as cliente_nome'
            ])
            ->whereBetween('vendas.created_at', [$startDate, $endDate . ' 23:59:59']);
        
        $this->applyTenantFilter($query, 'vendas.tenant_id');
        
        return $query->orderBy('vendas.created_at', 'desc')->get()->toArray();
    }
    
    /**
     * Generate products report
     */
    private function generateProductsReport(string $startDate, string $endDate): array
    {
        $query = $this->database->table('venda_itens')
            ->join('vendas', 'venda_itens.venda_id', '=', 'vendas.id')
            ->join('produtos', 'venda_itens.produto_id', '=', 'produtos.id')
            ->select([
                'produtos.codigo',
                'produtos.nome',
            ])
            ->selectRaw('
                SUM(venda_itens.quantidade) as total_vendido,
                SUM(venda_itens.valor_total) as receita_total,
                COUNT(DISTINCT vendas.id) as num_vendas
            ')
            ->where('vendas.status', 'concluida')
            ->whereBetween('vendas.created_at', [$startDate, $endDate . ' 23:59:59']);
        
        $this->applyTenantFilter($query, 'vendas.tenant_id');
        
        return $query->groupBy('produtos.id', 'produtos.codigo', 'produtos.nome')
                     ->orderBy('total_vendido', 'desc')
                     ->get()
                     ->toArray();
    }
    
    /**
     * Export report to CSV
     */
    private function exportReportToCsv(array $data, string $filename): Response
    {
        // Implementation would depend on the report structure
        // This is a simplified version
        $csv = $this->arrayToCsv($data);
        
        return Response::download(
            tempnam(sys_get_temp_dir(), 'report'),
            "{$filename}_" . date('Y-m-d_H-i-s') . '.csv',
            ['Content-Type' => 'text/csv']
        )->setContent($csv);
    }
    
    /**
     * Export report to PDF
     */
    private function exportReportToPdf(array $data, string $filename): Response
    {
        // This would require a PDF library like TCPDF or DomPDF
        // For now, return JSON with PDF generation instructions
        return $this->success([
            'message' => 'PDF generation would be implemented here',
            'data' => $data,
            'filename' => $filename,
        ]);
    }
    
    /**
     * Convert array to CSV format
     */
    private function arrayToCsv(array $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        $csv = '';
        $headers = array_keys($data[0]);
        $csv .= implode(',', $headers) . "\n";
        
        foreach ($data as $row) {
            $csvRow = [];
            foreach ($row as $value) {
                $csvRow[] = '"' . str_replace('"', '""', $value) . '"';
            }
            $csv .= implode(',', $csvRow) . "\n";
        }
        
        return $csv;
    }
}