<?php

declare(strict_types=1);

namespace ERP\Core\Notificacoes;

use Carbon\Carbon;

/**
 * Classe de Notificação
 * 
 * Representa uma notificação do sistema
 * 
 * @package ERP\Core\Notificacoes
 */
final class Notificacao
{
    private int $usuarioId;
    private ?string $tenantId;
    private string $titulo;
    private string $mensagem;
    private string $tipo;
    private array $dados;
    private array $canais;
    private string $prioridade;
    private Carbon $criadoEm;
    
    public function __construct(array $parametros)
    {
        $this->usuarioId = $parametros['usuario_id'];
        $this->tenantId = $parametros['tenant_id'] ?? null;
        $this->titulo = $parametros['titulo'];
        $this->mensagem = $parametros['mensagem'];
        $this->tipo = $parametros['tipo'] ?? 'geral';
        $this->dados = $parametros['dados'] ?? [];
        $this->canais = $parametros['canais'] ?? ['banco'];
        $this->prioridade = $parametros['prioridade'] ?? 'normal';
        $this->criadoEm = $parametros['criado_em'] ?? Carbon::now();
    }
    
    public function obterUsuarioId(): int
    {
        return $this->usuarioId;
    }
    
    public function obterTenantId(): ?string
    {
        return $this->tenantId;
    }
    
    public function obterTitulo(): string
    {
        return $this->titulo;
    }
    
    public function obterMensagem(): string
    {
        return $this->mensagem;
    }
    
    public function obterTipo(): string
    {
        return $this->tipo;
    }
    
    public function obterDados(): array
    {
        return $this->dados;
    }
    
    public function obterCanais(): array
    {
        return $this->canais;
    }
    
    public function obterPrioridade(): string
    {
        return $this->prioridade;
    }
    
    public function obterCriadoEm(): Carbon
    {
        return $this->criadoEm;
    }
    
    public function paraArray(): array
    {
        return [
            'usuario_id' => $this->usuarioId,
            'tenant_id' => $this->tenantId,
            'titulo' => $this->titulo,
            'mensagem' => $this->mensagem,
            'tipo' => $this->tipo,
            'dados' => $this->dados,
            'canais' => $this->canais,
            'prioridade' => $this->prioridade,
            'criado_em' => $this->criadoEm->toISOString(),
        ];
    }
}