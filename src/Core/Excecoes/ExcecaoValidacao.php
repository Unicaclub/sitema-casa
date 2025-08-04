<?php

declare(strict_types=1);

namespace ERP\Core\Excecoes;

/**
 * Exceção de Validação
 * 
 * Lançada quando há erros de validação de dados
 * 
 * @package ERP\Core\Excecoes
 */
class ExcecaoValidacao extends ExcecaoBase
{
    protected string $tipoErro = 'erro_validacao';
    protected array $errosValidacao = [];
    
    public function __construct(
        string $mensagem = 'Dados inválidos',
        array $errosValidacao = [],
        int $codigo = 422
    ) {
        parent::__construct($mensagem, $codigo);
        $this->errosValidacao = $errosValidacao;
        $this->contexto = ['erros' => $errosValidacao];
    }
    
    /**
     * Obter erros de validação
     */
    public function obterErrosValidacao(): array
    {
        return $this->errosValidacao;
    }
    
    /**
     * Adicionar erro de validação
     */
    public function adicionarErro(string $campo, string $mensagem): self
    {
        if (!isset($this->errosValidacao[$campo])) {
            $this->errosValidacao[$campo] = [];
        }
        
        $this->errosValidacao[$campo][] = $mensagem;
        $this->contexto['erros'] = $this->errosValidacao;
        
        return $this;
    }
    
    /**
     * Verificar se há erros para um campo específico
     */
    public function temErro(string $campo): bool
    {
        return isset($this->errosValidacao[$campo]) && !empty($this->errosValidacao[$campo]);
    }
    
    /**
     * Obter erros de um campo específico
     */
    public function obterErrosCampo(string $campo): array
    {
        return $this->errosValidacao[$campo] ?? [];
    }
    
    /**
     * Verificar se há erros
     */
    public function temErros(): bool
    {
        return !empty($this->errosValidacao);
    }
    
    /**
     * Criar exceção para campo obrigatório
     */
    public static function campoObrigatorio(string $campo): self
    {
        return new self("Campo obrigatório: {$campo}", [$campo => ["O campo {$campo} é obrigatório"]]);
    }
    
    /**
     * Criar exceção para formato inválido
     */
    public static function formatoInvalido(string $campo, string $formato = ''): self
    {
        $mensagem = $formato 
            ? "Formato inválido para o campo {$campo}. Formato esperado: {$formato}"
            : "Formato inválido para o campo {$campo}";
        
        return new self($mensagem, [$campo => [$mensagem]]);
    }
    
    /**
     * Criar exceção para valor duplicado
     */
    public static function valorDuplicado(string $campo, string $valor = ''): self
    {
        $mensagem = $valor 
            ? "Valor '{$valor}' já existe para o campo {$campo}"
            : "Valor duplicado para o campo {$campo}";
        
        return new self($mensagem, [$campo => [$mensagem]]);
    }
    
    /**
     * Criar exceção para valor fora do intervalo
     */
    public static function valorForaIntervalo(string $campo, mixed $min = null, mixed $max = null): self
    {
        $mensagem = "Valor do campo {$campo} está fora do intervalo permitido";
        
        if ($min !== null && $max !== null) {
            $mensagem .= " ({$min} - {$max})";
        } elseif ($min !== null) {
            $mensagem .= " (mínimo: {$min})";
        } elseif ($max !== null) {
            $mensagem .= " (máximo: {$max})";
        }
        
        return new self($mensagem, [$campo => [$mensagem]]);
    }
}