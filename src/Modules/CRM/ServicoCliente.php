<?php

declare(strict_types=1);

namespace ERP\Modules\CRM;

use Core\Logger;
use Core\MultiTenant\TenantManager;
use ERP\Core\Cache\CacheInterface;
use ERP\Core\Database\DatabaseManager;
use ERP\Core\Excecoes\ExcecaoValidacao;

/**
 * Serviço de Gestão de Clientes
 *
 * Lógica de negócio para operações com clientes
 *
 * @package ERP\Modules\CRM
 */
final class ServicoCliente
{
    public function __construct(
        private DatabaseManager $database,
        private CacheInterface $cache,
        private TenantManager $tenantManager,
        private Logger $logger,
    ) {
    }
    
    /**
     * Criar novo cliente
     */
    public function criarCliente(array $dados, ?int $tenantId = null): array
    {
        $tenantId ??= $this->tenantManager->getCurrentTenantId();

        if (! $tenantId) {
            throw new ExcecaoValidacao('Tenant não definido');
        }
        
        // Validar dados obrigatórios
        $this->validarDadosCliente($dados);
        
        // Verificar se cliente já existe
        if ($this->clienteExiste($dados['email'], $tenantId)) {
            throw new ExcecaoValidacao('Cliente com este email já existe');
        }
        
        $this->logger->info('Creating client', ['tenant_id' => $tenantId, 'email' => $dados['email']]);
        
        $dadosCliente = [
            'tenant_id' => $tenantId,
            'nome' => $dados['nome'],
            'email' => $dados['email'],
            'telefone' => $dados['telefone'] ?? null,
            'documento' => $dados['documento'] ?? null,
            'tipo_pessoa' => $dados['tipo_pessoa'] ?? 'fisica',
            'endereco' => json_encode($dados['endereco'] ?? []),
            'status' => 'ativo',
            'data_cadastro' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        
        $clienteId = $this->database->table('clientes')->insertGetId($dadosCliente);
        
        // Limpar cache
        $this->limparCacheClientes($tenantId);
        
        return $this->obterClientePorId($clienteId, $tenantId);
    }
    
    /**
     * Atualizar cliente existente
     */
    public function atualizarCliente(int $clienteId, array $dados, string $tenantId): array
    {
        // Verificar se cliente existe
        $cliente = $this->obterClientePorId($clienteId, $tenantId);
        if (! $cliente) {
            throw new ExcecaoValidacao('Cliente não encontrado');
        }
        
        // Validar dados
        $this->validarDadosCliente($dados, $clienteId);
        
        $dadosAtualizacao = [
            'nome' => $dados['nome'] ?? $cliente['nome'],
            'email' => $dados['email'] ?? $cliente['email'],
            'telefone' => $dados['telefone'] ?? $cliente['telefone'],
            'documento' => $dados['documento'] ?? $cliente['documento'],
            'endereco' => isset($dados['endereco']) ? json_encode($dados['endereco']) : $cliente['endereco'],
            'status' => $dados['status'] ?? $cliente['status'],
            'updated_at' => now(),
        ];
        
        $this->database->table('clientes')
            ->where('id', $clienteId)
            ->where('tenant_id', $tenantId)
            ->update($dadosAtualizacao);
        
        // Limpar cache
        $this->limparCacheClientes($tenantId);
        
        return $this->obterClientePorId($clienteId, $tenantId);
    }
    
    /**
     * Obter estatísticas de clientes
     */
    public function obterEstatisticasClientes(string $tenantId): array
    {
        $chaveCache = "estatisticas_clientes_{$tenantId}";
        
        return $this->cache->remember($chaveCache, function () use ($tenantId) {
            $totalClientes = $this->database->table('clientes')
                ->where('tenant_id', $tenantId)
                ->count();
            
            $clientesAtivos = $this->database->table('clientes')
                ->where('tenant_id', $tenantId)
                ->where('status', 'ativo')
                ->count();
            
            $clientesEstesMes = $this->database->table('clientes')
                ->where('tenant_id', $tenantId)
                ->whereMonth('created_at', date('m'))
                ->whereYear('created_at', date('Y'))
                ->count();
            
            $mediaComprasPorCliente = $this->database->table('vendas')
                ->join('clientes', 'vendas.cliente_id', '=', 'clientes.id')
                ->where('clientes.tenant_id', $tenantId)
                ->avg('vendas.valor_total') ?? 0;
            
            return [
                'total_clientes' => $totalClientes,
                'clientes_ativos' => $clientesAtivos,
                'novos_este_mes' => $clientesEstesMes,
                'media_compras' => round($mediaComprasPorCliente, 2),
                'taxa_conversao' => $totalClientes > 0 ? round(($clientesAtivos / $totalClientes) * 100, 2) : 0,
            ];
        }, 600); // Cache por 10 minutos
    }
    
    /**
     * Obter histórico de compras do cliente
     */
    public function obterHistoricoCompras(int $clienteId, string $tenantId): array
    {
        return $this->database->table('vendas')
            ->select([
                'id',
                'numero_venda',
                'valor_total',
                'status',
                'data_venda',
                'created_at',
            ])
            ->where('cliente_id', $clienteId)
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->toArray();
    }
    
    /**
     * Buscar clientes com filtros avançados
     */
    public function buscarClientes(array $filtros, string $tenantId): array
    {
        $query = $this->database->table('clientes')
            ->where('tenant_id', $tenantId);
        
        // Aplicar filtros
        if (! empty($filtros['termo_busca'])) {
            $termo = $filtros['termo_busca'];
            $query->where(function ($q) use ($termo) {
                $q->where('nome', 'LIKE', "%{$termo}%")
                  ->orWhere('email', 'LIKE', "%{$termo}%")
                  ->orWhere('documento', 'LIKE', "%{$termo}%");
            });
        }

        if (! empty($filtros['status'])) {
            $query->where('status', $filtros['status']);
        }

        if (! empty($filtros['tipo_pessoa'])) {
            $query->where('tipo_pessoa', $filtros['tipo_pessoa']);
        }

        if (! empty($filtros['data_inicio'])) {
            $query->where('created_at', '>=', $filtros['data_inicio']);
        }

        if (! empty($filtros['data_fim'])) {
            $query->where('created_at', '<=', $filtros['data_fim']);
        }
        
        return $query->orderBy('nome')->get()->toArray();
    }
    
    /**
     * Validar dados do cliente
     */
    private function validarDadosCliente(array $dados, ?int $clienteIdExcluir = null): void
    {
        $regrasObrigatorias = ['nome', 'email'];
        
        foreach ($regrasObrigatorias as $campo) {
            if (empty($dados[$campo])) {
                throw new ExcecaoValidacao("Campo {$campo} é obrigatório");
            }
        }
        
        // Validar email
        if (! filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ExcecaoValidacao('Email inválido');
        }
        
        // Validar documento se fornecido
        if (! empty($dados['documento'])) {
            $documento = preg_replace('/[^0-9]/', '', $dados['documento']);
            if (strlen($documento) !== 11 && strlen($documento) !== 14) {
                throw new ExcecaoValidacao('Documento deve ser CPF ou CNPJ válido');
            }
        }
    }
    
    /**
     * Verificar se cliente já existe
     */
    private function clienteExiste(string $email, string $tenantId, ?int $clienteIdExcluir = null): bool
    {
        $query = $this->database->table('clientes')
            ->where('email', $email)
            ->where('tenant_id', $tenantId);
        
        if ($clienteIdExcluir) {
            $query->where('id', '!=', $clienteIdExcluir);
        }
        
        return $query->exists();
    }
    
    /**
     * Obter cliente por ID
     */
    private function obterClientePorId(int $clienteId, string $tenantId): ?array
    {
        return $this->database->table('clientes')
            ->where('id', $clienteId)
            ->where('tenant_id', $tenantId)
            ->first()
            ?->toArray();
    }
    
    /**
     * Limpar cache de clientes
     */
    private function limparCacheClientes(string $tenantId): void
    {
        $this->cache->forget("estatisticas_clientes_{$tenantId}");
    }
}
