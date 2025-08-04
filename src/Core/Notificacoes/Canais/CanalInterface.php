<?php

declare(strict_types=1);

namespace ERP\Core\Notificacoes\Canais;

use ERP\Core\Notificacoes\Notificacao;

/**
 * Interface para Canais de Notificação
 * 
 * Define o contrato para implementação de canais de notificação
 * 
 * @package ERP\Core\Notificacoes\Canais
 */
interface CanalInterface
{
    /**
     * Verificar se o canal suporta o tipo de notificação
     */
    public function suporta(string $tipo): bool;
    
    /**
     * Enviar notificação pelo canal
     */
    public function enviar(Notificacao $notificacao): bool;
    
    /**
     * Obter configurações do canal
     */
    public function obterConfiguracoes(): array;
}