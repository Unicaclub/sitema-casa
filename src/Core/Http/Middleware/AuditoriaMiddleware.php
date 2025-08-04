<?php

declare(strict_types=1);

namespace ERP\Core\Http\Middleware;

use ERP\Core\Http\Request;
use ERP\Core\Http\Response;
use ERP\Core\Database\DatabaseManager;
use Carbon\Carbon;
use Closure;

/**
 * Middleware de Auditoria
 * 
 * Registra todas as ações dos usuários para fins de auditoria e compliance
 * 
 * @package ERP\Core\Http\Middleware
 */
final class AuditoriaMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DatabaseManager $database
    ) {}
    
    public function handle(Request $request, Closure $next): Response
    {
        $inicioProcessamento = microtime(true);
        $dadosAuditoria = $this->prepararDadosAuditoria($request);
        
        // Processar requisição
        $response = $next($request);
        
        // Completar dados de auditoria
        $dadosAuditoria['tempo_resposta'] = round((microtime(true) - $inicioProcessamento) * 1000, 2);
        $dadosAuditoria['codigo_status'] = $response->getStatusCode();
        $dadosAuditoria['tamanho_resposta'] = strlen($response->getContent());
        $dadosAuditoria['sucesso'] = $response->isSuccessful();
        
        // Registrar auditoria de forma assíncrona (não bloquear resposta)
        $this->registrarAuditoria($dadosAuditoria);
        
        return $response;
    }
    
    /**
     * Preparar dados básicos da auditoria
     */
    private function prepararDadosAuditoria(Request $request): array
    {
        return [
            'id_requisicao' => $request->getRequestId(),
            'timestamp' => Carbon::now(),
            'metodo_http' => $request->getMethod(),
            'url' => $request->getUri(),
            'caminho' => $request->getPathInfo(),
            'endereco_ip' => $request->getClientIp(),
            'user_agent' => $request->getUserAgent(),
            'tenant_id' => $request->getTenantId(),
            'usuario_id' => $request->getAttribute('user_id'),
            'dados_entrada' => $this->sanitizarDadosEntrada($request),
            'cabecalhos' => $this->filtrarCabecalhos($request->header()),
            'query_params' => $request->query(),
            'eh_ajax' => $request->isAjax(),
            'eh_json' => $request->isJson(),
            'referenciador' => $request->header('Referer'),
            'origem' => $request->header('Origin'),
        ];
    }
    
    /**
     * Registrar log de auditoria no banco de dados
     */
    private function registrarAuditoria(array $dados): void
    {
        try {
            // Determinar o tipo de ação baseado no método e caminho
            $dados['tipo_acao'] = $this->determinarTipoAcao($dados['metodo_http'], $dados['caminho']);
            $dados['categoria'] = $this->determinarCategoria($dados['caminho']);
            $dados['nivel_risco'] = $this->calcularNivelRisco($dados);
            $dados['descricao'] = $this->gerarDescricaoAcao($dados);
            
            // Inserir no banco de dados
            $this->database->table('auditoria_logs')->insert([
                'id_requisicao' => $dados['id_requisicao'],
                'timestamp' => $dados['timestamp'],
                'tipo_acao' => $dados['tipo_acao'],
                'categoria' => $dados['categoria'],
                'descricao' => $dados['descricao'],
                'metodo_http' => $dados['metodo_http'],
                'url' => $dados['url'],
                'caminho' => $dados['caminho'],
                'endereco_ip' => $dados['endereco_ip'],
                'user_agent' => $dados['user_agent'],
                'tenant_id' => $dados['tenant_id'],
                'usuario_id' => $dados['usuario_id'],
                'dados_entrada' => json_encode($dados['dados_entrada']),
                'cabecalhos' => json_encode($dados['cabecalhos']),
                'query_params' => json_encode($dados['query_params']),
                'codigo_status' => $dados['codigo_status'],
                'tempo_resposta' => $dados['tempo_resposta'],
                'tamanho_resposta' => $dados['tamanho_resposta'],
                'sucesso' => $dados['sucesso'],
                'nivel_risco' => $dados['nivel_risco'],
                'eh_ajax' => $dados['eh_ajax'],
                'eh_json' => $dados['eh_json'],
                'referenciador' => $dados['referenciador'],
                'origem' => $dados['origem'],
                'created_at' => Carbon::now(),
            ]);
            
            // Registrar alertas de segurança se necessário
            if ($dados['nivel_risco'] === 'alto') {
                $this->registrarAlertaSeguranca($dados);
            }
            
        } catch (\Exception $e) {
            // Falha silenciosa na auditoria para não afetar a aplicação
            error_log("Erro na auditoria: " . $e->getMessage());
        }
    }
    
    /**
     * Sanitizar dados de entrada removendo informações sensíveis
     */
    private function sanitizarDadosEntrada(Request $request): array
    {
        $dados = $request->all();
        $camposSensiveis = [
            'password', 'senha', 'token', 'secret', 'key', 'auth',
            'credit_card', 'cartao_credito', 'cpf', 'cnpj', 'rg',
            'social_security', 'ssn', 'passport', 'passaporte'
        ];
        
        foreach ($dados as $campo => $valor) {
            $campoLower = strtolower($campo);
            
            foreach ($camposSensiveis as $campoSensivel) {
                if (str_contains($campoLower, $campoSensivel)) {
                    $dados[$campo] = '[DADOS_PROTEGIDOS]';
                    break;
                }
            }
        }
        
        return $dados;
    }
    
    /**
     * Filtrar cabeçalhos removendo informações sensíveis
     */
    private function filtrarCabecalhos(array $cabecalhos): array
    {
        $cabecalhosSensiveis = [
            'authorization', 'cookie', 'x-api-key', 'x-auth-token',
            'x-csrf-token', 'x-forwarded-for', 'x-real-ip'
        ];
        
        $cabecalhosFiltrados = [];
        
        foreach ($cabecalhos as $nome => $valor) {
            $nomeLower = strtolower($nome);
            
            if (in_array($nomeLower, $cabecalhosSensiveis)) {
                $cabecalhosFiltrados[$nome] = '[PROTEGIDO]';
            } else {
                $cabecalhosFiltrados[$nome] = $valor;
            }
        }
        
        return $cabecalhosFiltrados;
    }
    
    /**
     * Determinar tipo de ação baseado no método HTTP e caminho
     */
    private function determinarTipoAcao(string $metodo, string $caminho): string
    {
        return match($metodo) {
            'GET' => str_contains($caminho, 'list') || str_contains($caminho, 'index') ? 'LISTAGEM' : 'VISUALIZACAO',
            'POST' => str_contains($caminho, 'create') ? 'CRIACAO' : 'ACAO',
            'PUT', 'PATCH' => 'ATUALIZACAO',
            'DELETE' => 'EXCLUSAO',
            'OPTIONS' => 'OPCOES',
            default => 'DESCONHECIDA',
        };
    }
    
    /**
     * Determinar categoria baseada no caminho da URL
     */
    private function determinarCategoria(string $caminho): string
    {
        if (str_contains($caminho, '/dashboard')) return 'DASHBOARD';
        if (str_contains($caminho, '/crm')) return 'CRM';
        if (str_contains($caminho, '/estoque')) return 'ESTOQUE';
        if (str_contains($caminho, '/vendas')) return 'VENDAS';
        if (str_contains($caminho, '/financeiro')) return 'FINANCEIRO';
        if (str_contains($caminho, '/relatorios')) return 'RELATORIOS';
        if (str_contains($caminho, '/config')) return 'CONFIGURACOES';
        if (str_contains($caminho, '/auth')) return 'AUTENTICACAO';
        
        return 'SISTEMA';
    }
    
    /**
     * Calcular nível de risco da operação
     */
    private function calcularNivelRisco(array $dados): string
    {
        $pontuacaoRisco = 0;
        
        // Risco por tipo de ação
        switch ($dados['tipo_acao']) {
            case 'EXCLUSAO':
                $pontuacaoRisco += 30;
                break;
            case 'ATUALIZACAO':
                $pontuacaoRisco += 20;
                break;
            case 'CRIACAO':
                $pontuacaoRisco += 15;
                break;
            case 'VISUALIZACAO':
                $pontuacaoRisco += 5;
                break;
        }
        
        // Risco por categoria
        if (in_array($dados['categoria'], ['CONFIGURACOES', 'FINANCEIRO'])) {
            $pontuacaoRisco += 25;
        } elseif (in_array($dados['categoria'], ['VENDAS', 'CRM'])) {
            $pontuacaoRisco += 15;
        }
        
        // Risco por falha de autenticação/autorização
        if (isset($dados['codigo_status']) && in_array($dados['codigo_status'], [401, 403])) {
            $pontuacaoRisco += 40;
        }
        
        // Risco por erro do servidor
        if (isset($dados['codigo_status']) && $dados['codigo_status'] >= 500) {
            $pontuacaoRisco += 20;
        }
        
        // Risco por origem suspeita (pode ser expandido)
        if (!$dados['usuario_id']) {
            $pontuacaoRisco += 10;
        }
        
        return match(true) {
            $pontuacaoRisco >= 50 => 'alto',
            $pontuacaoRisco >= 25 => 'medio',
            default => 'baixo',
        };
    }
    
    /**
     * Gerar descrição legível da ação
     */
    private function gerarDescricaoAcao(array $dados): string
    {
        $usuario = $dados['usuario_id'] ? "Usuário {$dados['usuario_id']}" : 'Usuário anônimo';
        $acao = strtolower($dados['tipo_acao']);
        $categoria = strtolower($dados['categoria']);
        
        $descricoes = [
            'LISTAGEM' => "visualizou listagem de {$categoria}",
            'VISUALIZACAO' => "visualizou detalhes de {$categoria}",
            'CRIACAO' => "criou novo item em {$categoria}",
            'ATUALIZACAO' => "atualizou item em {$categoria}",
            'EXCLUSAO' => "excluiu item de {$categoria}",
            'ACAO' => "executou ação em {$categoria}",
        ];
        
        $descricaoAcao = $descricoes[$dados['tipo_acao']] ?? "executou {$acao} em {$categoria}";
        
        return "{$usuario} {$descricaoAcao}";
    }
    
    /**
     * Registrar alerta de segurança para ações de alto risco
     */
    private function registrarAlertaSeguranca(array $dados): void
    {
        try {
            $this->database->table('alertas_seguranca')->insert([
                'tipo' => 'AUDITORIA_ALTO_RISCO',
                'descricao' => "Ação de alto risco detectada: {$dados['descricao']}",
                'dados_contexto' => json_encode([
                    'id_requisicao' => $dados['id_requisicao'],
                    'endereco_ip' => $dados['endereco_ip'],
                    'usuario_id' => $dados['usuario_id'],
                    'tenant_id' => $dados['tenant_id'],
                    'metodo_http' => $dados['metodo_http'],
                    'caminho' => $dados['caminho'],
                    'codigo_status' => $dados['codigo_status'] ?? null,
                    'nivel_risco' => $dados['nivel_risco'],
                ]),
                'nivel_severidade' => 'ALTO',
                'status' => 'NOVO',
                'tenant_id' => $dados['tenant_id'],
                'created_at' => Carbon::now(),
            ]);
        } catch (\Exception $e) {
            error_log("Erro ao registrar alerta de segurança: " . $e->getMessage());
        }
    }
}