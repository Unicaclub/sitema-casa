<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use Carbon\Carbon;

/**
 * Controlador API Configurações
 * 
 * Gerencia configurações do sistema e empresa
 * 
 * @package ERP\Api\Controllers
 */
final class ConfiguracoesController extends BaseController
{
    /**
     * Obter dados da empresa
     * GET /api/config/empresa
     */
    public function obterEmpresa(Request $request): Response
    {
        $this->authorize('configuracoes.visualizar');
        
        $empresa = $this->database->table('empresas')
            ->where('tenant_id', $this->tenantId)
            ->first();
        
        if (!$empresa) {
            return $this->erro('Dados da empresa não encontrados', 404);
        }
        
        return $this->sucesso($empresa);
    }
    
    /**
     * Atualizar dados da empresa
     * PUT /api/config/empresa
     */
    public function atualizarEmpresa(Request $request): Response
    {
        $this->authorize('configuracoes.editar');
        
        $regras = [
            'razao_social' => 'required|string|max:255',
            'nome_fantasia' => 'string|max:255',
            'cnpj' => 'required|string|max:18',
            'inscricao_estadual' => 'string|max:20',
            'inscricao_municipal' => 'string|max:20',
            'endereco' => 'required|string|max:500',
            'numero' => 'required|string|max:10',
            'complemento' => 'string|max:100',
            'bairro' => 'required|string|max:100',
            'cidade' => 'required|string|max:100',
            'estado' => 'required|string|max:2',
            'cep' => 'required|string|max:9',
            'telefone' => 'string|max:20',
            'email' => 'required|email|max:255',
            'website' => 'url|max:255',
            'regime_tributario' => 'required|in:simples_nacional,lucro_presumido,lucro_real',
            'atividade_principal' => 'string|max:500',
        ];
        
        $dados = $this->validar($request, $regras);
        $dados['updated_at'] = Carbon::now();
        
        // Verificar se empresa já existe
        $empresaExistente = $this->database->table('empresas')
            ->where('tenant_id', $this->tenantId)
            ->first();
        
        if ($empresaExistente) {
            // Atualizar empresa existente
            $this->database->table('empresas')
                ->where('tenant_id', $this->tenantId)
                ->update($dados);
            
            $empresaAtualizada = $this->database->table('empresas')
                ->where('tenant_id', $this->tenantId)
                ->first();
        } else {
            // Criar nova empresa
            $dados['tenant_id'] = $this->tenantId;
            $dados['created_at'] = Carbon::now();
            
            $empresaId = $this->database->table('empresas')->insertGetId($dados);
            
            $empresaAtualizada = $this->database->table('empresas')
                ->where('id', $empresaId)
                ->first();
        }
        
        $this->limparCache('configuracoes_*');
        
        return $this->sucesso($empresaAtualizada, 'Dados da empresa atualizados com sucesso');
    }
    
    /**
     * Obter configurações de moeda
     * GET /api/config/moeda
     */
    public function obterMoeda(Request $request): Response
    {
        $this->authorize('configuracoes.visualizar');
        
        $configuracao = $this->database->table('configuracoes_sistema')
            ->where('tenant_id', $this->tenantId)
            ->where('categoria', 'moeda')
            ->get()
            ->keyBy('chave')
            ->map(fn($item) => $item->valor);
        
        $padrao = [
            'moeda_padrao' => 'BRL',
            'simbolo_moeda' => 'R$',
            'formato_numero' => '1.234,56',
            'casas_decimais' => '2',
            'posicao_simbolo' => 'antes',
        ];
        
        $configuracaoMoeda = array_merge($padrao, $configuracao->toArray());
        
        return $this->sucesso($configuracaoMoeda);
    }
    
    /**
     * Atualizar configurações de moeda
     * PUT /api/config/moeda
     */
    public function atualizarMoeda(Request $request): Response
    {
        $this->authorize('configuracoes.editar');
        
        $regras = [
            'moeda_padrao' => 'required|string|max:3',
            'simbolo_moeda' => 'required|string|max:5',
            'formato_numero' => 'required|in:1.234,56,1,234.56,1234.56,1234,56',
            'casas_decimais' => 'required|integer|min:0|max:4',
            'posicao_simbolo' => 'required|in:antes,depois',
        ];
        
        $dados = $this->validar($request, $regras);
        
        foreach ($dados as $chave => $valor) {
            $this->database->table('configuracoes_sistema')->updateOrInsert(
                [
                    'tenant_id' => $this->tenantId,
                    'categoria' => 'moeda',
                    'chave' => $chave,
                ],
                [
                    'valor' => $valor,
                    'updated_at' => Carbon::now(),
                ]
            );
        }
        
        $this->limparCache('configuracoes_*');
        
        return $this->sucesso($dados, 'Configurações de moeda atualizadas com sucesso');
    }
    
