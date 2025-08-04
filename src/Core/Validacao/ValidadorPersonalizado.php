<?php

declare(strict_types=1);

namespace ERP\Core\Validacao;

use ERP\Core\Excecoes\ExcecaoValidacao;

/**
 * Validador Personalizado do Sistema ERP
 * 
 * Sistema de validação customizado para regras de negócio específicas
 * 
 * @package ERP\Core\Validacao
 */
final class ValidadorPersonalizado
{
    private array $regras = [];
    private array $mensagens = [];
    private array $erros = [];
    
    public function __construct()
    {
        $this->carregarMensagensPadrao();
    }
    
    /**
     * Validar dados com regras especificadas
     */
    public function validar(array $dados, array $regras, array $mensagensPersonalizadas = []): array
    {
        $this->regras = $regras;
        $this->mensagens = array_merge($this->mensagens, $mensagensPersonalizadas);
        $this->erros = [];
        
        foreach ($regras as $campo => $regrasCampo) {
            $valor = $dados[$campo] ?? null;
            $this->validarCampo($campo, $valor, $regrasCampo, $dados);
        }
        
        if (!empty($this->erros)) {
            throw new ExcecaoValidacao('Falha na validação dos dados', $this->erros);
        }
        
        return $dados;
    }
    
    /**
     * Validar campo específico
     */
    private function validarCampo(string $campo, mixed $valor, string $regras, array $todosDados): void
    {
        $regrasArray = explode('|', $regras);
        
        foreach ($regrasArray as $regra) {
            $this->aplicarRegra($campo, $valor, $regra, $todosDados);
        }
    }
    
    /**
     * Aplicar regra específica
     */
    private function aplicarRegra(string $campo, mixed $valor, string $regra, array $todosDados): void
    {
        $parametros = [];
        
        if (str_contains($regra, ':')) {
            [$regra, $parametrosStr] = explode(':', $regra, 2);
            $parametros = explode(',', $parametrosStr);
        }
        
        $metodo = 'validar' . ucfirst($regra);
        
        if (method_exists($this, $metodo)) {
            $this->$metodo($campo, $valor, $parametros, $todosDados);
        } else {
            $this->adicionarErro($campo, "Regra de validação '{$regra}' não reconhecida");
        }
    }
    
    /**
     * Validação: Campo obrigatório
     */
    private function validarRequired(string $campo, mixed $valor): void
    {
        if ($valor === null || $valor === '' || (is_array($valor) && empty($valor))) {
            $this->adicionarErro($campo, $this->mensagens['required'] ?? 'O campo :campo é obrigatório');
        }
    }
    
    /**
     * Validação: Tipo string
     */
    private function validarString(string $campo, mixed $valor): void
    {
        if ($valor !== null && !is_string($valor)) {
            $this->adicionarErro($campo, $this->mensagens['string'] ?? 'O campo :campo deve ser um texto');
        }
    }
    
    /**
     * Validação: Tipo numérico
     */
    private function validarNumeric(string $campo, mixed $valor): void
    {
        if ($valor !== null && !is_numeric($valor)) {
            $this->adicionarErro($campo, $this->mensagens['numeric'] ?? 'O campo :campo deve ser um número');
        }
    }
    
    /**
     * Validação: Tipo inteiro
     */
    private function validarInteger(string $campo, mixed $valor): void
    {
        if ($valor !== null && !filter_var($valor, FILTER_VALIDATE_INT)) {
            $this->adicionarErro($campo, $this->mensagens['integer'] ?? 'O campo :campo deve ser um número inteiro');
        }
    }
    
    /**
     * Validação: Email
     */
    private function validarEmail(string $campo, mixed $valor): void
    {
        if ($valor !== null && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            $this->adicionarErro($campo, $this->mensagens['email'] ?? 'O campo :campo deve ser um email válido');
        }
    }
    
    /**
     * Validação: Tamanho máximo
     */
    private function validarMax(string $campo, mixed $valor, array $parametros): void
    {
        $max = (int) ($parametros[0] ?? 0);
        
        if ($valor !== null) {
            $tamanho = is_string($valor) ? strlen($valor) : (is_numeric($valor) ? $valor : 0);
            
            if ($tamanho > $max) {
                $mensagem = $this->mensagens['max'] ?? 'O campo :campo não pode ser maior que :max';
                $this->adicionarErro($campo, str_replace(':max', (string)$max, $mensagem));
            }
        }
    }
    
