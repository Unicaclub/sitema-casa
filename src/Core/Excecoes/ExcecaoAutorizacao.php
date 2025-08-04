<?php

declare(strict_types=1);

namespace ERP\Core\Excecoes;

/**
 * Exceção de Autorização
 * 
 * Lançada quando há problemas de autorização/permissões
 * 
 * @package ERP\Core\Excecoes
 */
class ExcecaoAutorizacao extends ExcecaoBase
{
    protected string $tipoErro = 'erro_autorizacao';
    
    public static function permissaoNegada(string $permissao = ''): self
    {
        $mensagem = $permissao 
            ? "Acesso negado. Permissão necessária: {$permissao}"
            : 'Acesso negado. Permissões insuficientes';
            
        return new self($mensagem, 403, null, ['permissao' => $permissao]);
    }
    
    public static function perfilInsuficiente(string $perfil = ''): self
    {
        $mensagem = $perfil 
            ? "Perfil de acesso insuficiente. Perfil necessário: {$perfil}"
            : 'Perfil de acesso insuficiente';
            
        return new self($mensagem, 403, null, ['perfil' => $perfil]);
    }
    
    public static function tenantNaoAutorizado(): self
    {
        return new self('Acesso não autorizado a este tenant', 403);
    }
    
    public static function recursoProtegido(): self
    {
        return new self('Recurso protegido. Acesso não autorizado', 403);
    }
    
    public static function operacaoNaoPermitida(): self
    {
        return new self('Operação não permitida para este usuário', 403);
    }
}