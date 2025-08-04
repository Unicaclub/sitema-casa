<?php

declare(strict_types=1);

namespace ERP\Core\Notificacoes\Canais;

use ERP\Core\Notificacoes\Notificacao;

/**
 * Canal de Notificação - Email
 * 
 * Envia notificações por email usando PHPMailer
 * 
 * @package ERP\Core\Notificacoes\Canais
 */
final class CanalEmail implements CanalInterface
{
    private array $configuracoes;
    
    public function __construct()
    {
        $this->configuracoes = [
            'smtp_host' => $_ENV['MAIL_HOST'] ?? '',
            'smtp_port' => (int) ($_ENV['MAIL_PORT'] ?? 587),
            'smtp_username' => $_ENV['MAIL_USERNAME'] ?? '',
            'smtp_password' => $_ENV['MAIL_PASSWORD'] ?? '',
            'from_email' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@erp.com',
            'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'ERP Sistema',
        ];
    }
    
    public function suporta(string $tipo): bool
    {
        $tiposSuportados = [
            'backup_concluido',
            'relatorio_mensal',
            'alerta_critico',
            'nova_conta_usuario',
            'resetar_senha'
        ];
        
        return in_array($tipo, $tiposSuportados);
    }
    
    public function enviar(Notificacao $notificacao): bool
    {
        try {
            if (!$this->suporta($notificacao->obterTipo())) {
                return false;
            }
            
            if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                return false; // PHPMailer não disponível
            }
            
            // Obter email do usuário (seria consultado no banco)
            $emailUsuario = $this->obterEmailUsuario($notificacao->obterUsuarioId());
            if (!$emailUsuario) {
                return false;
            }
            
            return $this->enviarEmail(
                $emailUsuario,
                $notificacao->obterTitulo(),
                $this->formatarMensagemEmail($notificacao)
            );
            
        } catch (\Exception $e) {
            error_log("Erro no canal email: " . $e->getMessage());
            return false;
        }
    }
    
    public function obterConfiguracoes(): array
    {
        return [
            'nome' => 'Email',
            'descricao' => 'Notificações por email via SMTP',
            'ativo' => !empty($this->configuracoes['smtp_host']),
            'configuravel' => true,
            'configuracoes' => [
                'servidor' => $this->configuracoes['smtp_host'],
                'porta' => $this->configuracoes['smtp_port'],
                'de' => $this->configuracoes['from_email'],
            ],
        ];
    }
    
    private function obterEmailUsuario(int $usuarioId): ?string
    {
        // Em implementação real, consultaria o banco de dados
        // Simulando retorno para demonstração
        return "usuario{$usuarioId}@empresa.com";
    }
    
    private function formatarMensagemEmail(Notificacao $notificacao): string
    {
        $html = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #333;'>{$notificacao->obterTitulo()}</h2>
                <p>{$notificacao->obterMensagem()}</p>
                
                <hr style='border: 1px solid #eee; margin: 20px 0;'>
                
                <div style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>
                    <p style='margin: 0; font-size: 12px; color: #666;'>
                        Esta é uma notificação automática do sistema ERP.<br>
                        Data: " . date('d/m/Y H:i') . "
                    </p>
                </div>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    private function enviarEmail(string $para, string $assunto, string $mensagem): bool
    {
        // Implementação simplificada - em produção usaria PHPMailer completo
        $cabecalhos = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $this->configuracoes['from_name'] . ' <' . $this->configuracoes['from_email'] . '>',
            'Reply-To: ' . $this->configuracoes['from_email'],
            'X-Mailer: ERP Sistema v2.0'
        ];
        
        return @mail($para, $assunto, $mensagem, implode("\r\n", $cabecalhos));
    }
}