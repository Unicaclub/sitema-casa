<?php

declare(strict_types=1);

namespace ERP\Api\Controllers;

use ERP\Api\Controllers\BaseController;
use ERP\Modules\Eventos\ServicoEventos;
use ERP\Modules\Eventos\ServicoPDV;
use ERP\Core\Http\Request;
use ERP\Core\Http\Response;

/**
 * Controller de Eventos VIP
 * 
 * API completa para sistema de eventos com:
 * - Gestão de eventos e listas
 * - Vendas antecipadas por CPF  
 * - Controle de acesso e presenças
 * - PDV integrado com autenticação
 * - Dashboards em tempo real
 * 
 * @package ERP\Api\Controllers
 */
final class EventosController extends BaseController
{
    private ServicoEventos $servicoEventos;
    private ServicoPDV $servicoPDV;
    
    public function __construct(ServicoEventos $servicoEventos, ServicoPDV $servicoPDV)
    {
        parent::__construct();
        $this->servicoEventos = $servicoEventos;
        $this->servicoPDV = $servicoPDV;
    }
    
    /**
     * Criar novo evento
     * POST /api/eventos
     */
    public function criarEvento(Request $request): Response
    {
        try {
            $dados = $request->getJsonData();
            
            $resultado = $this->servicoEventos->criarEvento($dados);
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado, 201);
            } else {
                return $this->jsonResponse($resultado, 400);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao criar evento', 500);
        }
    }
    
    /**
     * Buscar cliente por CPF
     * GET /api/eventos/cliente/{cpf}
     */
    public function buscarClientePorCpf(Request $request): Response
    {
        try {
            $cpf = $request->getPathParam('cpf');
            
            if (empty($cpf)) {
                return $this->errorResponse('CPF é obrigatório', 400);
            }
            
            $resultado = $this->servicoEventos->buscarClientePorCpf($cpf);
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado);
            } else {
                return $this->jsonResponse($resultado, 404);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao buscar cliente', 500);
        }
    }
    
    /**
     * Cadastrar cliente
     * POST /api/eventos/cliente
     */
    public function cadastrarCliente(Request $request): Response
    {
        try {
            $dados = $request->getJsonData();
            
            $resultado = $this->servicoEventos->cadastrarCliente($dados);
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado, 201);
            } else {
                return $this->jsonResponse($resultado, 400);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao cadastrar cliente', 500);
        }
    }
    
    /**
     * Realizar venda de ingresso
     * POST /api/eventos/venda
     */
    public function realizarVenda(Request $request): Response
    {
        try {
            $dados = $request->getJsonData();
            
            $resultado = $this->servicoEventos->realizarVenda($dados);
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado, 201);
            } else {
                return $this->jsonResponse($resultado, 400);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao realizar venda', 500);
        }
    }
    
    /**
     * Validar entrada no evento
     * POST /api/eventos/{id_evento}/entrada
     */
    public function validarEntrada(Request $request): Response
    {
        try {
            $id_evento = (int) $request->getPathParam('id_evento');
            $dados = $request->getJsonData();
            
            if (empty($dados['cpf'])) {
                return $this->errorResponse('CPF é obrigatório', 400);
            }
            
            $resultado = $this->servicoEventos->validarEntrada($dados['cpf'], $id_evento);
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado);
            } else {
                return $this->jsonResponse($resultado, 400);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao validar entrada', 500);
        }
    }
    
    /**
     * Dashboard do evento em tempo real
     * GET /api/eventos/{id_evento}/dashboard
     */
    public function dashboardEvento(Request $request): Response
    {
        try {
            $id_evento = (int) $request->getPathParam('id_evento');
            
            $resultado = $this->servicoEventos->obterDashboardEvento($id_evento);
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado);
            } else {
                return $this->jsonResponse($resultado, 404);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao carregar dashboard', 500);
        }
    }
    
    // ===== ENDPOINTS PDV =====
    
    /**
     * Autenticar cliente no PDV
     * POST /api/eventos/{id_evento}/pdv/autenticar
     */
    public function autenticarPDV(Request $request): Response
    {
        try {
            $id_evento = (int) $request->getPathParam('id_evento');
            $dados = $request->getJsonData();
            
            if (empty($dados['cpf_completo']) || empty($dados['tres_primeiros_digitos'])) {
                return $this->errorResponse('CPF completo e 3 primeiros dígitos são obrigatórios', 400);
            }
            
            $resultado = $this->servicoPDV->autenticarClientePDV(
                $dados['cpf_completo'],
                $dados['tres_primeiros_digitos'],
                $id_evento
            );
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado);
            } else {
                return $this->jsonResponse($resultado, 401);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro na autenticação PDV', 500);
        }
    }
    
    /**
     * Processar venda no PDV
     * POST /api/eventos/{id_evento}/pdv/venda
     */
    public function processarVendaPDV(Request $request): Response
    {
        try {
            $id_evento = (int) $request->getPathParam('id_evento');
            $dados = $request->getJsonData();
            $dados['id_evento'] = $id_evento;
            
            $resultado = $this->servicoPDV->processarVendaPDV($dados);
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado, 201);
            } else {
                return $this->jsonResponse($resultado, 400);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao processar venda PDV', 500);
        }
    }
    
    /**
     * Recarregar cartão
     * POST /api/eventos/{id_evento}/pdv/recarga
     */
    public function recarregarCartao(Request $request): Response
    {
        try {
            $id_evento = (int) $request->getPathParam('id_evento');
            $dados = $request->getJsonData();
            
            if (empty($dados['cpf']) || empty($dados['valor_recarga']) || empty($dados['forma_pagamento'])) {
                return $this->errorResponse('CPF, valor da recarga e forma de pagamento são obrigatórios', 400);
            }
            
            $resultado = $this->servicoPDV->recarregarCartao(
                $dados['cpf'],
                $id_evento,
                (float) $dados['valor_recarga'],
                $dados['forma_pagamento']
            );
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado, 201);
            } else {
                return $this->jsonResponse($resultado, 400);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao recarregar cartão', 500);
        }
    }
    
    /**
     * Consultar saldo do cartão
     * GET /api/eventos/pdv/saldo/{cpf}
     */
    public function consultarSaldoCartao(Request $request): Response
    {
        try {
            $cpf = $request->getPathParam('cpf');
            
            if (empty($cpf)) {
                return $this->errorResponse('CPF é obrigatório', 400);
            }
            
            $resultado = $this->servicoPDV->consultarSaldoCartao($cpf);
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado);
            } else {
                return $this->jsonResponse($resultado, 404);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao consultar saldo', 500);
        }
    }
    
    /**
     * Listar produtos do PDV
     * GET /api/eventos/pdv/produtos
     */
    public function listarProdutosPDV(Request $request): Response
    {
        try {
            $resultado = $this->servicoPDV->listarProdutosPDV();
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado);
            } else {
                return $this->jsonResponse($resultado, 500);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao listar produtos', 500);
        }
    }
    
    /**
     * Relatório de vendas PDV
     * GET /api/eventos/{id_evento}/pdv/relatorio
     */
    public function relatorioVendasPDV(Request $request): Response
    {
        try {
            $id_evento = (int) $request->getPathParam('id_evento');
            $data_inicio = $request->getQueryParam('data_inicio');
            $data_fim = $request->getQueryParam('data_fim');
            
            $resultado = $this->servicoPDV->relatorioVendasPDV($id_evento, $data_inicio, $data_fim);
            
            if ($resultado['sucesso']) {
                return $this->jsonResponse($resultado);
            } else {
                return $this->jsonResponse($resultado, 500);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao gerar relatório', 500);
        }
    }
    
    // ===== ENDPOINTS ADMINISTRATIVOS =====
    
    /**
     * Listar eventos
     * GET /api/eventos
     */
    public function listarEventos(Request $request): Response
    {
        try {
            $status = $request->getQueryParam('status');
            $limite = (int) ($request->getQueryParam('limite') ?? 50);
            $pagina = (int) ($request->getQueryParam('pagina') ?? 1);
            $offset = ($pagina - 1) * $limite;
            
            $where = "WHERE ativo = TRUE";
            $parametros = [];
            
            if ($status) {
                $where .= " AND status_evento = ?";
                $parametros[] = $status;
            }
            
            $query = "
                SELECT 
                    e.*,
                    COUNT(DISTINCT ve.id_venda) as total_vendas,
                    COUNT(DISTINCT pe.id_presenca) as total_presencas,
                    COALESCE(SUM(ve.valor_final), 0) as faturamento
                FROM eventos e
                LEFT JOIN vendas_eventos ve ON e.id_evento = ve.id_evento AND ve.status_pagamento = 'aprovado'
                LEFT JOIN presencas_eventos pe ON e.id_evento = pe.id_evento
                {$where}
                GROUP BY e.id_evento
                ORDER BY e.data_evento DESC
                LIMIT ? OFFSET ?
            ";
            
            $parametros[] = $limite;
            $parametros[] = $offset;
            
            $eventos = $this->database->fetchAll($query, $parametros);
            
            // Contar total para paginação
            $total = $this->database->fetchColumn(
                "SELECT COUNT(*) FROM eventos {$where}",
                array_slice($parametros, 0, -2)
            );
            
            return $this->jsonResponse([
                'sucesso' => true,
                'eventos' => $eventos,
                'paginacao' => [
                    'pagina_atual' => $pagina,
                    'total_registros' => $total,
                    'total_paginas' => ceil($total / $limite),
                    'registros_por_pagina' => $limite
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao listar eventos', 500);
        }
    }
    
    /**
     * Detalhes do evento
     * GET /api/eventos/{id_evento}
     */
    public function detalhesEvento(Request $request): Response
    {
        try {
            $id_evento = (int) $request->getPathParam('id_evento');
            
            $evento = $this->database->fetchRow(
                "SELECT * FROM eventos WHERE id_evento = ? AND ativo = TRUE",
                [$id_evento]
            );
            
            if (!$evento) {
                return $this->errorResponse('Evento não encontrado', 404);
            }
            
            // Buscar listas do evento
            $listas = $this->database->fetchAll("
                SELECT 
                    l.*,
                    tl.nome_tipo_lista,
                    p.nome_promoter,
                    COUNT(ve.id_venda) as total_vendas,
                    SUM(ve.valor_final) as faturamento_lista
                FROM listas_eventos l
                INNER JOIN tipos_listas tl ON l.id_tipo_lista = tl.id_tipo_lista
                LEFT JOIN promoters p ON l.id_promoter = p.id_promoter
                LEFT JOIN vendas_eventos ve ON l.id_lista = ve.id_lista AND ve.status_pagamento = 'aprovado'
                WHERE l.id_evento = ?
                GROUP BY l.id_lista
                ORDER BY l.data_criacao
            ", [$id_evento]);
            
            return $this->jsonResponse([
                'sucesso' => true,
                'evento' => $evento,
                'listas' => $listas
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao buscar detalhes do evento', 500);
        }
    }
    
    /**
     * Atualizar status do evento
     * PATCH /api/eventos/{id_evento}/status
     */
    public function atualizarStatusEvento(Request $request): Response
    {
        try {
            $id_evento = (int) $request->getPathParam('id_evento');
            $dados = $request->getJsonData();
            
            if (empty($dados['status_evento'])) {
                return $this->errorResponse('Status do evento é obrigatório', 400);
            }
            
            $status_validos = ['planejamento', 'vendas_abertas', 'vendas_encerradas', 'em_andamento', 'finalizado', 'cancelado'];
            
            if (!in_array($dados['status_evento'], $status_validos)) {
                return $this->errorResponse('Status inválido', 400);
            }
            
            $affected = $this->database->execute(
                "UPDATE eventos SET status_evento = ?, data_atualizacao = NOW() WHERE id_evento = ? AND ativo = TRUE",
                [$dados['status_evento'], $id_evento]
            );
            
            if ($affected === 0) {
                return $this->errorResponse('Evento não encontrado', 404);
            }
            
            return $this->jsonResponse([
                'sucesso' => true,
                'mensagem' => 'Status do evento atualizado com sucesso!'
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao atualizar status do evento', 500);
        }
    }
    
    /**
     * Estatísticas gerais do sistema
     * GET /api/eventos/estatisticas
     */
    public function estatisticasGerais(Request $request): Response
    {
        try {
            $periodo = $request->getQueryParam('periodo') ?? '30'; // últimos 30 dias
            
            $estatisticas = $this->database->fetchRow("
                SELECT 
                    COUNT(DISTINCT e.id_evento) as total_eventos,
                    COUNT(DISTINCT c.id_cliente) as total_clientes,
                    COUNT(DISTINCT p.id_promoter) as total_promoters,
                    COUNT(DISTINCT ve.id_venda) as total_vendas,
                    COALESCE(SUM(ve.valor_final), 0) as faturamento_total,
                    COUNT(DISTINCT pe.id_presenca) as total_presencas,
                    COUNT(DISTINCT pt.id_transacao) as total_transacoes_pdv,
                    COALESCE(SUM(pt.valor_transacao), 0) as faturamento_pdv
                FROM eventos e
                LEFT JOIN vendas_eventos ve ON e.id_evento = ve.id_evento 
                    AND ve.status_pagamento = 'aprovado'
                    AND ve.data_venda >= DATE_SUB(NOW(), INTERVAL ? DAY)
                LEFT JOIN clientes_eventos c ON ve.id_cliente = c.id_cliente
                LEFT JOIN promoters p ON ve.id_promoter = p.id_promoter
                LEFT JOIN presencas_eventos pe ON ve.id_venda = pe.id_venda
                LEFT JOIN pdv_transacoes pt ON e.id_evento = pt.id_evento 
                    AND pt.status_transacao = 'aprovada'
                    AND pt.data_hora_transacao >= DATE_SUB(NOW(), INTERVAL ? DAY)
                WHERE e.ativo = TRUE
            ", [$periodo, $periodo]);
            
            // Top 5 eventos por faturamento
            $top_eventos = $this->database->fetchAll("
                SELECT 
                    e.nome_evento,
                    e.data_evento,
                    COUNT(DISTINCT ve.id_venda) as total_vendas,
                    COALESCE(SUM(ve.valor_final), 0) as faturamento
                FROM eventos e
                LEFT JOIN vendas_eventos ve ON e.id_evento = ve.id_evento 
                    AND ve.status_pagamento = 'aprovado'
                    AND ve.data_venda >= DATE_SUB(NOW(), INTERVAL ? DAY)
                WHERE e.ativo = TRUE
                GROUP BY e.id_evento
                ORDER BY faturamento DESC
                LIMIT 5
            ", [$periodo]);
            
            // Top 5 promoters
            $top_promoters = $this->database->fetchAll("
                SELECT 
                    p.nome_promoter,
                    COUNT(DISTINCT ve.id_venda) as total_vendas,
                    COALESCE(SUM(ve.valor_final), 0) as faturamento_gerado,
                    COALESCE(SUM(c.valor_comissao), 0) as total_comissoes
                FROM promoters p
                LEFT JOIN vendas_eventos ve ON p.id_promoter = ve.id_promoter 
                    AND ve.status_pagamento = 'aprovado' 
                    AND ve.data_venda >= DATE_SUB(NOW(), INTERVAL ? DAY)
                LEFT JOIN comissoes_promoters c ON ve.id_venda = c.id_venda
                WHERE p.ativo = TRUE
                GROUP BY p.id_promoter
                ORDER BY faturamento_gerado DESC
                LIMIT 5
            ", [$periodo]);
            
            return $this->jsonResponse([
                'sucesso' => true,
                'estatisticas' => $estatisticas,
                'top_eventos' => $top_eventos,
                'top_promoters' => $top_promoters,
                'periodo_dias' => $periodo
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Erro ao buscar estatísticas', 500);
        }
    }
}