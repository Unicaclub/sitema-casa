<?php

namespace Core\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\MultiTenant\TenantManager;
use Core\Auth\Auth;
use Core\Logger;

/**
 * TenantMiddleware - Middleware para validação de multi-tenancy
 * 
 * Responsável por:
 * - Definir contexto do tenant baseado no usuário autenticado
 * - Validar acesso a recursos do tenant
 * - Prevenir vazamento de dados entre tenants
 * - Registrar tentativas de acesso cruzado
 */
class TenantMiddleware
{
    private TenantManager $tenantManager;
    private Auth $auth;
    private Logger $logger;
    
    public function __construct(TenantManager $tenantManager, Auth $auth, Logger $logger)
    {
        $this->tenantManager = $tenantManager;
        $this->auth = $auth;
        $this->logger = $logger;
    }
    
    public function handle(Request $request, callable $next): Response
    {
        try {
            // Verifica se usuário está autenticado
            if (! $this->auth->check()) {
                return new Response(['error' => 'Unauthorized'], 401);
            }
            
            $user = $this->auth->user();
            $userTenantId = $user->company_id ?? $user->tenant_id ?? null;
            
            if (! $userTenantId) {
                $this->logger->error('User without tenant', ['user_id' => $user->id]);
                return new Response(['error' => 'User not associated with tenant'], 403);
            }
            
            // Define contexto do tenant
            $this->tenantManager->setCurrentTenant($userTenantId);
            
            // Valida tentativas de acesso a outros tenants via parâmetros
            $this->validateRequestParameters($request, $userTenantId);
            
            // Continua com a requisição
            $response = $next($request);
            
            // Valida resposta para vazamento de dados
            $this->validateResponse($response, $userTenantId);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->logger->error('Tenant middleware error', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
                'tenant_id' => $userTenantId ?? null
            ]);
            
            return new Response(['error' => 'Tenant validation failed'], 403);
        }
    }
    
    /**
     * Valida parâmetros da requisição para tentativas de acesso cruzado
     */
    private function validateRequestParameters(Request $request, int $userTenantId): void
    {
        // Lista de parâmetros que podem conter IDs de outros tenants
        $sensitiveParams = ['tenant_id', 'company_id'];
        
        foreach ($sensitiveParams as $param) {
            $value = $request->input($param);
            
            if ($value && is_numeric($value) && (int)$value !== $userTenantId) {
                $this->tenantManager->logCrossTenantAccess(
                    $this->auth->id(),
                    (int)$value,
                    $request->getUri()
                );
                
                throw new \InvalidArgumentException("Access denied to tenant {$value}");
            }
        }
        
        // Valida IDs de recursos em parâmetros da URL
        $this->validateResourceIds($request, $userTenantId);
    }
    
    /**
     * Valida IDs de recursos para garantir que pertencem ao tenant
     */
    private function validateResourceIds(Request $request, int $userTenantId): void
    {
        $path = $request->getPath();
        $method = $request->getMethod();
        
        // Mapeia rotas para suas respectivas tabelas
        $resourceMappings = [
            '/api/clientes' => 'clientes',
            '/api/produtos' => 'produtos', 
            '/api/vendas' => 'vendas',
            '/api/financeiro' => 'transacoes_financeiras',
            '/api/estoque' => 'produtos',
        ];
        
        foreach ($resourceMappings as $route => $table) {
            if (strpos($path, $route) === 0) {
                // Extrai ID do recurso da URL (ex: /api/clientes/123)
                if (preg_match("#^{$route}/(\d+)#", $path, $matches)) {
                    $resourceId = (int)$matches[1];
                    
                    if (! $this->tenantManager->validateOwnership($table, $resourceId, $userTenantId)) {
                        $this->tenantManager->logCrossTenantAccess(
                            $this->auth->id(),
                            0, // ID do tenant desconhecido
                            "{$table}:{$resourceId}"
                        );
                        
                        throw new \InvalidArgumentException("Resource {$resourceId} not found or access denied");
                    }
                }
                break;
            }
        }
    }
    
    /**
     * Valida resposta para vazamento de dados
     */
    private function validateResponse(Response $response, int $userTenantId): void
    {
        // Só valida respostas JSON de sucesso
        if ($response->getStatusCode() !== 200) {
            return;
        }
        
        $contentType = $response->getHeader('Content-Type');
        if (! str_contains($contentType, 'application/json')) {
            return;
        }
        
        $data = $response->getData();
        if (! is_array($data)) {
            return;
        }
        
        // Verifica se há dados com tenant_id ou company_id diferente
        $this->validateDataTenancy($data, $userTenantId, '');
    }
    
    /**
     * Valida recursivamente dados para tenant correto
     */
    private function validateDataTenancy($data, int $userTenantId, string $path): void
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $currentPath = $path ? "{$path}.{$key}" : $key;
                
                if (($key === 'tenant_id' || $key === 'company_id') && is_numeric($value)) {
                    if ((int)$value !== $userTenantId) {
                        $this->logger->critical('Data leakage detected', [
                            'user_tenant_id' => $userTenantId,
                            'leaked_tenant_id' => (int)$value,
                            'data_path' => $currentPath,
                            'user_id' => $this->auth->id()
                        ]);
                        
                        throw new \RuntimeException("Data leakage detected at {$currentPath}");
                    }
                }
                
                if (is_array($value) || is_object($value)) {
                    $this->validateDataTenancy($value, $userTenantId, $currentPath);
                }
            }
        } elseif (is_object($data)) {
            $this->validateDataTenancy((array)$data, $userTenantId, $path);
        }
    }
    
    /**
     * Middleware especial para rotas administrativas multi-tenant
     */
    public function handleAdmin(Request $request, callable $next): Response
    {
        if (! $this->auth->check()) {
            return new Response(['error' => 'Unauthorized'], 401);
        }
        
        $user = $this->auth->user();
        
        // Verifica se usuário tem permissão administrativa
        if (! $this->hasAdminPermission($user)) {
            return new Response(['error' => 'Insufficient permissions'], 403);
        }
        
        // Para administradores, permite acesso a múltiplos tenants
        // mas ainda registra atividade
        $requestedTenantId = $request->input('tenant_id');
        if ($requestedTenantId) {
            $this->tenantManager->setCurrentTenant((int)$requestedTenantId);
            
            $this->logger->info('Admin cross-tenant access', [
                'admin_user_id' => $user->id,
                'admin_tenant_id' => $user->company_id ?? $user->tenant_id,
                'accessed_tenant_id' => $requestedTenantId,
                'resource' => $request->getUri()
            ]);
        }
        
        return $next($request);
    }
    
    /**
     * Verifica se usuário tem permissão administrativa
     */
    private function hasAdminPermission($user): bool
    {
        // Implementa lógica para verificar permissões administrativas
        // Por exemplo, verificar roles ou permissões específicas
        return isset($user->is_admin) && $user->is_admin;
    }
}
