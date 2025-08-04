<?php

declare(strict_types=1);

namespace ERP\Core\Notificacoes;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Cache\CacheInterface;
use ERP\Core\Notificacoes\Canais\CanalBancoDados;
use ERP\Core\Notificacoes\Canais\CanalPush;
use ERP\Core\Notificacoes\Canais\CanalEmail;
use ERP\Core\Notificacoes\Canais\CanalSms;
use Carbon\Carbon;

/**
 * Gerenciador de Notificações
 * 
 * Sistema completo de notificações em tempo real para o ERP
 * 
 * @package ERP\Core\Notificacoes
 */
final class GerenciadorNotificacoes
{
    private array $canais = [];
    private array $ouvintes = [];
    
    public function __construct(
        private DatabaseManager $database,
        private CacheInterface $cache
    ) {
        $this->inicializarCanais();
    }
    
    /**
     * Enviar notificação
     */
    public function enviar(Notificacao $notificacao): bool
    {
        try {
            // Armazenar notificação no banco
            $idNotificacao = $this->armazenarNotificacao($notificacao);
            
            // Enviar pelos canais configurados
            $sucessos = [];
            foreach ($notificacao->obterCanais() as $canal) {
                if (isset($this->canais[$canal])) {
                    $sucessos[$canal] = $this->canais[$canal]->enviar($notificacao);
                }
            }
            
            // Atualizar status da notificação
            $this->atualizarStatusNotificacao($idNotificacao, $sucessos);
            
            // Disparar evento para ouvintes
            $this->dispararEvento('notificacao.enviada', $notificacao);
            
            return !empty(array_filter($sucessos));
            
        } catch (\Exception $e) {
            error_log("Erro ao enviar notificação: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enviar notificação para múltiplos usuários
     */
    public function enviarParaUsuarios(array $usuariosIds, string $titulo, string $mensagem, array $dados = [], array $canais = ['banco']): bool
    {
        $sucessos = [];
        
        foreach ($usuariosIds as $usuarioId) {
            $notificacao = new Notificacao([
                'usuario_id' => $usuarioId,
                'titulo' => $titulo,
                'mensagem' => $mensagem,
                'dados' => $dados,
                'canais' => $canais,
            ]);
            
            $sucessos[] = $this->enviar($notificacao);
        }
        
        return !empty(array_filter($sucessos));
    }
    
    /**
     * Enviar notificação para todos os usuários de um tenant
     */
    public function enviarParaTenant(string $tenantId, string $titulo, string $mensagem, array $dados = [], array $canais = ['banco']): bool
    {
        $usuarios = $this->database->table('user_tenants')
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->pluck('user_id')
            ->toArray();
        
        return $this->enviarParaUsuarios($usuarios, $titulo, $mensagem, $dados, $canais);
    }
    
    /**
     * Obter notificações não lidas de um usuário
     */
    public function obterNaoLidas(int $usuarioId, int $limite = 20): array
    {
        $chaveCache = "notificacoes:nao_lidas:{$usuarioId}";
        
        return $this->cache->remember($chaveCache, function() use ($usuarioId, $limite) {
            return $this->database->table('notificacoes')
                ->where('usuario_id', $usuarioId)
                ->where('lida', false)
                ->orderBy('created_at', 'desc')
                ->limit($limite)
                ->get()
                ->toArray();
        }, 300); // Cache por 5 minutos
    }
    
    /**
     * Contar notificações não lidas
     */
    public function contarNaoLidas(int $usuarioId): int
    {
        $chaveCache = "notificacoes:contador:{$usuarioId}";
        
        return $this->cache->remember($chaveCache, function() use ($usuarioId) {
            return $this->database->table('notificacoes')
                ->where('usuario_id', $usuarioId)
                ->where('lida', false)
                ->count();
        }, 300);
    }
    
    /**
     * Marcar notificação como lida
     */
    public function marcarComoLida(int $notificacaoId, int $usuarioId): bool
    {
        $resultado = $this->database->table('notificacoes')
            ->where('id', $notificacaoId)
            ->where('usuario_id', $usuarioId)
            ->update([
                'lida' => true,
                'lida_em' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        
        if ($resultado) {
            // Limpar cache
            $this->cache->forget("notificacoes:nao_lidas:{$usuarioId}");
            $this->cache->forget("notificacoes:contador:{$usuarioId}");
        }
        
        return $resultado > 0;
    }
    
    /**
     * Marcar todas as notificações como lidas
     */
    public function marcarTodasComoLidas(int $usuarioId): bool
    {
        $resultado = $this->database->table('notificacoes')
            ->where('usuario_id', $usuarioId)
            ->where('lida', false)
            ->update([
                'lida' => true,
                'lida_em' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        
        if ($resultado) {
            // Limpar cache
            $this->cache->forget("notificacoes:nao_lidas:{$usuarioId}");
            $this->cache->forget("notificacoes:contador:{$usuarioId}");
        }
        
        return $resultado > 0;
    }
    
    /**
     * Remover notificação
     */
    public function remover(int $notificacaoId, int $usuarioId): bool
    {
        $resultado = $this->database->table('notificacoes')
            ->where('id', $notificacaoId)
            ->where('usuario_id', $usuarioId)
            ->delete();
        
        if ($resultado) {
            // Limpar cache
            $this->cache->forget("notificacoes:nao_lidas:{$usuarioId}");
            $this->cache->forget("notificacoes:contador:{$usuarioId}");
        }
        
        return $resultado > 0;
    }
    
    /**
     * Obter histórico de notificações
     */
    public function obterHistorico(int $usuarioId, int $pagina = 1, int $porPagina = 15): array
    {
        $offset = ($pagina - 1) * $porPagina;
        
        $notificacoes = $this->database->table('notificacoes')
            ->where('usuario_id', $usuarioId)
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($porPagina)
            ->get()
            ->toArray();
        
        $total = $this->database->table('notificacoes')
            ->where('usuario_id', $usuarioId)
            ->count();
        
        return [
            'dados' => $notificacoes,
            'paginacao' => [
                'pagina_atual' => $pagina,
                'por_pagina' => $porPagina,
                'total' => $total,
                'ultima_pagina' => (int) ceil($total / $porPagina),
            ],
        ];
    }
    
    /**
     * Registrar ouvinte de evento
     */
    public function adicionarOuvinte(string $evento, callable $callback): void
    {
        if (!isset($this->ouvintes[$evento])) {
            $this->ouvintes[$evento] = [];
        }
        
        $this->ouvintes[$evento][] = $callback;
    }
    
    /**
     * Criar notificação automática para estoque baixo
     */
    public function notificarEstoqueBaixo(array $produtos, string $tenantId): bool
    {
        $titulo = 'Alerta de Estoque Baixo';
        $mensagem = count($produtos) === 1 
            ? "O produto {$produtos[0]['nome']} está com estoque baixo"
            : count($produtos) . ' produtos estão com estoque baixo';
        
        return $this->enviarParaTenant($tenantId, $titulo, $mensagem, [
            'tipo' => 'estoque_baixo',
            'produtos' => $produtos,
            'acao_requerida' => 'Verificar e reabastecer estoque',
        ], ['banco', 'push']);
    }
    
    /**
     * Criar notificação para nova venda
     */
    public function notificarNovaVenda(array $dadosVenda, string $tenantId): bool
    {
        $titulo = 'Nova Venda Realizada';
        $mensagem = "Venda #{$dadosVenda['numero_venda']} no valor de R$ " . number_format($dadosVenda['valor_total'], 2, ',', '.');
        
        return $this->enviarParaTenant($tenantId, $titulo, $mensagem, [
            'tipo' => 'nova_venda',
            'venda_id' => $dadosVenda['id'],
            'valor' => $dadosVenda['valor_total'],
            'cliente' => $dadosVenda['cliente_nome'] ?? 'N/A',
        ], ['banco']);
    }
    
    /**
     * Criar notificação para meta atingida
     */
    public function notificarMetaAtingida(string $tipoMeta, float $valorMeta, float $valorAtingido, string $periodo, string $tenantId): bool
    {
        $percentual = ($valorAtingido / $valorMeta) * 100;
        $titulo = "🎉 Meta {$tipoMeta} Atingida!";
        $mensagem = "Parabéns! A meta de {$tipoMeta} do período {$periodo} foi atingida ({$percentual:.1f}%)";
        
        return $this->enviarParaTenant($tenantId, $titulo, $mensagem, [
            'tipo' => 'meta_atingida',
            'tipo_meta' => $tipoMeta,
            'valor_meta' => $valorMeta,
            'valor_atingido' => $valorAtingido,
            'percentual' => $percentual,
            'periodo' => $periodo,
        ], ['banco', 'push']);
    }
    
    /**
     * Armazenar notificação no banco de dados
     */
    private function armazenarNotificacao(Notificacao $notificacao): int
    {
        return $this->database->table('notificacoes')->insertGetId([
            'usuario_id' => $notificacao->obterUsuarioId(),
            'tenant_id' => $notificacao->obterTenantId(),
            'titulo' => $notificacao->obterTitulo(),
            'mensagem' => $notificacao->obterMensagem(),
            'tipo' => $notificacao->obterTipo(),
            'dados' => json_encode($notificacao->obterDados()),
            'canais' => json_encode($notificacao->obterCanais()),
            'prioridade' => $notificacao->obterPrioridade(),
            'lida' => false,
            'enviada' => false,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
    
    /**
     * Atualizar status da notificação após envio
     */
    private function atualizarStatusNotificacao(int $id, array $sucessos): void
    {
        $enviada = !empty(array_filter($sucessos));
        
        $this->database->table('notificacoes')
            ->where('id', $id)
            ->update([
                'enviada' => $enviada,
                'status_canais' => json_encode($sucessos),
                'enviada_em' => $enviada ? Carbon::now() : null,
                'updated_at' => Carbon::now(),
            ]);
    }
    
    /**
     * Disparar evento para ouvintes registrados
     */
    private function dispararEvento(string $evento, mixed $dados): void
    {
        if (isset($this->ouvintes[$evento])) {
            foreach ($this->ouvintes[$evento] as $callback) {
                try {
                    $callback($dados);
                } catch (\Exception $e) {
                    error_log("Erro no ouvinte de evento {$evento}: " . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * Inicializar canais de notificação
     */
    private function inicializarCanais(): void
    {
        // Canal de banco de dados (sempre disponível)
        $this->canais['banco'] = new CanalBancoDados($this->database);
        
        // Canal push (WebSocket/Server-Sent Events)
        if (extension_loaded('sockets')) {
            $this->canais['push'] = new CanalPush();
        }
        
        // Canal email (se configurado)
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            $this->canais['email'] = new CanalEmail();
        }
        
        // Canal SMS (se configurado)
        if (function_exists('curl_init')) {
            $this->canais['sms'] = new CanalSms();
        }
    }
}