    /**
     * Validação: Tamanho mínimo
     */
    private function validarMin(string $campo, mixed $valor, array $parametros): void
    {
        $min = (int) ($parametros[0] ?? 0);
        
        if ($valor !== null) {
            $tamanho = is_string($valor) ? strlen($valor) : (is_numeric($valor) ? $valor : 0);
            
            if ($tamanho < $min) {
                $mensagem = $this->mensagens['min'] ?? 'O campo :campo deve ter pelo menos :min caracteres';
                $this->adicionarErro($campo, str_replace(':min', (string)$min, $mensagem));
            }
        }
    }
    
    /**
     * Validação: Valores permitidos
     */
    private function validarIn(string $campo, mixed $valor, array $parametros): void
    {
        if ($valor !== null && !in_array($valor, $parametros)) {
            $valoresPermitidos = implode(', ', $parametros);
            $mensagem = $this->mensagens['in'] ?? 'O campo :campo deve ser um dos seguintes valores: :values';
            $this->adicionarErro($campo, str_replace(':values', $valoresPermitidos, $mensagem));
        }
    }
    
    /**
     * Validação: Data válida
     */
    private function validarDate(string $campo, mixed $valor): void
    {
        if ($valor !== null && !strtotime($valor)) {
            $this->adicionarErro($campo, $this->mensagens['date'] ?? 'O campo :campo deve ser uma data válida');
        }
    }
    
    /**
     * Validação: Data posterior ou igual
     */
    private function validarAfterOrEqual(string $campo, mixed $valor, array $parametros, array $todosDados): void
    {
        if ($valor === null) return;
        
        $campoComparacao = $parametros[0] ?? '';
        $valorComparacao = $todosDados[$campoComparacao] ?? $campoComparacao;
        
        $dataValor = strtotime($valor);
        $dataComparacao = strtotime($valorComparacao);
        
        if ($dataValor === false || $dataComparacao === false) {
            $this->adicionarErro($campo, 'Formato de data inválido para comparação');
            return;
        }
        
        if ($dataValor < $dataComparacao) {
            $mensagem = $this->mensagens['after_or_equal'] ?? 'O campo :campo deve ser posterior ou igual a :date';
            $this->adicionarErro($campo, str_replace(':date', $valorComparacao, $mensagem));
        }
    }
    
    /**
     * Validação: CNPJ
     */
    private function validarCnpj(string $campo, mixed $valor): void
    {
        if ($valor === null) return;
        
        $cnpj = preg_replace('/[^0-9]/', '', $valor);
        
        if (strlen($cnpj) !== 14 || !$this->validarDigitosCnpj($cnpj)) {
            $this->adicionarErro($campo, $this->mensagens['cnpj'] ?? 'O campo :campo deve ser um CNPJ válido');
        }
    }
    
    /**
     * Validação: CPF
     */
    private function validarCpf(string $campo, mixed $valor): void
    {
        if ($valor === null) return;
        
        $cpf = preg_replace('/[^0-9]/', '', $valor);
        
        if (strlen($cpf) !== 11 || !$this->validarDigitosCpf($cpf)) {
            $this->adicionarErro($campo, $this->mensagens['cpf'] ?? 'O campo :campo deve ser um CPF válido');
        }
    }
    
    /**
     * Validação: CEP
     */
    private function validarCep(string $campo, mixed $valor): void
    {
        if ($valor === null) return;
        
        $cep = preg_replace('/[^0-9]/', '', $valor);
        
        if (strlen($cep) !== 8) {
            $this->adicionarErro($campo, $this->mensagens['cep'] ?? 'O campo :campo deve ser um CEP válido');
        }
    }
    
    /**
     * Validação: Telefone
     */
    private function validarTelefone(string $campo, mixed $valor): void
    {
        if ($valor === null) return;
        
        $telefone = preg_replace('/[^0-9]/', '', $valor);
        
        if (strlen($telefone) < 10 || strlen($telefone) > 11) {
            $this->adicionarErro($campo, $this->mensagens['telefone'] ?? 'O campo :campo deve ser um telefone válido');
        }
    }
    