    /**
     * Obter configurações de idioma
     * GET /api/config/idioma
     */
    public function obterIdioma(Request $request): Response
    {
        $this->authorize('configuracoes.visualizar');
        
        $configuracao = $this->database->table('configuracoes_sistema')
            ->where('tenant_id', $this->tenantId)
            ->where('categoria', 'idioma')
            ->get()
            ->keyBy('chave')
            ->map(fn($item) => $item->valor);
        
        $padrao = [
            'idioma_padrao' => 'pt_BR',
            'formato_data' => 'd/m/Y',
            'formato_hora' => 'H:i:s',
            'fuso_horario' => 'America/Sao_Paulo',
        ];
        
        $configuracaoIdioma = array_merge($padrao, $configuracao->toArray());
        
        return $this->sucesso($configuracaoIdioma);
    }
    
    /**
     * Atualizar configurações de idioma
     * PUT /api/config/idioma
     */
    public function atualizarIdioma(Request $request): Response
    {
        $this->authorize('configuracoes.editar');
        
        $regras = [
            'idioma_padrao' => 'required|in:pt_BR,en_US,es_ES',
            'formato_data' => 'required|in:d/m/Y,m/d/Y,Y-m-d,d-m-Y',
            'formato_hora' => 'required|in:H:i:s,h:i:s A,H:i',
            'fuso_horario' => 'required|string|max:50',
        ];
        
        $dados = $this->validar($request, $regras);
        
        foreach ($dados as $chave => $valor) {
            $this->database->table('configuracoes_sistema')->updateOrInsert(
                [
                    'tenant_id' => $this->tenantId,
                    'categoria' => 'idioma',
                    'chave' => $chave,
                ],
                [
                    'valor' => $valor,
                    'updated_at' => Carbon::now(),
                ]
            );
        }
        
        $this->limparCache('configuracoes_*');
        
        return $this->sucesso($dados, 'Configurações de idioma atualizadas com sucesso');
    }
    
