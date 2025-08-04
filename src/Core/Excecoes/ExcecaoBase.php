<?php

declare(strict_types=1);

namespace ERP\Core\Excecoes;

use Exception;
use Throwable;

/**
 * Exceção Base do Sistema ERP
 * 
 * Classe base para todas as exceções personalizadas do sistema
 * 
 * @package ERP\Core\Excecoes
 */
abstract class ExcecaoBase extends Exception
{
    protected array $contexto = [];
    protected string $tipoErro = 'erro_sistema';
    
    public function __construct(
        string $mensagem = '',
        int $codigo = 0,
        ?Throwable $anterior = null,
        array $contexto = []
    ) {
        parent::__construct($mensagem, $codigo, $anterior);
        $this->contexto = $contexto;
    }
    
    /**
     * Obter contexto do erro
     */
    public function obterContexto(): array
    {
        return $this->contexto;
    }
    
    /**
     * Definir contexto do erro
     */
    public function definirContexto(array $contexto): self
    {
        $this->contexto = $contexto;
        return $this;
    }
    
    /**
     * Obter tipo do erro
     */
    public function obterTipoErro(): string
    {
        return $this->tipoErro;
    }
    
    /**
     * Converter para array para logging
     */
    public function paraArray(): array
    {
        return [
            'tipo' => $this->tipoErro,
            'mensagem' => $this->getMessage(),
            'codigo' => $this->getCode(),
            'arquivo' => $this->getFile(),
            'linha' => $this->getLine(),
            'contexto' => $this->contexto,
            'stack_trace' => $this->getTraceAsString(),
        ];
    }
    
    /**
     * Converter para JSON
     */
    public function paraJson(int $flags = 0): string
    {
        return json_encode($this->paraArray(), $flags);
    }
}