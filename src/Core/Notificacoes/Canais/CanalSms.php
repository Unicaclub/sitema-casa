<?php

declare(strict_types=1);

namespace ERP\Core\Notificacoes\Canais;

use ERP\Core\Notificacoes\Notificacao;

/**
 * Canal de Notificação - SMS
 * 
 * Envia notificações por SMS usando API externa
 * 
 * @package ERP\Core\Notificacoes\Canais
 */
final class CanalSms implements CanalInterface
{
    private array $configuracoes;
    
    public function __construct()
    {
        $this->configuracoes = [
            'api_url' => $_ENV['SMS_API_URL'] ?? '',
            'api_key' => $_ENV['SMS_API_KEY'] ?? '',
            'remetente' => $_ENV['SMS_FROM'] ?? 'ERP',
            'timeout' => 15,
        ];
    }
    
    public function suporta(string $tipo): bool
    {
        $tiposSuportados = [
            'alerta_critico',
            'codigo_verificacao',
            'falha_sistema',
            'backup_falhou'
        ];
        
        return in_array($tipo, $tiposSuportados);
    }
    
    public function enviar(Notificacao $notificacao): bool
    {
        try {
            if (!$this->suporta($notificacao->obterTipo())) {
                return false;
            }
            
            if (empty($this->configuracoes['api_url']) || empty($this->configuracoes['api_key'])) {
                return false; // API não configurada
            }
            
            // Obter telefone do usuário (seria consultado no banco)
            $telefoneUsuario = $this->obterTelefoneUsuario($notificacao->obterUsuarioId());
            if (!$telefoneUsuario) {
                return false;
            }
            
            return $this->enviarSms(
                $telefoneUsuario,
                $this->formatarMensagemSms($notificacao)
            );
            
        } catch (\Exception $e) {
            error_log("Erro no canal SMS: " . $e->getMessage());
            return false;
        }
    }
    
    public function obterConfiguracoes(): array
    {
        return [
            'nome' => 'SMS',
            'descricao' => 'Notificações por SMS via API externa',
            'ativo' => !empty($this->configuracoes['api_url']),
            'configuravel' => true,
            'configuracoes' => [
                'provedor' => $this->configuracoes['api_url'],
                'remetente' => $this->configuracoes['remetente'],
                'timeout' => $this->configuracoes['timeout'],
            ],
        ];
    }
    
    private function obterTelefoneUsuario(int $usuarioId): ?string
    {
        // Em implementação real, consultaria o banco de dados
        // Simulando retorno para demonstração
        return "+5511999{$usuarioId}999";
    }
    
    private function formatarMensagemSms(Notificacao $notificacao): string
    {
        // SMS tem limite de caracteres, então formatamos de forma concisa
        $mensagem = $notificacao->obterTitulo();
        
        if (strlen($notificacao->obterMensagem()) <= 100) {
            $mensagem .= ": " . $notificacao->obterMensagem();
        }
        
        // Limitar a 160 caracteres (padrão SMS)
        if (strlen($mensagem) > 160) {
            $mensagem = substr($mensagem, 0, 157) . '...';
        }
        
        return $mensagem;
    }
    
    private function enviarSms(string $telefone, string $mensagem): bool
    {
        $dadosEnvio = [
            'to' => $telefone,
            'from' => $this->configuracoes['remetente'],
            'message' => $mensagem,
            'api_key' => $this->configuracoes['api_key'],
        ];
        
        $contexto = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                'content' => json_encode($dadosEnvio),
                'timeout' => $this->configuracoes['timeout'],
            ],
        ]);
        
        $resposta = @file_get_contents(
            $this->configuracoes['api_url'],
            false,
            $contexto
        );
        
        if ($resposta === false) {
            return false;
        }
        
        $dados = json_decode($resposta, true);
        return isset($dados['success']) && $dados['success'] === true;
    }
}