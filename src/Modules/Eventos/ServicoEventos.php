<?php

declare(strict_types=1);

namespace ERP\Modules\Eventos;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Logger;
use ERP\Core\Cache\CacheManager;
use DateTime;
use Exception;

/**
 * Serviço Supremo de Eventos VIP
 * 
 * Sistema completo para gestão de eventos com:
 * - Vendas antecipadas de convites
 * - Listas VIP e promoters integrados
 * - Controle total por CPF
 * - PDV integrado com autenticação
 * - Dashboard em tempo real
 * - Analytics avançados
 * 
 * @package ERP\Modules\Eventos
 */
final class ServicoEventos
{
    private DatabaseManager $database;
    private Logger $logger;
    private CacheManager $cache;
    
    public function __construct(
        DatabaseManager $database,
        Logger $logger,
        CacheManager $cache
    ) {
        $this->database = $database;
        $this->logger = $logger;
        $this->cache = $cache;
    }
    
    /**
     * Criar novo evento
     */
    public function criarEvento(array $dados_evento): array
    {
        try {
            $this->logger->info("Criando novo evento", $dados_evento);
            
            // Validar dados obrigatórios
            $this->validarDadosEvento($dados_evento);
            
            $query = "
                INSERT INTO eventos (
                    nome_evento, descricao_evento, data_evento, hora_inicio, hora_fim,
                    local_evento, endereco_completo, capacidade_maxima, 
                    valor_ingresso_padrao, percentual_comissao_promoter, criado_por
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $parametros = [
                $dados_evento['nome_evento'],
                $dados_evento['descricao_evento'] ?? '',
                $dados_evento['data_evento'],
                $dados_evento['hora_inicio'],
                $dados_evento['hora_fim'] ?? null,
                $dados_evento['local_evento'],
                $dados_evento['endereco_completo'] ?? '',
                $dados_evento['capacidade_maxima'] ?? 1000,
                $dados_evento['valor_ingresso_padrao'] ?? 0.00,
                $dados_evento['percentual_comissao_promoter'] ?? 10.00,
                $dados_evento['criado_por']
            ];
            
            $id_evento = $this->database->insert($query, $parametros);
            
            // Criar listas padrão para o evento
            $this->criarListasPadrao($id_evento);
            
            // Limpar cache
            $this->cache->delete("evento_detalhes_{$id_evento}");
            $this->cache->delete("eventos_lista");
            
            $this->logger->info("Evento criado com sucesso", ['id_evento' => $id_evento]);
            
            return [
                'sucesso' => true,
                'id_evento' => $id_evento,
                'mensagem' => 'Evento criado com sucesso!'
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Erro ao criar evento", [
                'erro' => $e->getMessage(),
                'dados' => $dados_evento
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro ao criar evento: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Buscar cliente por CPF (função principal do sistema)
     */
    public function buscarClientePorCpf(string $cpf): array
    {
        try {
            // Limpar e validar CPF
            $cpf = $this->limparCpf($cpf);
            
            if (!$this->validarCpf($cpf)) {
                return [
                    'sucesso' => false,
                    'erro' => 'CPF inválido'
                ];
            }
            
            // Verificar cache primeiro
            $cache_key = "cliente_cpf_{$cpf}";
            $cliente_cache = $this->cache->get($cache_key);
            
            if ($cliente_cache) {
                return [
                    'sucesso' => true,
                    'cliente' => $cliente_cache,
                    'origem' => 'cache'
                ];
            }
            
            $query = "
                SELECT 
                    c.*,
                    COUNT(DISTINCT ve.id_evento) as total_eventos,
                    COUNT(DISTINCT ve.id_venda) as total_ingressos,
                    COALESCE(SUM(ve.valor_final), 0) as total_gasto,
                    COUNT(DISTINCT pe.id_presenca) as total_presencas
                FROM clientes_eventos c
                LEFT JOIN vendas_eventos ve ON c.id_cliente = ve.id_cliente 
                    AND ve.status_pagamento = 'aprovado'
                LEFT JOIN presencas_eventos pe ON c.id_cliente = pe.id_cliente
                WHERE c.cpf_cliente = ? AND c.ativo = TRUE
                GROUP BY c.id_cliente
            ";
            
            $cliente = $this->database->fetchRow($query, [$cpf]);
            
            if (!$cliente) {
                return [
                    'sucesso' => false,
                    'erro' => 'Cliente não encontrado',
                    'cpf_consultado' => $cpf
                ];
            }
            
            // Buscar histórico de eventos
            $cliente['historico_eventos'] = $this->buscarHistoricoEventosCliente($cliente['id_cliente']);
            
            // Cache por 5 minutos
            $this->cache->set($cache_key, $cliente, 300);
            
            return [
                'sucesso' => true,
                'cliente' => $cliente
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Erro ao buscar cliente por CPF", [
                'cpf' => $cpf,
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro interno do sistema'
            ];
        }
    }
    
    /**
     * Cadastrar ou atualizar cliente
     */
    public function cadastrarCliente(array $dados_cliente): array
    {
        try {
            $cpf = $this->limparCpf($dados_cliente['cpf_cliente']);
            
            if (!$this->validarCpf($cpf)) {
                return [
                    'sucesso' => false,
                    'erro' => 'CPF inválido'
                ];
            }
            
            // Verificar se cliente já existe
            $cliente_existente = $this->database->fetchRow(
                "SELECT id_cliente FROM clientes_eventos WHERE cpf_cliente = ?",
                [$cpf]
            );
            
            if ($cliente_existente) {
                // Atualizar cliente existente
                $query = "
                    UPDATE clientes_eventos SET
                        nome_cliente = ?,
                        data_nascimento = ?,
                        genero = ?,
                        telefone = ?,
                        email = ?,
                        endereco = ?,
                        observacoes = ?,
                        data_ultima_atualizacao = NOW()
                    WHERE cpf_cliente = ?
                ";
                
                $parametros = [
                    $dados_cliente['nome_cliente'],
                    $dados_cliente['data_nascimento'] ?? null,
                    $dados_cliente['genero'] ?? 'nao_informado',
                    $dados_cliente['telefone'] ?? null,
                    $dados_cliente['email'] ?? null,
                    $dados_cliente['endereco'] ?? null,
                    $dados_cliente['observacoes'] ?? null,
                    $cpf
                ];
                
                $this->database->execute($query, $parametros);
                $id_cliente = $cliente_existente['id_cliente'];
                $acao = 'atualizado';
                
            } else {
                // Criar novo cliente
                $query = "
                    INSERT INTO clientes_eventos (
                        cpf_cliente, nome_cliente, data_nascimento, genero,
                        telefone, email, endereco, observacoes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ";
                
                $parametros = [
                    $cpf,
                    $dados_cliente['nome_cliente'],
                    $dados_cliente['data_nascimento'] ?? null,
                    $dados_cliente['genero'] ?? 'nao_informado',
                    $dados_cliente['telefone'] ?? null,
                    $dados_cliente['email'] ?? null,
                    $dados_cliente['endereco'] ?? null,
                    $dados_cliente['observacoes'] ?? null
                ];
                
                $id_cliente = $this->database->insert($query, $parametros);
                $acao = 'cadastrado';
            }
            
            // Limpar cache
            $this->cache->delete("cliente_cpf_{$cpf}");
            
            $this->logger->info("Cliente {$acao}", [
                'id_cliente' => $id_cliente,
                'cpf' => $cpf,
                'nome' => $dados_cliente['nome_cliente']
            ]);
            
            return [
                'sucesso' => true,
                'id_cliente' => $id_cliente,
                'acao' => $acao,
                'mensagem' => "Cliente {$acao} com sucesso!"
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Erro ao cadastrar cliente", [
                'dados' => $dados_cliente,
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro ao cadastrar cliente: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Realizar venda de ingresso
     */
    public function realizarVenda(array $dados_venda): array
    {
        try {
            $this->database->beginTransaction();
            
            // Validar dados da venda
            $this->validarDadosVenda($dados_venda);
            
            // Verificar disponibilidade na lista
            $lista = $this->database->fetchRow(
                "SELECT * FROM listas_eventos WHERE id_lista = ? AND status_lista = 'ativa'",
                [$dados_venda['id_lista']]
            );
            
            if (!$lista) {
                throw new Exception('Lista não encontrada ou inativa');
            }
            
            // Verificar capacidade
            $vendas_atuais = $this->database->fetchColumn(
                "SELECT COUNT(*) FROM vendas_eventos WHERE id_lista = ? AND status_pagamento = 'aprovado'",
                [$dados_venda['id_lista']]
            );
            
            if ($vendas_atuais >= $lista['capacidade_maxima']) {
                throw new Exception('Lista lotada');
            }
            
            // Gerar código único do ingresso
            $codigo_ingresso = $this->gerarCodigoIngresso($dados_venda['id_evento']);
            
            // Calcular valores
            $valor_original = $lista['valor_ingresso'];
            $percentual_desconto = $dados_venda['percentual_desconto'] ?? $lista['percentual_desconto'];
            $valor_desconto = ($valor_original * $percentual_desconto) / 100;
            $valor_final = $valor_original - $valor_desconto;
            
            // Inserir venda
            $query = "
                INSERT INTO vendas_eventos (
                    id_evento, id_lista, id_cliente, id_promoter, codigo_ingresso,
                    valor_original, percentual_desconto, valor_desconto, valor_final,
                    forma_pagamento, status_pagamento, vendido_por_usuario
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $parametros = [
                $dados_venda['id_evento'],
                $dados_venda['id_lista'],
                $dados_venda['id_cliente'],
                $dados_venda['id_promoter'] ?? null,
                $codigo_ingresso,
                $valor_original,
                $percentual_desconto,
                $valor_desconto,
                $valor_final,
                $dados_venda['forma_pagamento'] ?? 'pix',
                $dados_venda['status_pagamento'] ?? 'aprovado',
                $dados_venda['vendido_por_usuario'] ?? null
            ];
            
            $id_venda = $this->database->insert($query, $parametros);
            
            // Atualizar classificação do cliente se necessário
            $this->atualizarClassificacaoCliente($dados_venda['id_cliente']);
            
            $this->database->commit();
            
            // Limpar caches relevantes
            $this->cache->delete("evento_detalhes_{$dados_venda['id_evento']}");
            $this->cache->delete("lista_vendas_{$dados_venda['id_lista']}");
            
            $this->logger->info("Venda realizada com sucesso", [
                'id_venda' => $id_venda,
                'codigo_ingresso' => $codigo_ingresso,
                'valor_final' => $valor_final
            ]);
            
            return [
                'sucesso' => true,
                'id_venda' => $id_venda,
                'codigo_ingresso' => $codigo_ingresso,
                'valor_final' => $valor_final,
                'mensagem' => 'Venda realizada com sucesso!'
            ];
            
        } catch (Exception $e) {
            $this->database->rollback();
            
            $this->logger->error("Erro ao realizar venda", [
                'dados' => $dados_venda,
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro ao realizar venda: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Validar entrada no evento (controle de acesso)
     */
    public function validarEntrada(string $cpf, int $id_evento): array
    {
        try {
            $cpf = $this->limparCpf($cpf);
            
            if (!$this->validarCpf($cpf)) {
                return [
                    'sucesso' => false,
                    'erro' => 'CPF inválido'
                ];
            }
            
            // Buscar ingresso válido
            $query = "
                SELECT 
                    ve.*,
                    c.nome_cliente,
                    e.nome_evento,
                    l.nome_lista,
                    tl.nome_tipo_lista
                FROM vendas_eventos ve
                INNER JOIN clientes_eventos c ON ve.id_cliente = c.id_cliente
                INNER JOIN eventos e ON ve.id_evento = e.id_evento
                INNER JOIN listas_eventos l ON ve.id_lista = l.id_lista
                INNER JOIN tipos_listas tl ON l.id_tipo_lista = tl.id_tipo_lista
                WHERE c.cpf_cliente = ? 
                    AND ve.id_evento = ?
                    AND ve.status_pagamento = 'aprovado'
                    AND ve.status_ingresso = 'ativo'
                ORDER BY ve.data_venda DESC
                LIMIT 1
            ";
            
            $ingresso = $this->database->fetchRow($query, [$cpf, $id_evento]);
            
            if (!$ingresso) {
                return [
                    'sucesso' => false,
                    'erro' => 'Ingresso não encontrado ou inválido'
                ];
            }
            
            // Verificar se já deu entrada
            $presenca_existente = $this->database->fetchRow(
                "SELECT * FROM presencas_eventos WHERE id_venda = ?",
                [$ingresso['id_venda']]
            );
            
            if ($presenca_existente) {
                return [
                    'sucesso' => false,
                    'erro' => 'Entrada já registrada',
                    'data_entrada' => $presenca_existente['data_hora_entrada']
                ];
            }
            
            // Registrar entrada
            $this->database->insert(
                "INSERT INTO presencas_eventos (id_venda, id_evento, id_cliente, forma_validacao) VALUES (?, ?, ?, 'cpf')",
                [$ingresso['id_venda'], $id_evento, $ingresso['id_cliente']]
            );
            
            $this->logger->info("Entrada validada", [
                'cpf' => $cpf,
                'evento' => $id_evento,
                'cliente' => $ingresso['nome_cliente']
            ]);
            
            return [
                'sucesso' => true,
                'cliente' => [
                    'nome' => $ingresso['nome_cliente'],
                    'lista' => $ingresso['nome_lista'],
                    'tipo_lista' => $ingresso['nome_tipo_lista'],
                    'codigo_ingresso' => $ingresso['codigo_ingresso']
                ],
                'mensagem' => 'Entrada autorizada!'
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Erro ao validar entrada", [
                'cpf' => $cpf,
                'evento' => $id_evento,
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro interno do sistema'
            ];
        }
    }
    
    /**
     * Obter dashboard em tempo real do evento
     */
    public function obterDashboardEvento(int $id_evento): array
    {
        try {
            $cache_key = "dashboard_evento_{$id_evento}";
            $dashboard = $this->cache->get($cache_key);
            
            if ($dashboard) {
                return [
                    'sucesso' => true,
                    'dashboard' => $dashboard,
                    'origem' => 'cache'
                ];
            }
            
            // Informações gerais do evento
            $evento = $this->database->fetchRow(
                "SELECT * FROM eventos WHERE id_evento = ?",
                [$id_evento]
            );
            
            if (!$evento) {
                return [
                    'sucesso' => false,
                    'erro' => 'Evento não encontrado'
                ];
            }
            
            // Métricas principais
            $metricas = $this->database->fetchRow("
                SELECT 
                    COUNT(DISTINCT ve.id_venda) as total_vendas,
                    COALESCE(SUM(ve.valor_final), 0) as faturamento_total,
                    COUNT(DISTINCT pe.id_presenca) as total_presencas,
                    COUNT(DISTINCT ve.id_cliente) as clientes_unicos,
                    COUNT(DISTINCT l.id_lista) as total_listas,
                    COUNT(DISTINCT ve.id_promoter) as promoters_ativos
                FROM eventos e
                LEFT JOIN vendas_eventos ve ON e.id_evento = ve.id_evento AND ve.status_pagamento = 'aprovado'
                LEFT JOIN presencas_eventos pe ON e.id_evento = pe.id_evento
                LEFT JOIN listas_eventos l ON e.id_evento = l.id_evento AND l.status_lista = 'ativa'
                WHERE e.id_evento = ?
            ", [$id_evento]);
            
            // Vendas por lista
            $vendas_por_lista = $this->database->fetchAll("
                SELECT 
                    l.nome_lista,
                    tl.nome_tipo_lista,
                    l.capacidade_maxima,
                    COUNT(ve.id_venda) as total_vendas,
                    SUM(ve.valor_final) as faturamento_lista,
                    COUNT(pe.id_presenca) as total_presencas
                FROM listas_eventos l
                INNER JOIN tipos_listas tl ON l.id_tipo_lista = tl.id_tipo_lista
                LEFT JOIN vendas_eventos ve ON l.id_lista = ve.id_lista AND ve.status_pagamento = 'aprovado'
                LEFT JOIN presencas_eventos pe ON ve.id_venda = pe.id_venda
                WHERE l.id_evento = ?
                GROUP BY l.id_lista
                ORDER BY faturamento_lista DESC
            ", [$id_evento]);
            
            // Vendas por promoter
            $vendas_por_promoter = $this->database->fetchAll("
                SELECT 
                    p.nome_promoter,
                    COUNT(ve.id_venda) as total_vendas,
                    SUM(ve.valor_final) as faturamento_promoter,
                    SUM(c.valor_comissao) as total_comissoes
                FROM promoters p
                LEFT JOIN vendas_eventos ve ON p.id_promoter = ve.id_promoter 
                    AND ve.id_evento = ? AND ve.status_pagamento = 'aprovado'
                LEFT JOIN comissoes_promoters c ON ve.id_venda = c.id_venda
                WHERE ve.id_promoter IS NOT NULL
                GROUP BY p.id_promoter
                ORDER BY faturamento_promoter DESC
                LIMIT 10
            ", [$id_evento]);
            
            // Vendas por hora (últimas 24h)
            $vendas_por_hora = $this->database->fetchAll("
                SELECT 
                    HOUR(data_venda) as hora,
                    COUNT(*) as vendas,
                    SUM(valor_final) as faturamento
                FROM vendas_eventos 
                WHERE id_evento = ? 
                    AND status_pagamento = 'aprovado'
                    AND data_venda >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY HOUR(data_venda)
                ORDER BY hora
            ", [$id_evento]);
            
            $dashboard = [
                'evento' => $evento,
                'metricas' => $metricas,
                'vendas_por_lista' => $vendas_por_lista,
                'vendas_por_promoter' => $vendas_por_promoter,
                'vendas_por_hora' => $vendas_por_hora,
                'taxa_ocupacao' => ($metricas['total_vendas'] / $evento['capacidade_maxima']) * 100,
                'taxa_presenca' => $metricas['total_vendas'] > 0 ? ($metricas['total_presencas'] / $metricas['total_vendas']) * 100 : 0,
                'atualizado_em' => date('Y-m-d H:i:s')
            ];
            
            // Cache por 1 minuto (tempo real)
            $this->cache->set($cache_key, $dashboard, 60);
            
            return [
                'sucesso' => true,
                'dashboard' => $dashboard
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Erro ao obter dashboard do evento", [
                'evento' => $id_evento,
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro ao carregar dashboard'
            ];
        }
    }
    
    /**
     * Criar listas padrão para evento
     */
    private function criarListasPadrao(int $id_evento): void
    {
        $listas_padrao = [
            ['tipo' => 1, 'nome' => 'Lista Geral', 'capacidade' => 500],
            ['tipo' => 4, 'nome' => 'Lista Free', 'capacidade' => 100],
            ['tipo' => 7, 'nome' => 'Lista VIP', 'capacidade' => 50]
        ];
        
        foreach ($listas_padrao as $lista) {
            $this->database->insert(
                "INSERT INTO listas_eventos (id_evento, id_tipo_lista, nome_lista, capacidade_maxima) VALUES (?, ?, ?, ?)",
                [$id_evento, $lista['tipo'], $lista['nome'], $lista['capacidade']]
            );
        }
    }
    
    /**
     * Validar dados do evento
     */
    private function validarDadosEvento(array $dados): void
    {
        if (empty($dados['nome_evento'])) {
            throw new Exception('Nome do evento é obrigatório');
        }
        
        if (empty($dados['data_evento'])) {
            throw new Exception('Data do evento é obrigatória');
        }
        
        if (empty($dados['hora_inicio'])) {
            throw new Exception('Hora de início é obrigatória');
        }
        
        if (empty($dados['local_evento'])) {
            throw new Exception('Local do evento é obrigatório');
        }
        
        // Validar se data não é no passado
        $data_evento = new DateTime($dados['data_evento']);
        $hoje = new DateTime();
        
        if ($data_evento < $hoje) {
            throw new Exception('Data do evento não pode ser no passado');
        }
    }
    
    /**
     * Validar dados da venda
     */
    private function validarDadosVenda(array $dados): void
    {
        if (empty($dados['id_evento'])) {
            throw new Exception('ID do evento é obrigatório');
        }
        
        if (empty($dados['id_lista'])) {
            throw new Exception('ID da lista é obrigatório');
        }
        
        if (empty($dados['id_cliente'])) {
            throw new Exception('ID do cliente é obrigatório');
        }
    }
    
    /**
     * Gerar código único de ingresso
     */
    private function gerarCodigoIngresso(int $id_evento): string
    {
        $prefixo = 'EVT' . str_pad((string)$id_evento, 4, '0', STR_PAD_LEFT);
        $data = date('md');
        $aleatorio = str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        
        return $prefixo . $data . $aleatorio;
    }
    
    /**
     * Limpar CPF (remover formatação)
     */
    private function limparCpf(string $cpf): string
    {
        return preg_replace('/[^0-9]/', '', $cpf);
    }
    
    /**
     * Validar CPF
     */
    private function validarCpf(string $cpf): bool
    {
        if (strlen($cpf) !== 11) {
            return false;
        }
        
        // Verificar se todos os dígitos são iguais
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        
        // Calcular primeiro dígito verificador
        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += intval($cpf[$i]) * (10 - $i);
        }
        $digito1 = ($soma * 10) % 11;
        if ($digito1 >= 10) $digito1 = 0;
        
        // Calcular segundo dígito verificador
        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += intval($cpf[$i]) * (11 - $i);
        }
        $digito2 = ($soma * 10) % 11;
        if ($digito2 >= 10) $digito2 = 0;
        
        // Verificar dígitos
        return ($cpf[9] == $digito1 && $cpf[10] == $digito2);
    }
    
    /**
     * Buscar histórico de eventos do cliente
     */
    private function buscarHistoricoEventosCliente(int $id_cliente): array
    {
        return $this->database->fetchAll("
            SELECT 
                e.nome_evento,
                e.data_evento,
                ve.valor_final,
                ve.data_venda,
                CASE WHEN pe.id_presenca IS NOT NULL THEN 'Presente' ELSE 'Ausente' END as status_presenca
            FROM vendas_eventos ve
            INNER JOIN eventos e ON ve.id_evento = e.id_evento
            LEFT JOIN presencas_eventos pe ON ve.id_venda = pe.id_venda
            WHERE ve.id_cliente = ? AND ve.status_pagamento = 'aprovado'
            ORDER BY e.data_evento DESC
            LIMIT 10
        ", [$id_cliente]);
    }
    
    /**
     * Atualizar classificação do cliente baseada no histórico
     */
    private function atualizarClassificacaoCliente(int $id_cliente): void
    {
        $total_gasto = $this->database->fetchColumn(
            "SELECT COALESCE(SUM(valor_final), 0) FROM vendas_eventos WHERE id_cliente = ? AND status_pagamento = 'aprovado'",
            [$id_cliente]
        );
        
        $classificacao = 'novo';
        
        if ($total_gasto >= 5000) {
            $classificacao = 'diamante';
        } elseif ($total_gasto >= 2000) {
            $classificacao = 'ouro';
        } elseif ($total_gasto >= 1000) {
            $classificacao = 'prata';
        } elseif ($total_gasto >= 500) {
            $classificacao = 'bronze';
        }
        
        $this->database->execute(
            "UPDATE clientes_eventos SET classificacao_cliente = ?, total_gasto_historico = ? WHERE id_cliente = ?",
            [$classificacao, $total_gasto, $id_cliente]
        );
    }
}