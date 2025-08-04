/**
 * Estoque Module - Sistema ERP
 * Gestão completa de produtos e movimentações de estoque
 */
class EstoqueManager {
    constructor() {
        this.produtos = [];
        this.alertas = [];
        this.currentPage = 1;
        this.pageSize = 20;
        this.totalProdutos = 0;
        this.filters = {
            search: '',
            categoria: '',
            status: 'ativo'
        };
        this.selectedProdutos = [];
        this.editingProduto = null;
        
        this.init();
    }
    
    async init() {
        try {
            await this.loadUserInfo();
            await this.loadStats();
            await this.loadCategorias();
            await this.loadProdutos();
            await this.loadAlertas();
            this.setupEventListeners();
        } catch (error) {
            console.error('Erro ao inicializar Estoque:', error);
            ui.showToast('Erro ao carregar módulo de Estoque', 'error');
        }
    }
    
    /**
     * Carrega informações do usuário
     */
    async loadUserInfo() {
        const user = api.getCurrentUser();
        if (user) {
            document.getElementById('userName').textContent = user.name;
            document.getElementById('userEmail').textContent = user.email;
        }
    }
    
    /**
     * Carrega estatísticas do estoque
     */
    async loadStats() {
        try {
            const response = await api.get('/estoque/stats');
            if (response.success) {
                const stats = response.data;
                document.getElementById('totalProdutos').textContent = stats.total_produtos || 0;
                document.getElementById('alertasEstoque').textContent = stats.alertas_estoque || 0;
                document.getElementById('valorEstoque').textContent = ui.formatCurrency(stats.valor_total || 0);
                document.getElementById('movimentacoesMes').textContent = stats.movimentacoes_mes || 0;
            }
        } catch (error) {
            console.error('Erro ao carregar estatísticas:', error);
        }
    }
    
    /**
     * Carrega categorias para filtros e forms
     */
    async loadCategorias() {
        try {
            const response = await api.get('/estoque/categorias');
            if (response.success) {
                const categorias = response.data;
                
                // Preencher filtro
                const filtroSelect = document.getElementById('filtroCategoria');
                filtroSelect.innerHTML = '<option value="">Todas Categorias</option>';
                
                // Preencher forms
                const categoriaSelects = ['categoria', 'categoriaEdit'];
                categoriaSelects.forEach(selectId => {
                    const select = document.getElementById(selectId);
                    if (select) {
                        select.innerHTML = '<option value="">Selecione...</option>';
                    }
                });
                
                categorias.forEach(categoria => {
                    const option = `<option value="${categoria.id}">${categoria.nome}</option>`;
                    filtroSelect.insertAdjacentHTML('beforeend', option);
                    
                    categoriaSelects.forEach(selectId => {
                        const select = document.getElementById(selectId);
                        if (select) {
                            select.insertAdjacentHTML('beforeend', option);
                        }
                    });
                });
            }
        } catch (error) {
            console.error('Erro ao carregar categorias:', error);
        }
    }
    
    /**
     * Carrega lista de produtos
     */
    async loadProdutos() {
        try {
            ui.showLoading(true);
            
            const params = {
                page: this.currentPage,
                per_page: this.pageSize,
                search: this.filters.search,
                categoria: this.filters.categoria,
                status: this.filters.status
            };
            
            const response = await api.get('/estoque/list', params);
            
            if (response.success) {
                this.produtos = response.data.dados || [];
                this.totalProdutos = response.data.paginacao?.total || 0;
                
                this.renderProdutosTable();
                this.renderPagination(response.data.paginacao);
                this.updateTableInfo();
            }
        } catch (error) {
            console.error('Erro ao carregar produtos:', error);
            ui.showToast('Erro ao carregar produtos', 'error');
        } finally {
            ui.showLoading(false);
        }
    }
    
    /**
     * Carrega alertas de estoque baixo
     */
    async loadAlertas() {
        try {
            const response = await api.get('/estoque/alerts');
            if (response.success) {
                this.alertas = response.data || [];
                this.renderAlertas();
            }
        } catch (error) {
            console.error('Erro ao carregar alertas:', error);
        }
    }
    
