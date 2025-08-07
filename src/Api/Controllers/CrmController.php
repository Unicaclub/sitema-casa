<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use Carbon\Carbon;

/**
 * Controlador da API do CRM
 * 
 * Gerencia operações de relacionamento com clientes
 * 
 * @package ERP\Api\Controllers
 */
final class CrmController extends ControladorBase
{
    /**
     * Listar clientes com paginação e filtros
     * GET /api/crm/list
     */
    public function list(Request $request): Response
    {
        $this->autorizar('crm.visualizar');
        
        $query = $this->database->table('clientes')
            ->select([
                'id',
                'nome',
                'email',
                'telefone',
                'tipo',
                'documento',
                'status',
                'cidade',
                'estado',
                'created_at',
                'updated_at'
            ]);
        
        $this->applyTenantFilter($query);
        
        // Apply filters
        $this->applyFilters($query, $request);
        
        // Apply search
        $this->applySearch($query, $request, [
            'nome', 'email', 'telefone', 'documento', 'cidade'
        ]);
        
        // Apply sorting
        $sortBy = $request->query('sort_by', 'created_at');
        $sortOrder = $request->query('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);
        
        $result = $this->getPaginatedResults($query, $request);
        
        // Add customer statistics
        $result['stats'] = $this->getCustomerStats();
        
        return $this->paginated($result['data'], $result['pagination']);
    }
    
    /**
     * Get customer by ID
     * GET /api/crm/{id}
     */
    public function show(Request $request): Response
    {
        $this->authorize('crm.view');
        
        $id = $request->getAttribute('id');
        
        $customer = $this->database->table('clientes')
            ->where('id', $id);
        
        $this->applyTenantFilter($customer);
        
        $customerData = $customer->first();
        
        if (! $customerData) {
            return $this->error('Cliente não encontrado', 404);
        }
        
        // Get customer orders
        $orders = $this->database->table('vendas')
            ->where('cliente_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
        
        // Get customer statistics
        $stats = $this->getCustomerStatistics($id);
        
        return $this->success([
            'customer' => $customerData,
            'recent_orders' => $orders->toArray(),
            'statistics' => $stats,
        ]);
    }
    
    /**
     * Create new customer
     * POST /api/crm/create
     */
    public function create(Request $request): Response
    {
        $this->authorize('crm.create');
        
        $rules = [
            'nome' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'telefone' => 'string|max:20',
            'tipo' => 'required|in:pessoa_fisica,pessoa_juridica',
            'documento' => 'required|string|max:18',
            'endereco' => 'string|max:500',
            'cidade' => 'string|max:100',
            'estado' => 'string|max:2',
            'cep' => 'string|max:9',
            'observacoes' => 'string|max:1000',
        ];
        
        $data = $this->validate($request, $rules);
        
        // Check if email already exists
        $existingCustomer = $this->database->table('clientes')
            ->where('email', $data['email']);
        
        $this->applyTenantFilter($existingCustomer);
        
        if ($existingCustomer->exists()) {
            return $this->validationError(['email' => ['Email já está em uso']]);
        }
        
        // Check if document already exists
        $existingDocument = $this->database->table('clientes')
            ->where('documento', $data['documento']);
        
        $this->applyTenantFilter($existingDocument);
        
        if ($existingDocument->exists()) {
            return $this->validationError(['documento' => ['Documento já está em uso']]);
        }
        
        $data['tenant_id'] = $this->tenantId;
        $data['status'] = 'ativo';
        $data['created_at'] = Carbon::now();
        $data['updated_at'] = Carbon::now();
        
        $customerId = $this->database->table('clientes')->insertGetId($data);
        
        $customer = $this->database->table('clientes')
            ->where('id', $customerId)
            ->first();
        
        $this->clearCache('customer_*');
        
        return $this->success($customer, 'Cliente criado com sucesso', 201);
    }
    
    /**
     * Update customer
     * PUT /api/crm/update/{id}
     */
    public function update(Request $request): Response
    {
        $this->authorize('crm.update');
        
        $id = $request->getAttribute('id');
        
        $rules = [
            'nome' => 'string|max:255',
            'email' => 'email|max:255',
            'telefone' => 'string|max:20',
            'tipo' => 'in:pessoa_fisica,pessoa_juridica',
            'documento' => 'string|max:18',
            'endereco' => 'string|max:500',
            'cidade' => 'string|max:100',
            'estado' => 'string|max:2',
            'cep' => 'string|max:9',
            'status' => 'in:ativo,inativo,bloqueado',
            'observacoes' => 'string|max:1000',
        ];
        
        $data = $this->validate($request, $rules);
        
        // Check if customer exists
        $customer = $this->database->table('clientes')
            ->where('id', $id);
        
        $this->applyTenantFilter($customer);
        
        if (! $customer->exists()) {
            return $this->error('Cliente não encontrado', 404);
        }
        
        // Check email uniqueness if provided
        if (isset($data['email'])) {
            $existingCustomer = $this->database->table('clientes')
                ->where('email', $data['email'])
                ->where('id', '!=', $id);
            
            $this->applyTenantFilter($existingCustomer);
            
            if ($existingCustomer->exists()) {
                return $this->validationError(['email' => ['Email já está em uso']]);
            }
        }
        
        // Check document uniqueness if provided
        if (isset($data['documento'])) {
            $existingDocument = $this->database->table('clientes')
                ->where('documento', $data['documento'])
                ->where('id', '!=', $id);
            
            $this->applyTenantFilter($existingDocument);
            
            if ($existingDocument->exists()) {
                return $this->validationError(['documento' => ['Documento já está em uso']]);
            }
        }
        
        $data['updated_at'] = Carbon::now();
        
        $this->database->table('clientes')
            ->where('id', $id)
            ->update($data);
        
        $updatedCustomer = $this->database->table('clientes')
            ->where('id', $id)
            ->first();
        
        $this->clearCache('customer_*');
        
        return $this->success($updatedCustomer, 'Cliente atualizado com sucesso');
    }
    
    /**
     * Delete customer
     * DELETE /api/crm/delete/{id}
     */
    public function delete(Request $request): Response
    {
        $this->authorize('crm.delete');
        
        $id = $request->getAttribute('id');
        
        $customer = $this->database->table('clientes')
            ->where('id', $id);
        
        $this->applyTenantFilter($customer);
        
        if (! $customer->exists()) {
            return $this->error('Cliente não encontrado', 404);
        }
        
        // Check if customer has orders
        $hasOrders = $this->database->table('vendas')
            ->where('cliente_id', $id)
            ->exists();
        
        if ($hasOrders) {
            // Soft delete instead of hard delete
            $this->database->table('clientes')
                ->where('id', $id)
                ->update([
                    'status' => 'inativo',
                    'deleted_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            
            $message = 'Cliente desativado com sucesso (possui histórico de vendas)';
        } else {
            // Hard delete if no orders
            $this->database->table('clientes')
                ->where('id', $id)
                ->delete();
            
            $message = 'Cliente removido com sucesso';
        }
        
        $this->clearCache('customer_*');
        
        return $this->success(null, $message);
    }
    
    /**
     * Filter customers with advanced filters
     * GET /api/crm/filter
     */
    public function filter(Request $request): Response
    {
        $this->authorize('crm.view');
        
        $query = $this->database->table('clientes');
        $this->applyTenantFilter($query);
        
        // Apply advanced filters
        $this->applyAdvancedFilters($query, $request);
        
        // Apply search
        $this->applySearch($query, $request, [
            'nome', 'email', 'telefone', 'documento', 'cidade'
        ]);
        
        // Apply date range
        $this->applyDateRange($query, $request);
        
        $result = $this->getPaginatedResults($query, $request);
        
        return $this->paginated($result['data'], $result['pagination']);
    }
    
    /**
     * Get customer statistics
     * GET /api/crm/stats
     */
    public function stats(Request $request): Response
    {
        $this->authorize('crm.view');
        
        return $this->cached('crm_stats', function () {
            $query = $this->database->table('clientes');
            $this->applyTenantFilter($query);
            
            $total = $query->count();
            $active = $query->where('status', 'ativo')->count();
            $inactive = $query->where('status', 'inativo')->count();
            $blocked = $query->where('status', 'bloqueado')->count();
            
            $typeStats = $this->database->table('clientes')
                ->selectRaw('tipo, COUNT(*) as count')
                ->groupBy('tipo')
                ->pluck('count', 'tipo');
            
            $this->applyTenantFilter($query);
            
            $monthlyGrowth = $this->getMonthlyGrowth();
            
            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'blocked' => $blocked,
                'by_type' => [
                    'pessoa_fisica' => $typeStats['pessoa_fisica'] ?? 0,
                    'pessoa_juridica' => $typeStats['pessoa_juridica'] ?? 0,
                ],
                'monthly_growth' => $monthlyGrowth,
            ];
        }, 1800); // Cache for 30 minutes
    }
    
    /**
     * Export customers
     * GET /api/crm/export
     */
    public function export(Request $request): Response
    {
        $this->authorize('crm.export');
        
        $format = $request->query('format', 'csv');
        
        $query = $this->database->table('clientes')
            ->select([
                'nome',
                'email',
                'telefone',
                'tipo',
                'documento',
                'endereco',
                'cidade',
                'estado',
                'cep',
                'status',
                'created_at'
            ]);
        
        $this->applyTenantFilter($query);
        $this->applyFilters($query, $request);
        
        $customers = $query->get()->toArray();
        
        if ($format === 'csv') {
            return $this->exportToCsv($customers, 'clientes');
        }
        
        return $this->success($customers);
    }
    
    /**
     * Apply filters to query
     */
    private function applyFilters($query, Request $request): void
    {
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        
        if ($type = $request->query('tipo')) {
            $query->where('tipo', $type);
        }
        
        if ($city = $request->query('cidade')) {
            $query->where('cidade', 'LIKE', "%{$city}%");
        }
        
        if ($state = $request->query('estado')) {
            $query->where('estado', $state);
        }
    }
    
    /**
     * Apply advanced filters to query
     */
    private function applyAdvancedFilters($query, Request $request): void
    {
        $this->applyFilters($query, $request);
        
        // Age range for pessoa_fisica
        if ($minAge = $request->query('min_age')) {
            $query->where('data_nascimento', '<=', Carbon::now()->subYears($minAge));
        }
        
        if ($maxAge = $request->query('max_age')) {
            $query->where('data_nascimento', '>=', Carbon::now()->subYears($maxAge));
        }
        
        // Customer value filters
        if ($minValue = $request->query('min_purchase_value')) {
            $customerIds = $this->database->table('vendas')
                ->selectRaw('cliente_id')
                ->groupBy('cliente_id')
                ->havingRaw('SUM(valor_total) >= ?', [$minValue])
                ->pluck('cliente_id');
            
            $query->whereIn('id', $customerIds);
        }
        
        // Last purchase date
        if ($lastPurchaseDays = $request->query('last_purchase_days')) {
            $date = Carbon::now()->subDays($lastPurchaseDays);
            $customerIds = $this->database->table('vendas')
                ->where('created_at', '>=', $date)
                ->distinct()
                ->pluck('cliente_id');
            
            $query->whereIn('id', $customerIds);
        }
    }
    
    /**
     * Get customer statistics
     */
    private function getCustomerStats(): array
    {
        $query = $this->database->table('clientes');
        $this->applyTenantFilter($query);
        
        return [
            'total' => $query->count(),
            'active' => $query->where('status', 'ativo')->count(),
            'new_this_month' => $query->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
        ];
    }
    
    /**
     * Get individual customer statistics
     */
    private function getCustomerStatistics(int $customerId): array
    {
        $orders = $this->database->table('vendas')
            ->where('cliente_id', $customerId);
        
        $totalOrders = $orders->count();
        $totalSpent = $orders->where('status', 'concluida')->sum('valor_total') ?? 0;
        $lastOrder = $orders->orderBy('created_at', 'desc')->first();
        $averageOrder = $totalOrders > 0 ? $totalSpent / $totalOrders : 0;
        
        return [
            'total_orders' => $totalOrders,
            'total_spent' => $totalSpent,
            'average_order' => $averageOrder,
            'last_order_date' => $lastOrder?->created_at,
            'customer_since' => $this->database->table('clientes')
                ->where('id', $customerId)
                ->value('created_at'),
        ];
    }
    
    /**
     * Get monthly growth data
     */
    private function getMonthlyGrowth(): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $startOfMonth = $date->copy()->startOfMonth();
            $endOfMonth = $date->copy()->endOfMonth();
            
            $count = $this->database->table('clientes')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
            
            $this->applyTenantFilter($count);
            
            $months[] = [
                'month' => $date->format('M/Y'),
                'count' => $count->count(),
            ];
        }
        
        return $months;
    }
    
    /**
     * Export data to CSV
     */
    private function exportToCsv(array $data, string $filename): Response
    {
        $csv = "Nome,Email,Telefone,Tipo,Documento,Endereço,Cidade,Estado,CEP,Status,Data Criação\n";
        
        foreach ($data as $row) {
            $csv .= implode(',', [
                '"' . str_replace('"', '""', $row['nome'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['email'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['telefone'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['tipo'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['documento'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['endereco'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['cidade'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['estado'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['cep'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['status'] ?? '') . '"',
                '"' . str_replace('"', '""', $row['created_at'] ?? '') . '"',
            ]) . "\n";
        }
        
        return Response::download(
            tempnam(sys_get_temp_dir(), 'export'),
            "{$filename}_" . date('Y-m-d_H-i-s') . '.csv',
            ['Content-Type' => 'text/csv']
        )->setContent($csv);
    }
}
