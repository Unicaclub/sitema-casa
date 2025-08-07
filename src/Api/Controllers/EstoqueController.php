<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use Carbon\Carbon;

/**
 * Controlador da API do Estoque
 * 
 * Gerencia operações de controle de estoque
 * 
 * @package ERP\Api\Controllers
 */
final class EstoqueController extends ControladorBase
{
    /**
     * Listar produtos com paginação e filtros
     * GET /api/estoque/list
     */
    public function list(Request $request): Response
    {
        $this->autorizar('estoque.visualizar');
        
        $query = $this->database->table('produtos')
            ->select([
                'id',
                'codigo',
                'nome',
                'descricao',
                'categoria_id',
                'marca',
                'unidade_medida',
                'preco_venda',
                'preco_custo',
                'estoque_atual',
                'estoque_minimo',
                'estoque_maximo',
                'ativo',
                'created_at',
                'updated_at'
            ])
            ->leftJoin('categorias', 'produtos.categoria_id', '=', 'categorias.id')
            ->addSelect('categorias.nome as categoria_nome');
        
        $this->applyTenantFilter($query, 'produtos.tenant_id');
        
        // Apply filters
        $this->applyFilters($query, $request);
        
        // Apply search
        $this->applySearch($query, $request, [
            'produtos.nome', 'produtos.codigo', 'produtos.descricao', 'produtos.marca'
        ]);
        
        // Apply sorting
        $sortBy = $request->query('sort_by', 'produtos.created_at');
        $sortOrder = $request->query('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);
        
        $result = $this->getPaginatedResults($query, $request);
        
        // Add inventory statistics
        $result['stats'] = $this->getInventoryStats();
        
        return $this->paginated($result['data'], $result['pagination']);
    }
    
    /**
     * Get product by ID
     * GET /api/estoque/{id}
     */
    public function show(Request $request): Response
    {
        $this->authorize('estoque.view');
        
        $id = $request->getAttribute('id');
        
        $product = $this->database->table('produtos')
            ->leftJoin('categorias', 'produtos.categoria_id', '=', 'categorias.id')
            ->select('produtos.*', 'categorias.nome as categoria_nome')
            ->where('produtos.id', $id);
        
        $this->applyTenantFilter($product, 'produtos.tenant_id');
        
        $productData = $product->first();
        
        if (! $productData) {
            return $this->error('Produto não encontrado', 404);
        }
        
        // Get stock movements
        $movements = $this->database->table('estoque_movimentacoes')
            ->where('produto_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
        
        // Get product statistics
        $stats = $this->getProductStatistics($id);
        
        return $this->success([
            'product' => $productData,
            'movements' => $movements->toArray(),
            'statistics' => $stats,
        ]);
    }
    
    /**
     * Create new product
     * POST /api/estoque/create
     */
    public function create(Request $request): Response
    {
        $this->authorize('estoque.create');
        
        $rules = [
            'codigo' => 'required|string|max:50',
            'nome' => 'required|string|max:255',
            'descricao' => 'string|max:1000',
            'categoria_id' => 'integer|exists:categorias,id',
            'marca' => 'string|max:100',
            'unidade_medida' => 'required|string|max:10',
            'preco_venda' => 'required|numeric|min:0',
            'preco_custo' => 'required|numeric|min:0',
            'estoque_inicial' => 'required|integer|min:0',
            'estoque_minimo' => 'required|integer|min:0',
            'estoque_maximo' => 'integer|min:0',
            'peso' => 'numeric|min:0',
            'dimensoes' => 'string|max:100',
            'observacoes' => 'string|max:1000',
        ];
        
        $data = $this->validate($request, $rules);
        
        // Check if code already exists
        $existingProduct = $this->database->table('produtos')
            ->where('codigo', $data['codigo']);
        
        $this->applyTenantFilter($existingProduct);
        
        if ($existingProduct->exists()) {
            return $this->validationError(['codigo' => ['Código já está em uso']]);
        }
        
        $data['tenant_id'] = $this->tenantId;
        $data['estoque_atual'] = $data['estoque_inicial'];
        $data['ativo'] = true;
        $data['created_at'] = Carbon::now();
        $data['updated_at'] = Carbon::now();
        
        unset($data['estoque_inicial']); // Remove from product data
        
        $productId = $this->database->table('produtos')->insertGetId($data);
        
        // Create initial stock movement
        $this->createStockMovement([
            'produto_id' => $productId,
            'tipo' => 'entrada',
            'quantidade' => $data['estoque_atual'],
            'motivo' => 'Estoque inicial',
            'usuario_id' => $this->user->getAuthIdentifier(),
            'data_movimentacao' => Carbon::now(),
        ]);
        
        $product = $this->database->table('produtos')
            ->where('id', $productId)
            ->first();
        
        $this->clearCache('inventory_*');
        
        return $this->success($product, 'Produto criado com sucesso', 201);
    }
    
    /**
     * Update product
     * PUT /api/estoque/update/{id}
     */
    public function update(Request $request): Response
    {
        $this->authorize('estoque.update');
        
        $id = $request->getAttribute('id');
        
        $rules = [
            'codigo' => 'string|max:50',
            'nome' => 'string|max:255',
            'descricao' => 'string|max:1000',
            'categoria_id' => 'integer|exists:categorias,id',
            'marca' => 'string|max:100',
            'unidade_medida' => 'string|max:10',
            'preco_venda' => 'numeric|min:0',
            'preco_custo' => 'numeric|min:0',
            'estoque_minimo' => 'integer|min:0',
            'estoque_maximo' => 'integer|min:0',
            'peso' => 'numeric|min:0',
            'dimensoes' => 'string|max:100',
            'ativo' => 'boolean',
            'observacoes' => 'string|max:1000',
        ];
        
        $data = $this->validate($request, $rules);
        
        // Check if product exists
        $product = $this->database->table('produtos')
            ->where('id', $id);
        
        $this->applyTenantFilter($product);
        
        if (! $product->exists()) {
            return $this->error('Produto não encontrado', 404);
        }
        
        // Check code uniqueness if provided
        if (isset($data['codigo'])) {
            $existingProduct = $this->database->table('produtos')
                ->where('codigo', $data['codigo'])
                ->where('id', '!=', $id);
            
            $this->applyTenantFilter($existingProduct);
            
            if ($existingProduct->exists()) {
                return $this->validationError(['codigo' => ['Código já está em uso']]);
            }
        }
        
        $data['updated_at'] = Carbon::now();
        
        $this->database->table('produtos')
            ->where('id', $id)
            ->update($data);
        
        $updatedProduct = $this->database->table('produtos')
            ->where('id', $id)
            ->first();
        
        $this->clearCache('inventory_*');
        
        return $this->success($updatedProduct, 'Produto atualizado com sucesso');
    }
    
    /**
     * Delete product
     * DELETE /api/estoque/delete/{id}
     */
    public function delete(Request $request): Response
    {
        $this->authorize('estoque.delete');
        
        $id = $request->getAttribute('id');
        
        $product = $this->database->table('produtos')
            ->where('id', $id);
        
        $this->applyTenantFilter($product);
        
        if (! $product->exists()) {
            return $this->error('Produto não encontrado', 404);
        }
        
        // Check if product has sales
        $hasSales = $this->database->table('venda_itens')
            ->where('produto_id', $id)
            ->exists();
        
        if ($hasSales) {
            // Soft delete instead of hard delete
            $this->database->table('produtos')
                ->where('id', $id)
                ->update([
                    'ativo' => false,
                    'deleted_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            
            $message = 'Produto desativado com sucesso (possui histórico de vendas)';
        } else {
            // Hard delete if no sales
            $this->database->table('produtos')
                ->where('id', $id)
                ->delete();
            
            $message = 'Produto removido com sucesso';
        }
        
        $this->clearCache('inventory_*');
        
        return $this->success(null, $message);
    }
    
    /**
     * Get stock alerts
     * GET /api/estoque/alerts
     */
    public function alerts(Request $request): Response
    {
        $this->authorize('estoque.view');
        
        $query = $this->database->table('produtos')
            ->select([
                'id',
                'codigo',
                'nome',
                'estoque_atual',
                'estoque_minimo',
                'unidade_medida',
                'preco_venda'
            ])
            ->where('ativo', true)
            ->where(function($q) {
                $q->where('estoque_atual', '<=', $this->database->raw('estoque_minimo'))
                  ->orWhere('estoque_atual', '<=', 0);
            });
        
        $this->applyTenantFilter($query);
        
        $alerts = $query->orderBy('estoque_atual', 'asc')->get();
        
        $alertData = $alerts->map(function($product) {
            $alertType = 'low_stock';
            if ($product->estoque_atual <= 0) {
                $alertType = 'out_of_stock';
            }
            
            return [
                'id' => $product->id,
                'codigo' => $product->codigo,
                'nome' => $product->nome,
                'estoque_atual' => $product->estoque_atual,
                'estoque_minimo' => $product->estoque_minimo,
                'unidade_medida' => $product->unidade_medida,
                'preco_venda' => $product->preco_venda,
                'alert_type' => $alertType,
                'urgency' => $this->calculateUrgency($product),
            ];
        });
        
        return $this->success([
            'alerts' => $alertData->toArray(),
            'summary' => [
                'total_alerts' => $alerts->count(),
                'out_of_stock' => $alerts->where('estoque_atual', '<=', 0)->count(),
                'low_stock' => $alerts->where('estoque_atual', '>', 0)->count(),
            ]
        ]);
    }
    
    /**
     * Create stock movement
     * POST /api/estoque/movimentacao
     */
    public function movimentacao(Request $request): Response
    {
        $this->authorize('estoque.movement');
        
        $rules = [
            'produto_id' => 'required|integer|exists:produtos,id',
            'tipo' => 'required|in:entrada,saida,ajuste',
            'quantidade' => 'required|integer|min:1',
            'motivo' => 'required|string|max:255',
            'observacoes' => 'string|max:1000',
            'preco_unitario' => 'numeric|min:0',
        ];
        
        $data = $this->validate($request, $rules);
        
        // Verify product exists and belongs to tenant
        $product = $this->database->table('produtos')
            ->where('id', $data['produto_id']);
        
        $this->applyTenantFilter($product);
        
        $productData = $product->first();
        
        if (! $productData) {
            return $this->error('Produto não encontrado', 404);
        }
        
        // Calculate new stock based on movement type
        $currentStock = $productData->estoque_atual;
        $quantity = $data['quantidade'];
        
        $newStock = match($data['tipo']) {
            'entrada' => $currentStock + $quantity,
            'saida' => $currentStock - $quantity,
            'ajuste' => $quantity, // For adjustments, quantity is the new total
        };
        
        // Validate stock won't go negative
        if ($newStock < 0) {
            return $this->validationError(['quantidade' => ['Estoque insuficiente para esta operação']]);
        }
        
        // Create movement record
        $movementData = [
            'produto_id' => $data['produto_id'],
            'tipo' => $data['tipo'],
            'quantidade' => $quantity,
            'estoque_anterior' => $currentStock,
            'estoque_atual' => $newStock,
            'motivo' => $data['motivo'],
            'observacoes' => $data['observacoes'] ?? null,
            'preco_unitario' => $data['preco_unitario'] ?? $productData->preco_custo,
            'usuario_id' => $this->user->getAuthIdentifier(),
            'tenant_id' => $this->tenantId,
            'data_movimentacao' => Carbon::now(),
            'created_at' => Carbon::now(),
        ];
        
        $this->database->beginTransaction();
        
        try {
            // Create movement
            $movementId = $this->database->table('estoque_movimentacoes')
                ->insertGetId($movementData);
            
            // Update product stock
            $this->database->table('produtos')
                ->where('id', $data['produto_id'])
                ->update([
                    'estoque_atual' => $newStock,
                    'updated_at' => Carbon::now(),
                ]);
            
            $this->database->commit();
            
            $movement = $this->database->table('estoque_movimentacoes')
                ->where('id', $movementId)
                ->first();
            
            $this->clearCache('inventory_*');
            
            return $this->success($movement, 'Movimentação realizada com sucesso', 201);
            
        } catch (\Exception $e) {
            $this->database->rollback();
            return $this->error('Erro ao realizar movimentação: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get stock movement history
     * GET /api/estoque/movimentacao/history
     */
    public function movementHistory(Request $request): Response
    {
        $this->authorize('estoque.view');
        
        $query = $this->database->table('estoque_movimentacoes')
            ->join('produtos', 'estoque_movimentacoes.produto_id', '=', 'produtos.id')
            ->join('users', 'estoque_movimentacoes.usuario_id', '=', 'users.id')
            ->select([
                'estoque_movimentacoes.*',
                'produtos.codigo as produto_codigo',
                'produtos.nome as produto_nome',
                'users.name as usuario_nome'
            ]);
        
        $this->applyTenantFilter($query, 'estoque_movimentacoes.tenant_id');
        
        // Apply filters
        if ($productId = $request->query('produto_id')) {
            $query->where('estoque_movimentacoes.produto_id', $productId);
        }
        
        if ($type = $request->query('tipo')) {
            $query->where('estoque_movimentacoes.tipo', $type);
        }
        
        $this->applyDateRange($query, $request, 'estoque_movimentacoes.data_movimentacao');
        
        $query->orderBy('estoque_movimentacoes.data_movimentacao', 'desc');
        
        $result = $this->getPaginatedResults($query, $request);
        
        return $this->paginated($result['data'], $result['pagination']);
    }
    
    /**
     * Get inventory valuation report
     * GET /api/estoque/valuation
     */
    public function valuation(Request $request): Response
    {
        $this->authorize('estoque.view');
        
        return $this->cached('inventory_valuation', function () {
            $query = $this->database->table('produtos')
                ->select([
                    'categoria_id',
                    'categorias.nome as categoria_nome'
                ])
                ->selectRaw('
                    COUNT(*) as total_produtos,
                    SUM(estoque_atual) as total_quantidade,
                    SUM(estoque_atual * preco_custo) as valor_custo,
                    SUM(estoque_atual * preco_venda) as valor_venda
                ')
                ->leftJoin('categorias', 'produtos.categoria_id', '=', 'categorias.id')
                ->where('produtos.ativo', true);
            
            $this->applyTenantFilter($query, 'produtos.tenant_id');
            
            $byCategory = $query->groupBy('categoria_id', 'categorias.nome')
                               ->get()
                               ->toArray();
            
            // Total valuation
            $totalQuery = $this->database->table('produtos')
                ->where('ativo', true);
            
            $this->applyTenantFilter($totalQuery);
            
            $total = $totalQuery->selectRaw('
                COUNT(*) as total_produtos,
                SUM(estoque_atual) as total_quantidade,
                SUM(estoque_atual * preco_custo) as valor_custo_total,
                SUM(estoque_atual * preco_venda) as valor_venda_total
            ')->first();
            
            return [
                'by_category' => $byCategory,
                'total' => $total,
                'margin' => $total->valor_custo_total > 0 
                    ? (($total->valor_venda_total - $total->valor_custo_total) / $total->valor_custo_total) * 100 
                    : 0,
            ];
        }, 3600); // Cache for 1 hour
    }
    
    /**
     * Apply filters to query
     */
    private function applyFilters($query, Request $request): void
    {
        if ($category = $request->query('categoria_id')) {
            $query->where('produtos.categoria_id', $category);
        }
        
        if ($status = $request->query('status')) {
            if ($status === 'ativo') {
                $query->where('produtos.ativo', true);
            } elseif ($status === 'inativo') {
                $query->where('produtos.ativo', false);
            }
        }
        
        if ($stock = $request->query('stock_status')) {
            if ($stock === 'low') {
                $query->where('produtos.estoque_atual', '<=', $this->database->raw('produtos.estoque_minimo'));
            } elseif ($stock === 'out') {
                $query->where('produtos.estoque_atual', '<=', 0);
            } elseif ($stock === 'normal') {
                $query->where('produtos.estoque_atual', '>', $this->database->raw('produtos.estoque_minimo'));
            }
        }
        
        if ($brand = $request->query('marca')) {
            $query->where('produtos.marca', 'LIKE', "%{$brand}%");
        }
    }
    
    /**
     * Get inventory statistics
     */
    private function getInventoryStats(): array
    {
        $query = $this->database->table('produtos')
            ->where('ativo', true);
        
        $this->applyTenantFilter($query);
        
        $total = $query->count();
        $lowStock = $query->where('estoque_atual', '<=', $this->database->raw('estoque_minimo'))->count();
        $outOfStock = $query->where('estoque_atual', '<=', 0)->count();
        
        return [
            'total_products' => $total,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'normal_stock' => $total - $lowStock,
        ];
    }
    
    /**
     * Get product statistics
     */
    private function getProductStatistics(int $productId): array
    {
        $movements = $this->database->table('estoque_movimentacoes')
            ->where('produto_id', $productId);
        
        $totalEntradas = $movements->where('tipo', 'entrada')->sum('quantidade') ?? 0;
        $totalSaidas = $movements->where('tipo', 'saida')->sum('quantidade') ?? 0;
        $totalAjustes = $movements->where('tipo', 'ajuste')->count();
        
        $sales = $this->database->table('venda_itens')
            ->join('vendas', 'venda_itens.venda_id', '=', 'vendas.id')
            ->where('produto_id', $productId)
            ->where('vendas.status', 'concluida');
        
        $totalSold = $sales->sum('quantidade') ?? 0;
        $totalRevenue = $sales->sum('valor_total') ?? 0;
        
        return [
            'total_entradas' => $totalEntradas,
            'total_saidas' => $totalSaidas,
            'total_ajustes' => $totalAjustes,
            'total_vendido' => $totalSold,
            'receita_total' => $totalRevenue,
            'giro_estoque' => $this->calculateStockTurnover($productId),
        ];
    }
    
    /**
     * Calculate stock turnover
     */
    private function calculateStockTurnover(int $productId): float
    {
        // Simplified calculation: sold quantity / average stock
        $sold = $this->database->table('venda_itens')
            ->join('vendas', 'venda_itens.venda_id', '=', 'vendas.id')
            ->where('produto_id', $productId)
            ->where('vendas.status', 'concluida')
            ->where('vendas.created_at', '>=', Carbon::now()->subYear())
            ->sum('quantidade') ?? 0;
        
        $avgStock = $this->database->table('produtos')
            ->where('id', $productId)
            ->avg('estoque_atual') ?? 1;
        
        return $avgStock > 0 ? $sold / $avgStock : 0;
    }
    
    /**
     * Calculate alert urgency
     */
    private function calculateUrgency(object $product): string
    {
        if ($product->estoque_atual <= 0) {
            return 'critical';
        }
        
        if ($product->estoque_atual <= ($product->estoque_minimo * 0.5)) {
            return 'high';
        }
        
        if ($product->estoque_atual <= $product->estoque_minimo) {
            return 'medium';
        }
        
        return 'low';
    }
    
    /**
     * Create stock movement record
     */
    private function createStockMovement(array $data): void
    {
        $data['tenant_id'] = $this->tenantId;
        $data['created_at'] = Carbon::now();
        
        $this->database->table('estoque_movimentacoes')->insert($data);
    }
}
