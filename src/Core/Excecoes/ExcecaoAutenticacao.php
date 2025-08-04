<?php

declare(strict_types=1);

namespace ERP\Core\Excecoes;

/**
 * Exceção de Autenticação
 * 
 * Lançada quando há problemas de autenticação
 * 
 * @package ERP\Core\Excecoes
 */
class ExcecaoAutenticacao extends ExcecaoBase
{
    protected string $tipoErro = 'erro_autenticacao';
    
    public static function tokenInvalido(): self
    {
        return new self('Token de autenticação inválido ou expirado', 401);
    }
    
    public static function credenciaisInvalidas(): self
    {
        return new self('Credenciais de acesso inválidas', 401);
    }
    
    public static function contaBloqueada(): self
    {
        return new self('Conta de usuário está bloqueada', 423);
    }
    
    public static function tentativasExcedidas(): self
    {
        return new self('Muitas tentativas de login. Tente novamente mais tarde', 429);
    }
    
    public static function sessaoExpirada(): self
    {
        return new self('Sessão expirada. Faça login novamente', 401);
    }
    
    public static function tokenAusente(): self
    {
        return new self('Token de autenticação não fornecido', 401);
    }
}