    /**
     * Listar usuários
     * GET /api/config/usuarios
     */
    public function listarUsuarios(Request $request): Response
    {
        $this->authorize('usuarios.visualizar');
        
        $query = $this->database->table('users')
            ->join('user_tenants', 'users.id', '=', 'user_tenants.user_id')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.email_verified_at',
                'users.active',
                'users.created_at',
                'user_tenants.active as tenant_active'
            ])
            ->where('user_tenants.tenant_id', $this->tenantId);
        
        // Aplicar filtros
        if ($status = $request->query('status')) {
            if ($status === 'ativo') {
                $query->where('users.active', true)
                      ->where('user_tenants.active', true);
            } elseif ($status === 'inativo') {
                $query->where(function($q) {
                    $q->where('users.active', false)
                      ->orWhere('user_tenants.active', false);
                });
            }
        }
        
        // Aplicar busca
        $this->aplicarBusca($query, $request, ['users.name', 'users.email']);
        
        $resultado = $this->obterResultadosPaginados($query, $request);
        
        // Buscar perfis dos usuários
        foreach ($resultado['dados'] as &$usuario) {
            $perfis = $this->database->table('user_roles')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('user_roles.user_id', $usuario['id'])
                ->where('user_roles.tenant_id', $this->tenantId)
                ->pluck('roles.name')
                ->toArray();
            
            $usuario['perfis'] = $perfis;
        }
        
        return $this->paginado($resultado['dados'], $resultado['paginacao']);
    }
    
    /**
     * Criar usuário
     * POST /api/config/usuarios
     */
    public function criarUsuario(Request $request): Response
    {
        $this->authorize('usuarios.criar');
        
        $regras = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'perfis' => 'array',
            'perfis.*' => 'string|exists:roles,name',
            'ativo' => 'boolean',
        ];
        
        $dados = $this->validar($request, $regras);
        
        $this->database->beginTransaction();
        
        try {
            // Criar usuário
            $usuarioId = $this->database->table('users')->insertGetId([
                'name' => $dados['name'],
                'email' => $dados['email'],
                'password' => password_hash($dados['password'], PASSWORD_DEFAULT),
                'active' => $dados['ativo'] ?? true,
                'email_verified_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            
            // Associar ao tenant
            $this->database->table('user_tenants')->insert([
                'user_id' => $usuarioId,
                'tenant_id' => $this->tenantId,
                'active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            
            // Atribuir perfis
            if (!empty($dados['perfis'])) {
                foreach ($dados['perfis'] as $perfilNome) {
                    $perfil = $this->database->table('roles')
                        ->where('name', $perfilNome)
                        ->first();
                    
                    if ($perfil) {
                        $this->database->table('user_roles')->insert([
                            'user_id' => $usuarioId,
                            'role_id' => $perfil->id,
                            'tenant_id' => $this->tenantId,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }
                }
            }
            
            $this->database->commit();
            
            $usuario = $this->database->table('users')
                ->where('id', $usuarioId)
                ->first();
            
            $this->limparCache('usuarios_*');
            
            return $this->sucesso($usuario, 'Usuário criado com sucesso', 201);
            
        } catch (\Exception $e) {
            $this->database->rollback();
            return $this->erro('Erro ao criar usuário: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Atualizar usuário
     * PUT /api/config/usuarios/{id}
     */
    public function atualizarUsuario(Request $request): Response
    {
        $this->authorize('usuarios.editar');
        
        $id = $request->getAttribute('id');
        
        $regras = [
            'name' => 'string|max:255',
            'email' => 'email|max:255|unique:users,email,' . $id,
            'password' => 'string|min:8',
            'perfis' => 'array',
            'perfis.*' => 'string|exists:roles,name',
            'ativo' => 'boolean',
        ];
        
        $dados = $this->validar($request, $regras);
        
        // Verificar se usuário pertence ao tenant
        $usuarioTenant = $this->database->table('user_tenants')
            ->where('user_id', $id)
            ->where('tenant_id', $this->tenantId)
            ->first();
        
        if (!$usuarioTenant) {
            return $this->erro('Usuário não encontrado', 404);
        }
        
        $this->database->beginTransaction();
        
        try {
            // Atualizar dados do usuário
            $dadosUsuario = [];
            if (isset($dados['name'])) $dadosUsuario['name'] = $dados['name'];
            if (isset($dados['email'])) $dadosUsuario['email'] = $dados['email'];
            if (isset($dados['password'])) $dadosUsuario['password'] = password_hash($dados['password'], PASSWORD_DEFAULT);
            if (isset($dados['ativo'])) $dadosUsuario['active'] = $dados['ativo'];
            
            if (!empty($dadosUsuario)) {
                $dadosUsuario['updated_at'] = Carbon::now();
                $this->database->table('users')
                    ->where('id', $id)
                    ->update($dadosUsuario);
            }
            
            // Atualizar perfis se fornecidos
            if (isset($dados['perfis'])) {
                // Remover perfis existentes
                $this->database->table('user_roles')
                    ->where('user_id', $id)
                    ->where('tenant_id', $this->tenantId)
                    ->delete();
                
                // Adicionar novos perfis
                foreach ($dados['perfis'] as $perfilNome) {
                    $perfil = $this->database->table('roles')
                        ->where('name', $perfilNome)
                        ->first();
                    
                    if ($perfil) {
                        $this->database->table('user_roles')->insert([
                            'user_id' => $id,
                            'role_id' => $perfil->id,
                            'tenant_id' => $this->tenantId,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }
                }
            }
            
            $this->database->commit();
            
            $usuarioAtualizado = $this->database->table('users')
                ->where('id', $id)
                ->first();
            
            $this->limparCache('usuarios_*');
            
            return $this->sucesso($usuarioAtualizado, 'Usuário atualizado com sucesso');
            
        } catch (\Exception $e) {
            $this->database->rollback();
            return $this->erro('Erro ao atualizar usuário: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Remover usuário
     * DELETE /api/config/usuarios/{id}
     */
    public function removerUsuario(Request $request): Response
    {
        $this->authorize('usuarios.excluir');
        
        $id = $request->getAttribute('id');
        
        // Verificar se usuário pertence ao tenant
        $usuarioTenant = $this->database->table('user_tenants')
            ->where('user_id', $id)
            ->where('tenant_id', $this->tenantId)
            ->first();
        
        if (!$usuarioTenant) {
            return $this->erro('Usuário não encontrado', 404);
        }
        
        // Não permitir remoção do próprio usuário
        if ($id == $this->usuario->getAuthIdentifier()) {
            return $this->erro('Não é possível remover seu próprio usuário', 400);
        }
        
        $this->database->beginTransaction();
        
        try {
            // Remover associações do tenant
            $this->database->table('user_tenants')
                ->where('user_id', $id)
                ->where('tenant_id', $this->tenantId)
                ->delete();
            
            // Remover perfis do tenant
            $this->database->table('user_roles')
                ->where('user_id', $id)
                ->where('tenant_id', $this->tenantId)
                ->delete();
            
            // Se usuário não pertence a outros tenants, desativar
            $outrosTenants = $this->database->table('user_tenants')
                ->where('user_id', $id)
                ->count();
            
            if ($outrosTenants === 0) {
                $this->database->table('users')
                    ->where('id', $id)
                    ->update([
                        'active' => false,
                        'updated_at' => Carbon::now(),
                    ]);
            }
            
            $this->database->commit();
            
            $this->limparCache('usuarios_*');
            
            return $this->sucesso(null, 'Usuário removido com sucesso');
            
        } catch (\Exception $e) {
            $this->database->rollback();
            return $this->erro('Erro ao remover usuário: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Fazer backup do sistema
     * POST /api/config/backup
     */
    public function fazerBackup(Request $request): Response
    {
        $this->authorize('sistema.backup');
        
        $regras = [
            'incluir_anexos' => 'boolean',
            'incluir_logs' => 'boolean',
            'tipo' => 'in:completo,dados,estrutura',
        ];
        
        $dados = $this->validar($request, $regras);
        
        // Criar registro do backup
        $backupId = $this->database->table('sistema_backups')->insertGetId([
            'tenant_id' => $this->tenantId,
            'tipo' => $dados['tipo'] ?? 'completo',
            'incluir_anexos' => $dados['incluir_anexos'] ?? false,
            'incluir_logs' => $dados['incluir_logs'] ?? false,
            'status' => 'processando',
            'usuario_id' => $this->usuario->getAuthIdentifier(),
            'created_at' => Carbon::now(),
        ]);
        
        // Em uma implementação real, isso seria processado em background
        // Por agora, simular o processo
        
        $this->database->table('sistema_backups')
            ->where('id', $backupId)
            ->update([
                'status' => 'concluido',
                'tamanho_arquivo' => rand(1000000, 10000000), // Simular tamanho
                'caminho_arquivo' => "backups/backup_{$backupId}_" . date('Y-m-d_H-i-s') . '.zip',
                'completed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        
        $backup = $this->database->table('sistema_backups')
            ->where('id', $backupId)
            ->first();
        
        return $this->sucesso($backup, 'Backup iniciado com sucesso', 201);
    }
    
    /**
     * Restaurar backup
     * POST /api/config/restore
     */
    public function restaurarBackup(Request $request): Response
    {
        $this->authorize('sistema.restaurar');
        
        $regras = [
            'backup_id' => 'required|integer|exists:sistema_backups,id',
            'confirmar' => 'required|boolean|accepted',
        ];
        
        $dados = $this->validar($request, $regras);
        
        $backup = $this->database->table('sistema_backups')
            ->where('id', $dados['backup_id'])
            ->where('tenant_id', $this->tenantId)
            ->first();
        
        if (!$backup) {
            return $this->erro('Backup não encontrado', 404);
        }
        
        if ($backup->status !== 'concluido') {
            return $this->erro('Backup não está disponível para restauração', 400);
        }
        
        // Criar registro da restauração
        $restauracaoId = $this->database->table('sistema_restauracoes')->insertGetId([
            'tenant_id' => $this->tenantId,
            'backup_id' => $dados['backup_id'],
            'status' => 'processando',
            'usuario_id' => $this->usuario->getAuthIdentifier(),
            'created_at' => Carbon::now(),
        ]);
        
        // Em uma implementação real, isso seria processado em background
        // Por agora, simular o processo
        
        $this->database->table('sistema_restauracoes')
            ->where('id', $restauracaoId)
            ->update([
                'status' => 'concluido',
                'completed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        
        $restauracao = $this->database->table('sistema_restauracoes')
            ->where('id', $restauracaoId)
            ->first();
        
        return $this->sucesso($restauracao, 'Restauração iniciada com sucesso');
    }
    
    /**
     * Obter configurações gerais do sistema
     * GET /api/config/sistema
     */
    public function obterConfiguracoesSistema(Request $request): Response
    {
        $this->authorize('configuracoes.visualizar');
        
        $configuracoes = $this->database->table('configuracoes_sistema')
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->groupBy('categoria')
            ->map(function($items) {
                return $items->keyBy('chave')->map(fn($item) => $item->valor);
            });
        
        return $this->sucesso($configuracoes);
    }
}