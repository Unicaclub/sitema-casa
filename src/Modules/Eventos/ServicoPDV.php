<?php

declare(strict_types=1);

namespace ERP\Modules\Eventos;

use ERP\Core\Database\DatabaseManager;
use ERP\Core\Logger;
use ERP\Core\Cache\CacheManager;
use Exception;

/**
 * Serviço PDV Integrado com Autenticação por CPF
 * 
 * Sistema de Ponto de Venda para eventos com:
 * - Autenticação segura pelos 3 primeiros dígitos do CPF
 * - Cartões físicos e digitais
 * - Gestão de produtos e estoque
 * - Controle de transações em tempo real
 * - Integração total com sistema de eventos
 * 
 * @package ERP\Modules\Eventos
 */
final class ServicoPDV
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
     * Autenticar cliente para PDV usando 3 primeiros dígitos do CPF
     */
    public function autenticarClientePDV(string $cpf_completo, string $tres_primeiros_digitos, int $id_evento): array
    {
        try {
            $cpf = $this->limparCpf($cpf_completo);
            
            // Validar CPF completo
            if (! $this->validarCpf($cpf)) {
                return [
                    'sucesso' => false,
                    'erro' => 'CPF inválido'
                ];
            }
            
            // Verificar se os 3 primeiros dígitos conferem
            if (substr($cpf, 0, 3) !== $tres_primeiros_digitos) {
                $this->logger->warning("Tentativa de autenticação PDV com CPF incorreto", [
                    'cpf_tentativa' => $cpf,
                    'tres_digitos' => $tres_primeiros_digitos,
                    'evento' => $id_evento
                ]);
                
                return [
                    'sucesso' => false,
                    'erro' => 'Autenticação inválida - Dígitos do CPF não conferem'
                ];
            }
            
            // Buscar cliente e verificar se tem acesso ao evento
            $query = "
                SELECT 
                    c.*,
                    ve.id_venda,
                    ve.codigo_ingresso,
                    l.nome_lista,
                    tl.nome_tipo_lista,
                    CASE WHEN pe.id_presenca IS NOT NULL THEN TRUE ELSE FALSE END as presente_evento
                FROM clientes_eventos c
                INNER JOIN vendas_eventos ve ON c.id_cliente = ve.id_cliente
                INNER JOIN listas_eventos l ON ve.id_lista = l.id_lista
                INNER JOIN tipos_listas tl ON l.id_tipo_lista = tl.id_tipo_lista
                LEFT JOIN presencas_eventos pe ON ve.id_venda = pe.id_venda
                WHERE c.cpf_cliente = ? 
                    AND ve.id_evento = ?
                    AND ve.status_pagamento = 'aprovado'
                    AND ve.status_ingresso IN ('ativo', 'utilizado')
                ORDER BY ve.data_venda DESC
                LIMIT 1
            ";
            
            $cliente = $this->database->fetchRow($query, [$cpf, $id_evento]);
            
            if (! $cliente) {
                return [
                    'sucesso' => false,
                    'erro' => 'Cliente não tem acesso a este evento ou ingresso inválido'
                ];
            }
            
            // Buscar ou criar cartão do cliente
            $cartao = $this->obterOuCriarCartaoCliente($cliente['id_cliente']);
            
            // Log da autenticação
            $this->logger->info("Autenticação PDV realizada", [
                'cliente_id' => $cliente['id_cliente'],
                'cliente_nome' => $cliente['nome_cliente'],
                'evento_id' => $id_evento,
                'presente_evento' => $cliente['presente_evento']
            ]);
            
            return [
                'sucesso' => true,
                'cliente' => [
                    'id_cliente' => $cliente['id_cliente'],
                    'nome_cliente' => $cliente['nome_cliente'],
                    'classificacao_cliente' => $cliente['classificacao_cliente'],
                    'lista' => $cliente['nome_lista'],
                    'tipo_lista' => $cliente['nome_tipo_lista'],
                    'presente_evento' => $cliente['presente_evento']
                ],
                'cartao' => $cartao,
                'mensagem' => 'Autenticação realizada com sucesso!'
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Erro na autenticação PDV", [
                'cpf' => $cpf_completo,
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
     * Processar venda/consumo no PDV
     */
    public function processarVendaPDV(array $dados_venda): array
    {
        try {
            $this->database->beginTransaction();
            
            // Validar dados da venda
            $this->validarDadosVendaPDV($dados_venda);
            
            // Verificar autenticação do cliente
            $autenticacao = $this->autenticarClientePDV(
                $dados_venda['cpf_cliente'],
                $dados_venda['tres_primeiros_cpf'],
                $dados_venda['id_evento']
            );
            
            if (! $autenticacao['sucesso']) {
                throw new Exception($autenticacao['erro']);
            }
            
            $cliente = $autenticacao['cliente'];
            $cartao = $autenticacao['cartao'];
            
            // Calcular valor total da venda
            $valor_total = 0;
            $itens_validos = [];
            
            foreach ($dados_venda['itens'] as $item) {
                $produto = $this->database->fetchRow(
                    "SELECT * FROM produtos_pdv WHERE id_produto = ? AND ativo = TRUE",
                    [$item['id_produto']]
                );
                
                if (! $produto) {
                    throw new Exception("Produto ID {$item['id_produto']} não encontrado");
                }
                
                // Verificar estoque
                if ($produto['estoque_atual'] < $item['quantidade']) {
                    throw new Exception("Estoque insuficiente para {$produto['nome_produto']}");
                }
                
                $subtotal = $produto['preco_produto'] * $item['quantidade'];
                $valor_total += $subtotal;
                
                $itens_validos[] = [
                    'id_produto' => $produto['id_produto'],
                    'nome_produto' => $produto['nome_produto'],
                    'quantidade' => $item['quantidade'],
                    'preco_unitario' => $produto['preco_produto'],
                    'subtotal' => $subtotal
                ];
            }
            
            // Verificar forma de pagamento e saldo (se necessário)
            if ($dados_venda['forma_pagamento'] === 'cartao_fisico' || $dados_venda['forma_pagamento'] === 'cartao_digital') {
                $saldo_disponivel = $cartao['saldo_atual'] + $cartao['limite_credito'];
                
                if ($saldo_disponivel < $valor_total) {
                    throw new Exception("Saldo insuficiente. Disponível: R$ " . number_format($saldo_disponivel, 2, ',', '.'));
                }
            }
            
            // Criar transação principal
            $query_transacao = "
                INSERT INTO pdv_transacoes (
                    id_evento, id_cliente, cpf_cliente, tres_primeiros_cpf,
                    tipo_transacao, descricao_transacao, valor_transacao,
                    forma_pagamento, status_transacao, operador_pdv,
                    terminal_pdv, numero_comanda, observacoes_pdv
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $parametros_transacao = [
                $dados_venda['id_evento'],
                $cliente['id_cliente'],
                $dados_venda['cpf_cliente'],
                $dados_venda['tres_primeiros_cpf'],
                $dados_venda['tipo_transacao'] ?? 'consumo',
                $dados_venda['descricao_transacao'] ?? 'Consumo no evento',
                $valor_total,
                $dados_venda['forma_pagamento'],
                'aprovada',
                $dados_venda['operador_pdv'] ?? null,
                $dados_venda['terminal_pdv'] ?? null,
                $dados_venda['numero_comanda'] ?? null,
                $dados_venda['observacoes_pdv'] ?? null
            ];
            
            $id_transacao = $this->database->insert($query_transacao, $parametros_transacao);
            
            // Inserir itens da transação e atualizar estoque
            foreach ($itens_validos as $item) {
                // Inserir item da transação
                $this->database->insert(
                    "INSERT INTO pdv_itens_transacao (id_transacao, id_produto, quantidade, preco_unitario, subtotal) VALUES (?, ?, ?, ?, ?)",
                    [$id_transacao, $item['id_produto'], $item['quantidade'], $item['preco_unitario'], $item['subtotal']]
                );
                
                // Atualizar estoque
                $this->database->execute(
                    "UPDATE produtos_pdv SET estoque_atual = estoque_atual - ? WHERE id_produto = ?",
                    [$item['quantidade'], $item['id_produto']]
                );
            }
            
            // Atualizar saldo do cartão (se forma de pagamento for cartão)
            if ($dados_venda['forma_pagamento'] === 'cartao_fisico' || $dados_venda['forma_pagamento'] === 'cartao_digital') {
                $this->database->execute(
                    "UPDATE cartoes_clientes SET saldo_atual = saldo_atual - ?, data_ultima_utilizacao = NOW(), total_transacoes = total_transacoes + 1 WHERE id_cliente = ?",
                    [$valor_total, $cliente['id_cliente']]
                );
            }
            
            $this->database->commit();
            
            // Limpar caches relevantes
            $this->cache->delete("cliente_cartao_{$cliente['id_cliente']}");
            $this->cache->delete("produtos_pdv_ativos");
            
            $this->logger->info("Venda PDV processada com sucesso", [
                'id_transacao' => $id_transacao,
                'cliente' => $cliente['nome_cliente'],
                'valor_total' => $valor_total,
                'itens_count' => count($itens_validos)
            ]);
            
            return [
                'sucesso' => true,
                'id_transacao' => $id_transacao,
                'valor_total' => $valor_total,
                'itens' => $itens_validos,
                'cliente' => $cliente,
                'mensagem' => 'Venda processada com sucesso!'
            ];
            
        } catch (Exception $e) {
            $this->database->rollback();
            
            $this->logger->error("Erro ao processar venda PDV", [
                'dados' => $dados_venda,
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro ao processar venda: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Recarregar saldo do cartão
     */
    public function recarregarCartao(string $cpf, int $id_evento, float $valor_recarga, string $forma_pagamento): array
    {
        try {
            $this->database->beginTransaction();
            
            $cpf = $this->limparCpf($cpf);
            
            // Buscar cliente
            $cliente = $this->database->fetchRow(
                "SELECT id_cliente, nome_cliente FROM clientes_eventos WHERE cpf_cliente = ? AND ativo = TRUE",
                [$cpf]
            );
            
            if (! $cliente) {
                throw new Exception('Cliente não encontrado');
            }
            
            // Buscar cartão
            $cartao = $this->database->fetchRow(
                "SELECT * FROM cartoes_clientes WHERE id_cliente = ? AND status_cartao = 'ativo'",
                [$cliente['id_cliente']]
            );
            
            if (! $cartao) {
                throw new Exception('Cartão não encontrado');
            }
            
            // Registrar transação de recarga
            $id_transacao = $this->database->insert(
                "INSERT INTO pdv_transacoes (id_evento, id_cliente, cpf_cliente, tres_primeiros_cpf, tipo_transacao, descricao_transacao, valor_transacao, forma_pagamento, status_transacao) VALUES (?, ?, ?, ?, 'recarga', 'Recarga de cartão', ?, ?, 'aprovada')",
                [$id_evento, $cliente['id_cliente'], $cpf, substr($cpf, 0, 3), $valor_recarga, $forma_pagamento]
            );
            
            // Atualizar saldo do cartão
            $this->database->execute(
                "UPDATE cartoes_clientes SET saldo_atual = saldo_atual + ?, data_ultima_utilizacao = NOW(), total_transacoes = total_transacoes + 1 WHERE id_cliente = ?",
                [$valor_recarga, $cliente['id_cliente']]
            );
            
            // Buscar novo saldo
            $novo_saldo = $this->database->fetchColumn(
                "SELECT saldo_atual FROM cartoes_clientes WHERE id_cliente = ?",
                [$cliente['id_cliente']]
            );
            
            $this->database->commit();
            
            // Limpar cache
            $this->cache->delete("cliente_cartao_{$cliente['id_cliente']}");
            
            $this->logger->info("Recarga de cartão realizada", [
                'cliente' => $cliente['nome_cliente'],
                'valor_recarga' => $valor_recarga,
                'novo_saldo' => $novo_saldo
            ]);
            
            return [
                'sucesso' => true,
                'id_transacao' => $id_transacao,
                'valor_recarga' => $valor_recarga,
                'saldo_anterior' => $novo_saldo - $valor_recarga,
                'saldo_atual' => $novo_saldo,
                'mensagem' => 'Recarga realizada com sucesso!'
            ];
            
        } catch (Exception $e) {
            $this->database->rollback();
            
            $this->logger->error("Erro ao recarregar cartão", [
                'cpf' => $cpf,
                'valor' => $valor_recarga,
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro ao recarregar cartão: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Consultar saldo do cartão
     */
    public function consultarSaldoCartao(string $cpf): array
    {
        try {
            $cpf = $this->limparCpf($cpf);
            
            $query = "
                SELECT 
                    c.nome_cliente,
                    c.classificacao_cliente,
                    cart.codigo_cartao,
                    cart.saldo_atual,
                    cart.limite_credito,
                    cart.status_cartao,
                    cart.data_ultima_utilizacao,
                    cart.total_transacoes,
                    (cart.saldo_atual + cart.limite_credito) as saldo_disponivel
                FROM clientes_eventos c
                INNER JOIN cartoes_clientes cart ON c.id_cliente = cart.id_cliente
                WHERE c.cpf_cliente = ? AND c.ativo = TRUE
                ORDER BY cart.data_criacao DESC
                LIMIT 1
            ";
            
            $cartao = $this->database->fetchRow($query, [$cpf]);
            
            if (! $cartao) {
                return [
                    'sucesso' => false,
                    'erro' => 'Cliente ou cartão não encontrado'
                ];
            }
            
            return [
                'sucesso' => true,
                'cartao' => $cartao
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Erro ao consultar saldo", [
                'cpf' => $cpf,
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro ao consultar saldo'
            ];
        }
    }
    
    /**
     * Listar produtos ativos para PDV
     */
    public function listarProdutosPDV(): array
    {
        try {
            $cache_key = "produtos_pdv_ativos";
            $produtos = $this->cache->get($cache_key);
            
            if ($produtos) {
                return [
                    'sucesso' => true,
                    'produtos' => $produtos,
                    'origem' => 'cache'
                ];
            }
            
            $produtos = $this->database->fetchAll("
                SELECT 
                    id_produto,
                    nome_produto,
                    descricao_produto,
                    categoria_produto,
                    preco_produto,
                    estoque_atual,
                    estoque_minimo,
                    codigo_barras,
                    CASE WHEN estoque_atual <= estoque_minimo THEN TRUE ELSE FALSE END as estoque_baixo
                FROM produtos_pdv 
                WHERE ativo = TRUE 
                ORDER BY categoria_produto, nome_produto
            ");
            
            // Agrupar por categoria
            $produtos_agrupados = [];
            foreach ($produtos as $produto) {
                $categoria = $produto['categoria_produto'];
                if (! isset($produtos_agrupados[$categoria])) {
                    $produtos_agrupados[$categoria] = [];
                }
                $produtos_agrupados[$categoria][] = $produto;
            }
            
            // Cache por 5 minutos
            $this->cache->set($cache_key, $produtos_agrupados, 300);
            
            return [
                'sucesso' => true,
                'produtos' => $produtos_agrupados
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Erro ao listar produtos PDV", [
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro ao carregar produtos'
            ];
        }
    }
    
    /**
     * Relatório de vendas PDV do evento
     */
    public function relatorioVendasPDV(int $id_evento, ?string $data_inicio = null, ?string $data_fim = null): array
    {
        try {
            $where_data = "";
            $parametros = [$id_evento];
            
            if ($data_inicio && $data_fim) {
                $where_data = "AND DATE(t.data_hora_transacao) BETWEEN ? AND ?";
                $parametros[] = $data_inicio;
                $parametros[] = $data_fim;
            }
            
            // Resumo geral
            $resumo = $this->database->fetchRow("
                SELECT 
                    COUNT(*) as total_transacoes,
                    SUM(valor_transacao) as faturamento_total,
                    COUNT(DISTINCT id_cliente) as clientes_unicos,
                    AVG(valor_transacao) as ticket_medio,
                    COUNT(CASE WHEN tipo_transacao = 'consumo' THEN 1 END) as vendas_consumo,
                    COUNT(CASE WHEN tipo_transacao = 'recarga' THEN 1 END) as recargas
                FROM pdv_transacoes 
                WHERE id_evento = ? AND status_transacao = 'aprovada' {$where_data}
            ", $parametros);
            
            // Vendas por forma de pagamento
            $vendas_por_pagamento = $this->database->fetchAll("
                SELECT 
                    forma_pagamento,
                    COUNT(*) as quantidade,
                    SUM(valor_transacao) as valor_total
                FROM pdv_transacoes 
                WHERE id_evento = ? AND status_transacao = 'aprovada' AND tipo_transacao = 'consumo' {$where_data}
                GROUP BY forma_pagamento
                ORDER BY valor_total DESC
            ", $parametros);
            
            // Produtos mais vendidos
            $produtos_vendidos = $this->database->fetchAll("
                SELECT 
                    p.nome_produto,
                    p.categoria_produto,
                    SUM(it.quantidade) as quantidade_vendida,
                    SUM(it.subtotal) as faturamento_produto,
                    AVG(it.preco_unitario) as preco_medio
                FROM pdv_transacoes t
                INNER JOIN pdv_itens_transacao it ON t.id_transacao = it.id_transacao
                INNER JOIN produtos_pdv p ON it.id_produto = p.id_produto
                WHERE t.id_evento = ? AND t.status_transacao = 'aprovada' AND t.tipo_transacao = 'consumo' {$where_data}
                GROUP BY p.id_produto
                ORDER BY quantidade_vendida DESC
                LIMIT 20
            ", $parametros);
            
            // Vendas por hora
            $vendas_por_hora = $this->database->fetchAll("
                SELECT 
                    HOUR(data_hora_transacao) as hora,
                    COUNT(*) as transacoes,
                    SUM(valor_transacao) as faturamento
                FROM pdv_transacoes 
                WHERE id_evento = ? AND status_transacao = 'aprovada' AND tipo_transacao = 'consumo' {$where_data}
                GROUP BY HOUR(data_hora_transacao)
                ORDER BY hora
            ", $parametros);
            
            return [
                'sucesso' => true,
                'relatorio' => [
                    'resumo' => $resumo,
                    'vendas_por_pagamento' => $vendas_por_pagamento,
                    'produtos_vendidos' => $produtos_vendidos,
                    'vendas_por_hora' => $vendas_por_hora,
                    'periodo' => [
                        'data_inicio' => $data_inicio ?? 'Início do evento',
                        'data_fim' => $data_fim ?? 'Fim do evento'
                    ]
                ]
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Erro ao gerar relatório PDV", [
                'evento' => $id_evento,
                'erro' => $e->getMessage()
            ]);
            
            return [
                'sucesso' => false,
                'erro' => 'Erro ao gerar relatório'
            ];
        }
    }
    
    /**
     * Obter ou criar cartão do cliente
     */
    private function obterOuCriarCartaoCliente(int $id_cliente): array
    {
        $cache_key = "cliente_cartao_{$id_cliente}";
        $cartao = $this->cache->get($cache_key);
        
        if ($cartao) {
            return $cartao;
        }
        
        // Buscar cartão existente
        $cartao = $this->database->fetchRow(
            "SELECT * FROM cartoes_clientes WHERE id_cliente = ? AND status_cartao = 'ativo' ORDER BY data_criacao DESC LIMIT 1",
            [$id_cliente]
        );
        
        if (!$cartao) {
            // Criar novo cartão
            $codigo_cartao = $this->gerarCodigoCartao();
            
            $id_cartao = $this->database->insert(
                "INSERT INTO cartoes_clientes (id_cliente, codigo_cartao, tipo_cartao, saldo_atual, limite_credito) VALUES (?, ?, 'digital', 0.00, 100.00)",
                [$id_cliente, $codigo_cartao]
            );
            
            $cartao = $this->database->fetchRow(
                "SELECT * FROM cartoes_clientes WHERE id_cartao = ?",
                [$id_cartao]
            );
        }
        
        // Cache por 10 minutos
        $this->cache->set($cache_key, $cartao, 600);
        
        return $cartao;
    }
    
    /**
     * Gerar código único de cartão
     */
    private function gerarCodigoCartao(): string
    {
        $prefixo = 'CARD';
        $timestamp = date('YmdHis');
        $aleatorio = str_pad((string)random_int(1, 999), 3, '0', STR_PAD_LEFT);
        
        return $prefixo . $timestamp . $aleatorio;
    }
    
    /**
     * Validar dados da venda PDV
     */
    private function validarDadosVendaPDV(array $dados): void
    {
        if (empty($dados['cpf_cliente'])) {
            throw new Exception('CPF do cliente é obrigatório');
        }
        
        if (empty($dados['tres_primeiros_cpf'])) {
            throw new Exception('3 primeiros dígitos do CPF são obrigatórios');
        }
        
        if (empty($dados['id_evento'])) {
            throw new Exception('ID do evento é obrigatório');
        }
        
        if (empty($dados['itens']) || !is_array($dados['itens'])) {
            throw new Exception('Itens da venda são obrigatórios');
        }
        
        if (empty($dados['forma_pagamento'])) {
            throw new Exception('Forma de pagamento é obrigatória');
        }
        
        foreach ($dados['itens'] as $item) {
            if (empty($item['id_produto'])) {
                throw new Exception('ID do produto é obrigatório');
            }
            
            if (empty($item['quantidade']) || $item['quantidade'] <= 0) {
                throw new Exception('Quantidade deve ser maior que zero');
            }
        }
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
}
