/**
 * Vendas Module - Sistema ERP
 * Gestão completa de vendas, performance e metas
 */
class VendasManager {
    constructor() {
        this.vendas = [];
        this.topProducts = [];
        this.currentPage = 1;
        this.pageSize = 20;
        this.totalVendas = 0;
        this.filters = {
            search: '',
            status: '',
            periodo: 'mes'
        };
        this.selectedVendas = [];
        this.editingVenda = null;
        this.vendaItens = [];
        this.salesChart = null;
        
        this.init();
    }
    
    async init() {
        try {
            await this.loadUserInfo();
            await this.loadStats();
            await this.loadVendas();
            await this.loadTopProducts();
            await this.loadChart();
            this.setupEventListeners();
        } catch (error) {
            console.error('Erro ao inicializar Vendas:', error);
            ui.showToast('Erro ao carregar módulo de Vendas', 'error');
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
     * Carrega estatísticas de vendas
     */
    async loadStats() {
        try {
            const response = await api.get('/vendas/stats');
            if (response.success) {
                const stats = response.data;
                document.getElementById('totalVendas').textContent = stats.total_vendas || 0;
                document.getElementById('receitaMes').textContent = ui.formatCurrency(stats.receita_mes || 0);
                document.getElementById('ticketMedio').textContent = ui.formatCurrency(stats.ticket_medio || 0);
                document.getElementById('metaAtingida').textContent = (stats.meta_atingida || 0) + '%';
            }
        } catch (error) {
            console.error('Erro ao carregar estatísticas:', error);
        }
    }
    
    /**
     * Carrega lista de vendas
     */
    async loadVendas() {
        try {
            ui.showLoading(true);
            
            const params = {
                page: this.currentPage,
                per_page: this.pageSize,
                search: this.filters.search,
                status: this.filters.status,
                periodo: this.filters.periodo
            };
            
            const response = await api.get('/vendas/list', params);
            
            if (response.success) {
                this.vendas = response.data.dados || [];
                this.totalVendas = response.data.paginacao?.total || 0;
                
                this.renderVendasTable();
                this.renderPagination(response.data.paginacao);
                this.updateTableInfo();
            }
        } catch (error) {
            console.error('Erro ao carregar vendas:', error);
            ui.showToast('Erro ao carregar vendas', 'error');
        } finally {
            ui.showLoading(false);
        }
    }
    
    /**
     * Carrega produtos mais vendidos
     */
    async loadTopProducts() {
        try {
            const response = await api.get('/vendas/top-products', { limite: 5 });
            if (response.success) {
                this.topProducts = response.data || [];
                this.renderTopProducts();
            }
        } catch (error) {
            console.error('Erro ao carregar produtos mais vendidos:', error);
        }
    }
    
    /**
     * Carrega gráfico de vendas
     */
    async loadChart() {
        try {
            const period = document.querySelector('.chart-controls .btn.active')?.dataset.period || '30';
            const response = await api.get('/vendas/chart', { periodo: period });
            
            if (response.success) {
                this.renderSalesChart(response.data);
            }
        } catch (error) {
            console.error('Erro ao carregar gráfico:', error);
        }
    }
    
    /**
     * Renderiza tabela de vendas
     */
    renderVendasTable() {
        const tbody = document.getElementById('vendasTableBody');
        
        if (this.vendas.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="no-data">
                        <i class="fas fa-shopping-cart"></i>
                        <p>Nenhuma venda encontrada</p>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = this.vendas.map(venda => `
            <tr>
                <td>
                    <input type="checkbox" value="${venda.id}" class="venda-checkbox">
                </td>
                <td><strong>#${venda.numero_venda}</strong></td>
                <td>${moment(venda.data_venda).format('DD/MM/YYYY')}</td>
                <td>
                    <div class="cliente-info">
                        <span class="cliente-nome">${venda.cliente_nome || 'N/A'}</span>
                        ${venda.cliente_email ? `<small>${venda.cliente_email}</small>` : ''}
                    </div>
                </td>
                <td>${venda.vendedor_nome || 'N/A'}</td>
                <td>
                    <span class="badge-count">${venda.total_itens || 0} itens</span>
                </td>
                <td><strong>${ui.formatCurrency(venda.valor_total)}</strong></td>
                <td>
                    <span class="status-badge ${venda.status}">
                        ${this.getStatusText(venda.status)}
                    </span>
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-icon view" onclick="vendas.viewVenda(${venda.id})" title="Visualizar">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn-icon edit" onclick="vendas.editVenda(${venda.id})" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        ${venda.status === 'pendente' ? `
                            <button class="btn-icon delete" onclick="vendas.cancelVenda(${venda.id})" title="Cancelar">
                                <i class="fas fa-times"></i>
                            </button>
                        ` : ''}
                        <button class="btn-icon print" onclick="vendas.printVenda(${venda.id})" title="Imprimir">
                            <i class="fas fa-print"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    }
    
    /**
     * Renderiza produtos mais vendidos
     */
    renderTopProducts() {
        const grid = document.getElementById('topProductsGrid');
        
        if (this.topProducts.length === 0) {
            grid.innerHTML = `
                <div class="no-data">
                    <i class="fas fa-chart-bar"></i>
                    <p>Nenhum dado disponível</p>
                </div>
            `;
            return;
        }
        
        grid.innerHTML = this.topProducts.map((produto, index) => `
            <div class="top-product-card">
                <div class="product-rank">
                    <span class="rank-number">${index + 1}º</span>
                </div>
                <div class="product-info">
                    <h4>${produto.nome}</h4>
                    <p><strong>SKU:</strong> ${produto.sku}</p>
                    <p><strong>Vendidos:</strong> ${produto.total_vendido}</p>
                    <p><strong>Receita:</strong> ${ui.formatCurrency(produto.receita_total)}</p>
                </div>
            </div>
        `).join('');
    }
    
    /**
     * Renderiza gráfico de vendas
     */
    renderSalesChart(data) {
        const ctx = document.getElementById('salesChart').getContext('2d');
        
        if (this.salesChart) {
            this.salesChart.destroy();
        }
        
        this.salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.rotulos || [],
                datasets: [{
                    label: 'Vendas (R$)',
                    data: data.valores || [],
                    borderColor: 'rgb(54, 162, 235)',
                    backgroundColor: 'rgba(54, 162, 235, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Quantidade',
                    data: data.quantidades || [],
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        ticks: {
                            callback: function(value) {
                                return ui.formatCurrencyShort(value);
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (context.datasetIndex === 0) {
                                    return `Vendas: ${ui.formatCurrency(context.parsed.y)}`;
                                } else {
                                    return `Quantidade: ${context.parsed.y}`;
                                }
                            }
                        }
                    }
                }
            }
        });
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
        
        // Filtros
        document.getElementById('filtroStatus').addEventListener('change', (e) => {
            this.filters.status = e.target.value;
            this.currentPage = 1;
            this.loadVendas();
        });
        
        document.getElementById('filtroPeriodo').addEventListener('change', (e) => {
            this.filters.periodo = e.target.value;
            this.currentPage = 1;
            this.loadVendas();
            this.loadStats();
        });
        
        // Botões principais
        document.getElementById('btnNovaVenda').addEventListener('click', () => this.abrirModalVenda());
        document.getElementById('btnMetas').addEventListener('click', () => this.abrirModalMetas());
        document.getElementById('btnRelatorio').addEventListener('click', () => this.gerarRelatorio());
        document.getElementById('btnRefresh').addEventListener('click', () => this.refresh());
        document.getElementById('btnExportar').addEventListener('click', () => this.exportarDados());
        
        // Controles do gráfico
        document.querySelectorAll('.chart-controls .btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.chart-controls .btn').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                this.loadChart();
            });
        });
        
        // Modais
        document.getElementById('closeVendaModal').addEventListener('click', () => this.fecharModalVenda());
        document.getElementById('closeMetasModal').addEventListener('click', () => this.fecharModalMetas());
        document.getElementById('cancelVenda').addEventListener('click', () => this.fecharModalVenda());
        document.getElementById('cancelMetas').addEventListener('click', () => this.fecharModalMetas());
        
        // Forms
        document.getElementById('vendaForm').addEventListener('submit', (e) => this.saveVenda(e));
        document.getElementById('metasForm').addEventListener('submit', (e) => this.saveMetas(e));
        
        // Itens da venda
        document.getElementById('btnAdicionarItem').addEventListener('click', () => this.adicionarItem());
        document.getElementById('descontoGeral').addEventListener('input', () => this.calcularTotais());
        
        // Select all checkbox
        document.getElementById('selectAll').addEventListener('change', (e) => {
            const checkboxes = document.querySelectorAll('.venda-checkbox');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
        });
        
        // Máscaras monetárias
        ui.setupMoneyMasks();
        
        // Data padrão
        document.getElementById('dataVenda').value = moment().format('YYYY-MM-DD');
    }
    
    /**
     * Debounce para busca
     */
    debounceSearch() {
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            this.currentPage = 1;
            this.loadVendas();
        }, 500);
    }
    
    /**
     * Abre modal para nova venda
     */
    abrirModalVenda(venda = null) {
        this.editingVenda = venda;
        const modal = document.getElementById('modalVenda');
        const title = document.getElementById('modalTitle');
        const form = document.getElementById('vendaForm');
        
        if (venda) {
            title.textContent = 'Editar Venda';
            this.preencherFormVenda(venda);
        } else {
            title.textContent = 'Nova Venda';
            form.reset();
            this.vendaItens = [];
            this.renderItensVenda();
            this.calcularTotais();
            document.getElementById('dataVenda').value = moment().format('YYYY-MM-DD');
        }
        
        this.carregarClientesSelect();
        this.carregarVendedoresSelect();
        
        modal.style.display = 'flex';
    }
    
    /**
     * Fecha modal de venda
     */
    fecharModalVenda() {
        document.getElementById('modalVenda').style.display = 'none';
        this.editingVenda = null;
        this.vendaItens = [];
    }
    
    /**
     * Abre modal de metas
     */
    abrirModalMetas() {
        const modal = document.getElementById('modalMetas');
        const form = document.getElementById('metasForm');
        
        form.reset();
        this.carregarVendedoresSelect(document.getElementById('vendedorMeta'));
        
        modal.style.display = 'flex';
    }
    
    /**
     * Fecha modal de metas
     */
    fecharModalMetas() {
        document.getElementById('modalMetas').style.display = 'none';
    }
    
    /**
     * Adiciona item à venda
     */
    adicionarItem() {
        const novoItem = {
            id: Date.now(),
            produto_id: '',
            produto_nome: '',
            quantidade: 1,
            preco_unitario: 0,
            desconto: 0,
            subtotal: 0
        };
        
        this.vendaItens.push(novoItem);
        this.renderItensVenda();
    }
    
    /**
     * Remove item da venda
     */
    removerItem(itemId) {
        this.vendaItens = this.vendaItens.filter(item => item.id !== itemId);
        this.renderItensVenda();
        this.calcularTotais();
    }
    
    /**
     * Renderiza itens da venda
     */
    renderItensVenda() {
        const tbody = document.getElementById('itensTableBody');
        
        if (this.vendaItens.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="no-data">
                        <p>Nenhum item adicionado</p>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = this.vendaItens.map(item => `
            <tr>
                <td>
                    <select class="produto-select" data-item-id="${item.id}" onchange="vendas.updateItemProduto(${item.id}, this.value)">
                        <option value="">Selecione um produto...</option>
                    </select>
                </td>
                <td>
                    <input type="number" min="1" step="0.01" value="${item.quantidade}" 
                           onchange="vendas.updateItemQuantidade(${item.id}, this.value)">
                </td>
                <td>
                    <input type="text" class="money-input" value="${ui.formatCurrency(item.preco_unitario)}" 
                           onchange="vendas.updateItemPreco(${item.id}, this.value)">
                </td>
                <td>
                    <input type="text" class="money-input" value="${ui.formatCurrency(item.desconto)}" 
                           onchange="vendas.updateItemDesconto(${item.id}, this.value)">
                </td>
                <td><strong>${ui.formatCurrency(item.subtotal)}</strong></td>
                <td>
                    <button class="btn-icon delete" onclick="vendas.removerItem(${item.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `).join('');
        
        // Carregar produtos nos selects
        this.carregarProdutosSelects();
        
        // Aplicar máscaras monetárias
        ui.setupMoneyMasks();
    }
    
    /**
     * Calcula totais da venda
     */
    calcularTotais() {
        const subtotal = this.vendaItens.reduce((total, item) => total + item.subtotal, 0);
        const descontoGeral = ui.parseCurrency(document.getElementById('descontoGeral').value);
        const total = subtotal - descontoGeral;
        
        document.getElementById('subtotalVenda').textContent = ui.formatCurrency(subtotal);
        document.getElementById('totalVenda').textContent = ui.formatCurrency(total);
    }
    
    /**
     * Atualiza produto do item
     */
    async updateItemProduto(itemId, produtoId) {
        if (!produtoId) return;
        
        try {
            const response = await api.get(`/estoque/${produtoId}`);
            if (response.success) {
                const produto = response.data;
                const item = this.vendaItens.find(i => i.id === itemId);
                if (item) {
                    item.produto_id = produto.id;
                    item.produto_nome = produto.nome;
                    item.preco_unitario = produto.preco_venda;
                    item.subtotal = item.quantidade * item.preco_unitario - item.desconto;
                }
                this.renderItensVenda();
                this.calcularTotais();
            }
        } catch (error) {
            console.error('Erro ao carregar produto:', error);
        }
    }
    
    /**
     * Atualiza quantidade do item
     */
    updateItemQuantidade(itemId, quantidade) {
        const item = this.vendaItens.find(i => i.id === itemId);
        if (item) {
            item.quantidade = parseFloat(quantidade) || 1;
            item.subtotal = item.quantidade * item.preco_unitario - item.desconto;
            this.calcularTotais();
        }
    }
    
    /**
     * Atualiza preço do item
     */
    updateItemPreco(itemId, preco) {
        const item = this.vendaItens.find(i => i.id === itemId);
        if (item) {
            item.preco_unitario = ui.parseCurrency(preco);
            item.subtotal = item.quantidade * item.preco_unitario - item.desconto;
            this.calcularTotais();
        }
    }
    
    /**
     * Atualiza desconto do item
     */
    updateItemDesconto(itemId, desconto) {
        const item = this.vendaItens.find(i => i.id === itemId);
        if (item) {
            item.desconto = ui.parseCurrency(desconto);
            item.subtotal = item.quantidade * item.preco_unitario - item.desconto;
            this.calcularTotais();
        }
    }
    
    /**
     * Salva venda
     */
    async saveVenda(e) {
        e.preventDefault();
        
        if (this.vendaItens.length === 0) {
            ui.showToast('Adicione pelo menos um item à venda', 'warning');
            return;
        }
        
        try {
            ui.showLoading(true);
            
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData.entries());
            
            // Adicionar itens
            data.itens = this.vendaItens.map(item => ({
                produto_id: item.produto_id,
                quantidade: item.quantidade,
                preco_unitario: item.preco_unitario,
                desconto: item.desconto
            }));
            
            // Adicionar desconto geral
            data.desconto_geral = ui.parseCurrency(document.getElementById('descontoGeral').value);
            
            let response;
            if (this.editingVenda) {
                response = await api.put(`/vendas/update/${this.editingVenda.id}`, data);
            } else {
                response = await api.post('/vendas/create', data);
            }
            
            if (response.success) {
                ui.showToast(
                    this.editingVenda ? 'Venda atualizada com sucesso!' : 'Venda criada com sucesso!',
                    'success'
                );
                this.fecharModalVenda();
                await this.refresh();
            } else {
                throw new Error(response.message || 'Erro ao salvar venda');
            }
        } catch (error) {
            console.error('Erro ao salvar venda:', error);
            ui.showToast('Erro ao salvar venda: ' + error.message, 'error');
        } finally {
            ui.showLoading(false);
        }
    }
    
    /**
     * Salva metas
     */
    async saveMetas(e) {
        e.preventDefault();
        
        try {
            ui.showLoading(true);
            
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData.entries());
            
            data.valor = ui.parseCurrency(data.valor);
            
            const response = await api.post('/vendas/metas', data);
            
            if (response.success) {
                ui.showToast('Meta configurada com sucesso!', 'success');
                this.fecharModalMetas();
                await this.loadStats();
            } else {
                throw new Error(response.message || 'Erro ao salvar meta');
            }
        } catch (error) {
            console.error('Erro ao salvar meta:', error);
            ui.showToast('Erro ao salvar meta: ' + error.message, 'error');
        } finally {
            ui.showLoading(false);
        }
    }
    
    /**
     * Métodos auxiliares
     */
    getStatusText(status) {
        const statusMap = {
            'pendente': 'Pendente',
            'concluida': 'Concluída',
            'cancelada': 'Cancelada'
        };
        return statusMap[status] || status;
    }
    
    async carregarClientesSelect() {
        try {
            const response = await api.get('/crm/list', { per_page: 1000 });
            if (response.success) {
                const select = document.getElementById('cliente');
                select.innerHTML = '<option value="">Selecione um cliente...</option>';
                response.data.dados.forEach(cliente => {
                    select.insertAdjacentHTML('beforeend', 
                        `<option value="${cliente.id}">${cliente.nome}</option>`
                    );
                });
            }
        } catch (error) {
            console.error('Erro ao carregar clientes:', error);
        }
    }
    
    async carregarVendedoresSelect(select = null) {
        if (!select) {
            select = document.getElementById('vendedor');
        }
        
        try {
            const response = await api.get('/config/usuarios');
            if (response.success) {
                select.innerHTML = '<option value="">Selecione um vendedor...</option>';
                response.data.dados.forEach(usuario => {
                    select.insertAdjacentHTML('beforeend', 
                        `<option value="${usuario.id}">${usuario.nome}</option>`
                    );
                });
            }
        } catch (error) {
            console.error('Erro ao carregar vendedores:', error);
        }
    }
    
    async carregarProdutosSelects() {
        try {
            const response = await api.get('/estoque/list', { per_page: 1000 });
            if (response.success) {
                document.querySelectorAll('.produto-select').forEach(select => {
                    const currentValue = select.value;
                    select.innerHTML = '<option value="">Selecione um produto...</option>';
                    response.data.dados.forEach(produto => {
                        const selected = currentValue == produto.id ? 'selected' : '';
                        select.insertAdjacentHTML('beforeend', 
                            `<option value="${produto.id}" ${selected}>${produto.sku} - ${produto.nome}</option>`
                        );
                    });
                });
            }
        } catch (error) {
            console.error('Erro ao carregar produtos:', error);
        }
    }
    
    async refresh() {
        await Promise.all([
            this.loadStats(),
            this.loadVendas(),
            this.loadTopProducts(),
            this.loadChart()
        ]);
    }
    
    // Métodos de ação
    async viewVenda(id) {
        // Implementar visualização detalhada
        ui.showToast('Funcionalidade em desenvolvimento', 'info');
    }
    
    async editVenda(id) {
        try {
            const response = await api.get(`/vendas/${id}`);
            if (response.success) {
                this.abrirModalVenda(response.data);
            }
        } catch (error) {
            console.error('Erro ao carregar venda:', error);
            ui.showToast('Erro ao carregar dados da venda', 'error');
        }
    }
    
    async cancelVenda(id) {
        if (!confirm('Tem certeza que deseja cancelar esta venda?')) {
            return;
        }
        
        try {
            const response = await api.put(`/vendas/update/${id}`, { status: 'cancelada' });
            if (response.success) {
                ui.showToast('Venda cancelada com sucesso!', 'success');
                await this.refresh();
            }
        } catch (error) {
            console.error('Erro ao cancelar venda:', error);
            ui.showToast('Erro ao cancelar venda', 'error');
        }
    }
    
    async printVenda(id) {
        try {
            const response = await api.get(`/vendas/${id}/print`);
            if (response.success && response.data.url) {
                window.open(response.data.url, '_blank');
            }
        } catch (error) {
            console.error('Erro ao imprimir venda:', error);
            ui.showToast('Erro ao imprimir venda', 'error');
        }
    }
    
    async gerarRelatorio() {
        try {
            const response = await api.get('/vendas/report', {
                formato: 'pdf',
                ...this.filters
            });
            if (response.success && response.data.url) {
                window.open(response.data.url, '_blank');
            }
        } catch (error) {
            console.error('Erro ao gerar relatório:', error);
            ui.showToast('Erro ao gerar relatório', 'error');
        }
    }
    
    async exportarDados() {
        try {
            const response = await api.get('/vendas/export', {
                formato: 'excel',
                ...this.filters
            });
            if (response.success && response.data.url) {
                const link = document.createElement('a');
                link.href = response.data.url;
                link.download = `vendas_${moment().format('YYYY-MM-DD')}.xlsx`;
                link.click();
            }
        } catch (error) {
            console.error('Erro ao exportar dados:', error);
            ui.showToast('Erro ao exportar dados', 'error');
        }
    }
    
    // Paginação e info
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
        
        if (currentPage > 1) {
            html += `<button class="page-btn" onclick="vendas.goToPage(${currentPage - 1})">Anterior</button>`;
        }
        
        for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
            html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="vendas.goToPage(${i})">${i}</button>`;
        }
        
        if (currentPage < totalPages) {
            html += `<button class="page-btn" onclick="vendas.goToPage(${currentPage + 1})">Próximo</button>`;
        }
        
        pagination.innerHTML = html;
    }
    
    goToPage(page) {
        this.currentPage = page;
        this.loadVendas();
    }
    
    updateTableInfo() {
        const info = document.getElementById('tableInfo');
        const start = ((this.currentPage - 1) * this.pageSize) + 1;
        const end = Math.min(this.currentPage * this.pageSize, this.totalVendas);
        info.textContent = `Mostrando ${start}-${end} de ${this.totalVendas} vendas`;
    }
}

// Instanciar quando a página carrega
let vendas;
document.addEventListener('DOMContentLoaded', () => {
    vendas = new VendasManager();
});