    /**
     * Renderiza tabela de produtos
     */
    renderProdutosTable() {
        const tbody = document.getElementById('produtosTableBody');
        
        if (this.produtos.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="10" class="no-data">
                        <i class="fas fa-box-open"></i>
                        <p>Nenhum produto encontrado</p>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = this.produtos.map(produto => `
            <tr>
                <td>
                    <input type="checkbox" value="${produto.id}" class="produto-checkbox">
                </td>
                <td><strong>${produto.sku}</strong></td>
                <td>
                    <div class="produto-info">
                        <span class="produto-nome">${produto.nome}</span>
                        ${produto.descricao ? `<small class="produto-desc">${produto.descricao}</small>` : ''}
                    </div>
                </td>
                <td>
                    <span class="categoria-badge">${produto.categoria || 'N/A'}</span>
                </td>
                <td>
                    <span class="quantidade ${produto.quantidade_atual <= produto.estoque_minimo ? 'low-stock' : ''}">
                        ${produto.quantidade_atual} ${produto.unidade || 'UN'}
                    </span>
                </td>
                <td>${produto.estoque_minimo} ${produto.unidade || 'UN'}</td>
                <td>${ui.formatCurrency(produto.preco_custo)}</td>
                <td><strong>${ui.formatCurrency(produto.preco_venda)}</strong></td>
                <td>
                    <span class="status-badge ${produto.status}">
                        ${produto.status === 'ativo' ? 'Ativo' : 'Inativo'}
                    </span>
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-icon edit" onclick="estoque.editProduto(${produto.id})" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-icon movimentacao" onclick="estoque.abrirMovimentacao(${produto.id})" title="Movimentação">
                            <i class="fas fa-exchange-alt"></i>
                        </button>
                        <button class="btn-icon delete" onclick="estoque.deleteProduto(${produto.id})" title="Excluir">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    }
    
    /**
     * Renderiza alertas de estoque
     */
    renderAlertas() {
        const alertsGrid = document.getElementById('alertsGrid');
        
        if (this.alertas.length === 0) {
            alertsGrid.innerHTML = `
                <div class="alert-card success">
                    <i class="fas fa-check-circle"></i>
                    <h4>Estoque OK</h4>
                    <p>Todos os produtos estão com estoque adequado</p>
                </div>
            `;
            return;
        }
        
        alertsGrid.innerHTML = this.alertas.map(alerta => `
            <div class="alert-card warning">
                <i class="fas fa-exclamation-triangle"></i>
                <h4>${alerta.nome}</h4>
                <p>
                    <strong>SKU:</strong> ${alerta.sku}<br>
                    <strong>Estoque:</strong> ${alerta.quantidade_atual} / ${alerta.estoque_minimo}<br>
                    <strong>Categoria:</strong> ${alerta.categoria || 'N/A'}
                </p>
                <button class="btn btn-sm btn-primary" onclick="estoque.abrirMovimentacao(${alerta.id})">
                    Reabastecer
                </button>
            </div>
        `).join('');
    }
    
    /**
     * Configura event listeners
     */
    setupEventListeners() {
        // Busca
        document.getElementById('searchInput').addEventListener('input', (e) => {
            this.filters.search = e.target.value;
            this.debounceSearch();
        });
        
        // Filtro categoria
        document.getElementById('filtroCategoria').addEventListener('change', (e) => {
            this.filters.categoria = e.target.value;
            this.currentPage = 1;
            this.loadProdutos();
        });
        
        // Botões principais
        document.getElementById('btnNovoProduto').addEventListener('click', () => this.abrirModalProduto());
        document.getElementById('btnMovimentacao').addEventListener('click', () => this.abrirModalMovimentacao());
        document.getElementById('btnRelatorio').addEventListener('click', () => this.gerarRelatorio());
        document.getElementById('btnRefresh').addEventListener('click', () => this.refresh());
        document.getElementById('btnExportar').addEventListener('click', () => this.exportarDados());
        
        // Modais
        document.getElementById('closeProdutoModal').addEventListener('click', () => this.fecharModalProduto());
        document.getElementById('closeMovimentacaoModal').addEventListener('click', () => this.fecharModalMovimentacao());
        document.getElementById('cancelProduto').addEventListener('click', () => this.fecharModalProduto());
        document.getElementById('cancelMovimentacao').addEventListener('click', () => this.fecharModalMovimentacao());
        
        // Forms
        document.getElementById('produtoForm').addEventListener('submit', (e) => this.saveProduto(e));
        document.getElementById('movimentacaoForm').addEventListener('submit', (e) => this.saveMovimentacao(e));
        
        // Select all checkbox
        document.getElementById('selectAll').addEventListener('change', (e) => {
            const checkboxes = document.querySelectorAll('.produto-checkbox');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
        });
        
        // Máscaras monetárias
        ui.setupMoneyMasks();
    }
    
    /**
     * Debounce para busca
     */
    debounceSearch() {
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            this.currentPage = 1;
            this.loadProdutos();
        }, 500);
    }
    
    /**
     * Abre modal para novo produto
     */
    abrirModalProduto(produto = null) {
        this.editingProduto = produto;
        const modal = document.getElementById('modalProduto');
        const title = document.getElementById('modalTitle');
        const form = document.getElementById('produtoForm');
        
        if (produto) {
            title.textContent = 'Editar Produto';
            this.preencherFormProduto(produto);
        } else {
            title.textContent = 'Novo Produto';
            form.reset();
        }
        
        modal.style.display = 'flex';
    }
    
    /**
     * Fecha modal de produto
     */
    fecharModalProduto() {
        document.getElementById('modalProduto').style.display = 'none';
        this.editingProduto = null;
    }
    
    /**
     * Abre modal de movimentação
     */
    abrirModalMovimentacao(produtoId = null) {
        const modal = document.getElementById('modalMovimentacao');
        const form = document.getElementById('movimentacaoForm');
        const select = document.getElementById('produtoMovimentacao');
        
        // Carregar produtos no select
        this.carregarProdutosSelect(select, produtoId);
        
        form.reset();
        if (produtoId) {
            select.value = produtoId;
        }
        
        modal.style.display = 'flex';
    }
    
    /**
     * Fecha modal de movimentação
     */
    fecharModalMovimentacao() {
        document.getElementById('modalMovimentacao').style.display = 'none';
    }
    
    /**
     * Salva produto (criar/editar)
     */
    async saveProduto(e) {
        e.preventDefault();
        
        try {
            ui.showLoading(true);
            
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData.entries());
            
            // Converter valores monetários
            data.preco_custo = ui.parseCurrency(data.preco_custo);
            data.preco_venda = ui.parseCurrency(data.preco_venda);
            
            let response;
            if (this.editingProduto) {
                response = await api.put(`/estoque/update/${this.editingProduto.id}`, data);
            } else {
                response = await api.post('/estoque/create', data);
            }
            
            if (response.success) {
                ui.showToast(
                    this.editingProduto ? 'Produto atualizado com sucesso!' : 'Produto criado com sucesso!',
                    'success'
                );
                this.fecharModalProduto();
                await this.refresh();
            } else {
                throw new Error(response.message || 'Erro ao salvar produto');
            }
        } catch (error) {
            console.error('Erro ao salvar produto:', error);
            ui.showToast('Erro ao salvar produto: ' + error.message, 'error');
        } finally {
            ui.showLoading(false);
        }
    }
    
    /**
     * Salva movimentação
     */
    async saveMovimentacao(e) {
        e.preventDefault();
        
        try {
            ui.showLoading(true);
            
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData.entries());
            
            // Converter valor unitário
            data.valor_unitario = ui.parseCurrency(data.valor_unitario);
            
            const response = await api.post('/estoque/movimentacao', data);
            
            if (response.success) {
                ui.showToast('Movimentação registrada com sucesso!', 'success');
                this.fecharModalMovimentacao();
                await this.refresh();
            } else {
                throw new Error(response.message || 'Erro ao registrar movimentação');
            }
        } catch (error) {
            console.error('Erro ao registrar movimentação:', error);
            ui.showToast('Erro ao registrar movimentação: ' + error.message, 'error');
        } finally {
            ui.showLoading(false);
        }
    }
    
    /**
     * Edita produto
     */
    async editProduto(id) {
        try {
            const response = await api.get(`/estoque/${id}`);
            if (response.success) {
                this.abrirModalProduto(response.data);
            }
        } catch (error) {
            console.error('Erro ao carregar produto:', error);
            ui.showToast('Erro ao carregar dados do produto', 'error');
        }
    }
    
    /**
     * Exclui produto
     */
    async deleteProduto(id) {
        if (!confirm('Tem certeza que deseja excluir este produto?')) {
            return;
        }
        
        try {
            ui.showLoading(true);
            
            const response = await api.delete(`/estoque/delete/${id}`);
            
            if (response.success) {
                ui.showToast('Produto excluído com sucesso!', 'success');
                await this.refresh();
            } else {
                throw new Error(response.message || 'Erro ao excluir produto');
            }
        } catch (error) {
            console.error('Erro ao excluir produto:', error);
            ui.showToast('Erro ao excluir produto: ' + error.message, 'error');
        } finally {
            ui.showLoading(false);
        }
    }
    
    /**
     * Refresh completo da página
     */
    async refresh() {
        await Promise.all([
            this.loadStats(),
            this.loadProdutos(),
            this.loadAlertas()
        ]);
    }
    
    /**
     * Gera relatório de estoque
     */
    async gerarRelatorio() {
        try {
            ui.showLoading(true);
            
            const response = await api.get('/estoque/relatorio', {
                formato: 'pdf',
                ...this.filters
            });
            
            if (response.success && response.data.url) {
                window.open(response.data.url, '_blank');
            }
        } catch (error) {
            console.error('Erro ao gerar relatório:', error);
            ui.showToast('Erro ao gerar relatório', 'error');
        } finally {
            ui.showLoading(false);
        }
    }
    
    /**
     * Exporta dados para Excel
     */
    async exportarDados() {
        try {
            ui.showLoading(true);
            
            const response = await api.get('/estoque/export', {
                formato: 'excel',
                ...this.filters
            });
            
            if (response.success && response.data.url) {
                const link = document.createElement('a');
                link.href = response.data.url;
                link.download = `estoque_${moment().format('YYYY-MM-DD')}.xlsx`;
                link.click();
            }
        } catch (error) {
            console.error('Erro ao exportar dados:', error);
            ui.showToast('Erro ao exportar dados', 'error');
        } finally {
            ui.showLoading(false);
        }
    }
    
    /**
     * Métodos auxiliares
     */
    preencherFormProduto(produto) {
        Object.keys(produto).forEach(key => {
            const input = document.getElementById(key);
            if (input) {
                if (key.includes('preco')) {
                    input.value = ui.formatCurrency(produto[key]);
                } else {
                    input.value = produto[key];
                }
            }
        });
    }
    
    async carregarProdutosSelect(select, selectedId = null) {
        try {
            const response = await api.get('/estoque/list', { per_page: 1000 });
            if (response.success) {
                select.innerHTML = '<option value="">Selecione um produto...</option>';
                response.data.dados.forEach(produto => {
                    const selected = selectedId == produto.id ? 'selected' : '';
                    select.insertAdjacentHTML('beforeend', 
                        `<option value="${produto.id}" ${selected}>${produto.sku} - ${produto.nome}</option>`
                    );
                });
            }
        } catch (error) {
            console.error('Erro ao carregar produtos:', error);
        }
    }
    
    renderPagination(paginacao) {
        if (!paginacao) return;
        
        const pagination = document.getElementById('pagination');
        const totalPages = paginacao.ultima_pagina;
        const currentPage = paginacao.pagina_atual;
        
        if (totalPages <= 1) {
            pagination.innerHTML = '';
            return;
        }
        
        let html = '';
        
        // Previous
        if (currentPage > 1) {
            html += `<button class="page-btn" onclick="estoque.goToPage(${currentPage - 1})">Anterior</button>`;
        }
        
        // Pages
        for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
            html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="estoque.goToPage(${i})">${i}</button>`;
        }
        
        // Next
        if (currentPage < totalPages) {
            html += `<button class="page-btn" onclick="estoque.goToPage(${currentPage + 1})">Próximo</button>`;
        }
        
        pagination.innerHTML = html;
    }
    
    goToPage(page) {
        this.currentPage = page;
        this.loadProdutos();
    }
    
    updateTableInfo() {
        const info = document.getElementById('tableInfo');
        const start = ((this.currentPage - 1) * this.pageSize) + 1;
        const end = Math.min(this.currentPage * this.pageSize, this.totalProdutos);
        info.textContent = `Mostrando ${start}-${end} de ${this.totalProdutos} produtos`;
    }
}

// Instanciar quando a página carrega
let estoque;
document.addEventListener('DOMContentLoaded', () => {
    estoque = new EstoqueManager();
});