    /**
     * Validação: URL
     */
    private function validarUrl(string $campo, mixed $valor): void
    {
        if ($valor !== null && !filter_var($valor, FILTER_VALIDATE_URL)) {
            $this->adicionarErro($campo, $this->mensagens['url'] ?? 'O campo :campo deve ser uma URL válida');
        }
    }
    
    /**
     * Validação: Array
     */
    private function validarArray(string $campo, mixed $valor): void
    {
        if ($valor !== null && !is_array($valor)) {
            $this->adicionarErro($campo, $this->mensagens['array'] ?? 'O campo :campo deve ser um array');
        }
    }
    
    /**
     * Validação: Boolean
     */
    private function validarBoolean(string $campo, mixed $valor): void
    {
        if ($valor !== null && !is_bool($valor) && !in_array($valor, [0, 1, '0', '1', 'true', 'false'])) {
            $this->adicionarErro($campo, $this->mensagens['boolean'] ?? 'O campo :campo deve ser verdadeiro ou falso');
        }
    }
    
    /**
     * Adicionar erro de validação
     */
    private function adicionarErro(string $campo, string $mensagem): void
    {
        if (!isset($this->erros[$campo])) {
            $this->erros[$campo] = [];
        }
        
        $mensagemFormatada = str_replace(':campo', $campo, $mensagem);
        $this->erros[$campo][] = $mensagemFormatada;
    }
    
    /**
     * Carregar mensagens padrão
     */
    private function carregarMensagensPadrao(): void
    {
        $this->mensagens = [
            'required' => 'O campo :campo é obrigatório',
            'string' => 'O campo :campo deve ser um texto',
            'numeric' => 'O campo :campo deve ser um número',
            'integer' => 'O campo :campo deve ser um número inteiro',
            'email' => 'O campo :campo deve ser um email válido',
            'max' => 'O campo :campo não pode ser maior que :max',
            'min' => 'O campo :campo deve ter pelo menos :min caracteres',
            'in' => 'O campo :campo deve ser um dos seguintes valores: :values',
            'date' => 'O campo :campo deve ser uma data válida',
            'after_or_equal' => 'O campo :campo deve ser posterior ou igual a :date',
            'cnpj' => 'O campo :campo deve ser um CNPJ válido',
            'cpf' => 'O campo :campo deve ser um CPF válido',
            'cep' => 'O campo :campo deve ser um CEP válido',
            'telefone' => 'O campo :campo deve ser um telefone válido',
            'url' => 'O campo :campo deve ser uma URL válida',
            'array' => 'O campo :campo deve ser um array',
            'boolean' => 'O campo :campo deve ser verdadeiro ou falso',
        ];
    }
    
    /**
     * Validar dígitos do CNPJ
     */
    private function validarDigitosCnpj(string $cnpj): bool
    {
        // Algoritmo de validação do CNPJ
        $tamanho = strlen($cnpj) - 2;
        $numeros = substr($cnpj, 0, $tamanho);
        $digitos = substr($cnpj, $tamanho);
        $soma = 0;
        $pos = $tamanho - 7;
        
        for ($i = $tamanho; $i >= 1; $i--) {
            $soma += $numeros[$tamanho - $i] * $pos--;
            if ($pos < 2) $pos = 9;
        }
        
        $resultado = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
        if ($resultado != $digitos[0]) return false;
        
        $tamanho++;
        $numeros = substr($cnpj, 0, $tamanho);
        $soma = 0;
        $pos = $tamanho - 7;
        
        for ($i = $tamanho; $i >= 1; $i--) {
            $soma += $numeros[$tamanho - $i] * $pos--;
            if ($pos < 2) $pos = 9;
        }
        
        $resultado = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
        return $resultado == $digitos[1];
    }
    
    /**
     * Validar dígitos do CPF
     */
    private function validarDigitosCpf(string $cpf): bool
    {
        // Verificar sequências inválidas
        if (preg_match('/(\d)\1{10}/', $cpf)) return false;
        
        // Validar primeiro dígito
        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += $cpf[$i] * (10 - $i);
        }
        $resto = $soma % 11;
        $digito1 = $resto < 2 ? 0 : 11 - $resto;
        
        if ($cpf[9] != $digito1) return false;
        
        // Validar segundo dígito
        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += $cpf[$i] * (11 - $i);
        }
        $resto = $soma % 11;
        $digito2 = $resto < 2 ? 0 : 11 - $resto;
        
        return $cpf[10] == $digito2;
    }
}