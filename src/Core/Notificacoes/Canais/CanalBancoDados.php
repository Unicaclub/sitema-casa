<?php

declare(strict_types=1);

namespace ERP\Core\Notificacoes\Canais;

use ERP\Core\Notificacoes\Notificacao;
use ERP\Core\Database\DatabaseManager;

/**
 * Canal de Notificação - Banco de Dados
 * 
 * Armazena notificações no banco de dados para visualização posterior
 * 
 * @package ERP\Core\Notificacoes\Canais
 */
final class CanalBancoDados implements CanalInterface
{
    public function __construct(
        private DatabaseManager $database
    ) {}
    
    public function suporta(string $tipo): bool
    {
        return true; // Canal banco sempre suporta todos os tipos
    }
    
    public function enviar(Notificacao $notificacao): bool
    {
        try {
            // A notificação já foi armazenada no GerenciadorNotificacoes
            // Este canal apenas confirma o sucesso
            return true;
            
        } catch (\Exception $e) {
            error_log("Erro no canal banco de dados: " . $e->getMessage());
            return false;
        }
    }
    
    public function obterConfiguracoes(): array
    {
        return [
            'nome' => 'Banco de Dados',
            'descricao' => 'Armazenamento persistente de notificações',
            'ativo' => true,
            'configuravel' => false,
        ];
    }
}