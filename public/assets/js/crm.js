/**
 * CRM Module - Sistema ERP
 * Gestão de clientes com integração completa à API
 */
class CRMManager {
    constructor() {
        this.clients = [];
        this.currentPage = 1;
        this.pageSize = 20;
        this.totalClients = 0;
        this.filters = {
            search: '',
            status: '',
            dateFrom: ''
        };
        this.selectedClients = [];
        this.editingClient = null;
        
        this.init();
    }
    
    async init() {
        try {
            await this.loadUserInfo();
            await this.loadStats();
            await this.loadClients();
            this.setupEventListeners();
        } catch (error) {
            console.error('Erro ao inicializar CRM:', error);
            ui.showToast('Erro ao carregar módulo CRM', 'error');
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
     * Carrega estatísticas do CRM
     */
    async loadStats() {
        try {
            const response = await api.get('/crm/stats');
            const stats = response.data;
            
            document.getElementById('totalClientsCount').textContent = ui.formatNumber(stats.total);
            document.getElementById('activeClientsCount').textContent = ui.formatNumber(stats.active);
            document.getElementById('newClientsCount').textContent = ui.formatNumber(stats.new_this_month);
            document.getElementById('clientsRevenue').textContent = ui.formatCurrency(stats.total_revenue);
            
        } catch (error) {
            console.error('Erro ao carregar estatísticas:', error);
            // Usar valores padrão em caso de erro
            document.getElementById('totalClientsCount').textContent = '0';
            document.getElementById('activeClientsCount').textContent = '0';
            document.getElementById('newClientsCount').textContent = '0';
            document.getElementById('clientsRevenue').textContent = 'R$ 0,00';
        }
    }
    
    /**
     * Carrega lista de clientes
     */
    async loadClients() {
        try {
            this.showTableLoading(true);
            
            const params = {
                page: this.currentPage,
                limit: this.pageSize,
                ...this.filters
            };
            
            const response = await api.getClients(params);
            
            this.clients = response.data.clients;
            this.totalClients = response.data.pagination.total;
            
            this.renderClients();
            this.renderPagination(response.data.pagination);
            this.updatePaginationInfo();
            
        } catch (error) {
            console.error('Erro ao carregar clientes:', error);
            ui.showToast('Erro ao carregar clientes', 'error');
            this.renderClientsError();
        } finally {
            this.showTableLoading(false);
        }
    }
    
    /**
     * Renderiza tabela de clientes
     */
    renderClients() {
        const tbody = document.querySelector('#clientsTable tbody');
        
        if (this.clients.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>Nenhum cliente encontrado</p>
                        <button class="btn btn-primary" onclick="openClientModal()">
                            <i class="fas fa-plus"></i>
                            Cadastrar Primeiro Cliente
                        </button>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = this.clients.map(client => `
            <tr>
                <td>
                    <input type="checkbox" value="${client.id}" onchange="toggleClientSelection(${client.id})">
                </td>
                <td>
                    <div class="client-info">
                        <div class="client-avatar">
                            ${this.getClientInitials(client.name)}
                        </div>
                        <div class="client-details">
                            <strong>${client.name}</strong>
                            ${client.company_name ? `<small>${client.company_name}</small>` : ''}
                            ${client.document ? `<small>${client.document}</small>` : ''}
                        </div>
                    </div>
                </td>
                <td>
                    <div class="contact-info">
                        <div><i class="fas fa-envelope"></i> ${client.email}</div>
                        ${client.phone ? `<div><i class="fas fa-phone"></i> ${client.phone}</div>` : ''}
                    </div>
                </td>
                <td>
                    <span class="status-badge status-${client.status}">
                        ${this.getStatusLabel(client.status)}
                    </span>
                </td>
                <td class="text-center">
                    <span class="purchases-count">${client.total_purchases || 0}</span>
                </td>
                <td class="text-right">
                    <span class="total-spent">${ui.formatCurrency(client.total_spent || 0)}</span>
                </td>
                <td class="text-center">
                    ${client.last_purchase ? ui.formatDate(client.last_purchase) : '-'}
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-icon" onclick="viewClient(${client.id})" title="Ver detalhes">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn-icon" onclick="editClient(${client.id})" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-icon btn-danger" onclick="deleteClient(${client.id})" title="Excluir">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    }
    
    /**
     * Renderiza erro na tabela
     */
    renderClientsError() {
        const tbody = document.querySelector('#clientsTable tbody');
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="error-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Erro ao carregar clientes</p>
                    <button class="btn btn-outline" onclick="crm.loadClients()">
                        <i class="fas fa-refresh"></i>
                        Tentar Novamente
                    </button>
                </td>
            </tr>
        `;
    }
    
    /**
     * Renderiza paginação
     */
    renderPagination(pagination) {
        const container = document.getElementById('pagination');
        
        if (pagination.last_page <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let pages = [];
        
        // Primeira página
        if (pagination.current_page > 2) {
            pages.push(`<button class="page-btn" onclick="crm.goToPage(1)">1</button>`);
            if (pagination.current_page > 3) {
                pages.push(`<span class="page-dots">...</span>`);
            }
        }
        
        // Páginas ao redor da atual
        for (let i = Math.max(1, pagination.current_page - 1); i <= Math.min(pagination.last_page, pagination.current_page + 1); i++) {
            pages.push(`
                <button class="page-btn ${i === pagination.current_page ? 'active' : ''}" 
                        onclick="crm.goToPage(${i})">
                    ${i}
                </button>
            `);
        }
        
        // Última página
        if (pagination.current_page < pagination.last_page - 1) {
            if (pagination.current_page < pagination.last_page - 2) {
                pages.push(`<span class="page-dots">...</span>`);
            }
            pages.push(`<button class="page-btn" onclick="crm.goToPage(${pagination.last_page})">${pagination.last_page}</button>`);
        }
        
        container.innerHTML = `
            <button class="page-btn" onclick="crm.goToPage(${pagination.current_page - 1})" 
                    ${!pagination.has_prev ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i>
            </button>
            ${pages.join('')}
            <button class="page-btn" onclick="crm.goToPage(${pagination.current_page + 1})" 
                    ${!pagination.has_next ? 'disabled' : ''}>
                <i class="fas fa-chevron-right"></i>
            </button>
        `;
    }
    
    /**
     * Atualiza informações de paginação
     */
    updatePaginationInfo() {
        const start = (this.currentPage - 1) * this.pageSize + 1;
        const end = Math.min(this.currentPage * this.pageSize, this.totalClients);
        
        document.getElementById('paginationInfo').textContent = 
            `Mostrando ${start}-${end} de ${this.totalClients} clientes`;
    }
    
    /**
     * Navega para página específica
     */
    goToPage(page) {
        if (page < 1 || page === this.currentPage) return;
        
        this.currentPage = page;
        this.loadClients();
    }
    
    /**
     * Busca clientes
     */
    searchClients(query) {
        this.filters.search = query;
        this.currentPage = 1;
        this.debounceSearch();
    }
    
    /**
     * Debounce para busca
     */
    debounceSearch = ui.debounce(() => {
        this.loadClients();
    }, 500);
    
    /**
     * Filtra clientes
     */
    filterClients() {
        this.filters.status = document.getElementById('statusFilter').value;
        this.filters.dateFrom = document.getElementById('dateFromFilter').value;
        
        this.currentPage = 1;
        this.loadClients();
    }
    
    /**
     * Limpa filtros
     */
    clearFilters() {
        document.getElementById('searchInput').value = '';
        document.getElementById('statusFilter').value = '';
        document.getElementById('dateFromFilter').value = '';
        
        this.filters = { search: '', status: '', dateFrom: '' };
        this.currentPage = 1;
        this.loadClients();
    }
    
    /**
     * Abre modal de cliente
     */
    openClientModal(clientId = null) {
        this.editingClient = clientId;
        
        const modal = document.getElementById('clientModal');
        const title = document.getElementById('clientModalTitle');
        const form = document.getElementById('clientForm');
        
        if (clientId) {
            title.textContent = 'Editar Cliente';
            this.loadClientData(clientId);
        } else {
            title.textContent = 'Novo Cliente';
            form.reset();
        }
        
        ui.openModal('clientModal');
    }
    
    /**
     * Carrega dados do cliente para edição
     */
    async loadClientData(clientId) {
        try {
            const response = await api.getClient(clientId);
            const client = response.data;
            
            // Preencher formulário
            document.getElementById('clientName').value = client.name || '';
            document.getElementById('clientEmail').value = client.email || '';
            document.getElementById('clientPhone').value = client.phone || '';
            document.getElementById('clientDocument').value = client.document || '';
            document.getElementById('clientCompany').value = client.company_name || '';
            document.getElementById('clientStreet').value = client.address?.street || '';
            document.getElementById('clientCity').value = client.address?.city || '';
            document.getElementById('clientState').value = client.address?.state || '';
            document.getElementById('clientZipCode').value = client.address?.zip_code || '';
            document.getElementById('clientNotes').value = client.notes || '';
            
        } catch (error) {
            console.error('Erro ao carregar cliente:', error);
            ui.showToast('Erro ao carregar dados do cliente', 'error');
        }
    }
    
    /**
     * Fecha modal de cliente
     */
    closeClientModal() {
        ui.closeModal(document.getElementById('clientModal').querySelector('.modal'));
        this.editingClient = null;
    }
    
    /**
     * Salva cliente
     */
    async saveClient(event) {
        event.preventDefault();
        
        const form = event.target;
        const formData = new FormData(form);
        
        // Validar formulário
        if (!ui.validateForm(form)) {
            return;
        }
        
        // Preparar dados
        const clientData = {
            name: formData.get('name'),
            email: formData.get('email'),
            phone: formData.get('phone'),
            document: formData.get('document'),
            company_name: formData.get('company_name'),
            address: {
                street: formData.get('address.street'),
                city: formData.get('address.city'),
                state: formData.get('address.state'),
                zip_code: formData.get('address.zip_code')
            },
            notes: formData.get('notes')
        };
        
        const saveBtn = document.getElementById('saveClientBtn');
        ui.setButtonLoading(saveBtn, true);
        
        try {
            if (this.editingClient) {
                await api.updateClient(this.editingClient, clientData);
                ui.showToast('Cliente atualizado com sucesso!', 'success');
            } else {
                await api.createClient(clientData);
                ui.showToast('Cliente cadastrado com sucesso!', 'success');
            }
            
            this.closeClientModal();
            this.loadClients();
            this.loadStats();
            
        } catch (error) {
            console.error('Erro ao salvar cliente:', error);
            
            if (error.isValidationError()) {
                const errors = error.getValidationErrors();
                this.showFormErrors(form, errors);
            } else {
                ui.showToast(error.message || 'Erro ao salvar cliente', 'error');
            }
        } finally {
            ui.setButtonLoading(saveBtn, false);
        }
    }
    
    /**
     * Mostra erros de validação no formulário
     */
    showFormErrors(form, errors) {
        // Limpar erros anteriores
        form.querySelectorAll('.field-error').forEach(error => error.remove());
        form.querySelectorAll('.error').forEach(field => field.classList.remove('error'));
        
        // Mostrar novos erros
        Object.keys(errors).forEach(fieldName => {
            const field = form.querySelector(`[name="${fieldName}"]`);
            if (field) {
                ui.showFieldError(field, errors[fieldName][0]);
            }
        });
    }
    
    /**
     * Visualiza cliente
     */
    async viewClient(clientId) {
        try {
            const response = await api.getClient(clientId);
            const client = response.data;
            
            // Criar modal de visualização
            const modal = ui.createElement('div', 'modal-overlay', `
                <div class="modal">
                    <div class="modal-header">
                        <h3>Detalhes do Cliente</h3>
                        <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="client-details-grid">
                            <div class="detail-item">
                                <label>Nome:</label>
                                <span>${client.name}</span>
                            </div>
                            <div class="detail-item">
                                <label>E-mail:</label>
                                <span>${client.email}</span>
                            </div>
                            <div class="detail-item">
                                <label>Telefone:</label>
                                <span>${client.phone || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <label>CPF/CNPJ:</label>
                                <span>${client.document || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <label>Empresa:</label>
                                <span>${client.company_name || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <label>Status:</label>
                                <span class="status-badge status-${client.status}">
                                    ${this.getStatusLabel(client.status)}
                                </span>
                            </div>
                            <div class="detail-item">
                                <label>Total de Compras:</label>
                                <span>${client.total_purchases || 0}</span>
                            </div>
                            <div class="detail-item">
                                <label>Total Gasto:</label>
                                <span>${ui.formatCurrency(client.total_spent || 0)}</span>
                            </div>
                            <div class="detail-item full-width">
                                <label>Endereço:</label>
                                <span>${this.formatAddress(client.address)}</span>
                            </div>
                            ${client.notes ? `
                            <div class="detail-item full-width">
                                <label>Observações:</label>
                                <span>${client.notes}</span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline" onclick="this.closest('.modal-overlay').remove()">
                            Fechar
                        </button>
                        <button class="btn btn-primary" onclick="editClient(${client.id}); this.closest('.modal-overlay').remove();">
                            <i class="fas fa-edit"></i>
                            Editar
                        </button>
                    </div>
                </div>
            `);
            
            document.body.appendChild(modal);
            ui.openModal(modal.id || 'clientViewModal');
            
        } catch (error) {
            console.error('Erro ao visualizar cliente:', error);
            ui.showToast('Erro ao carregar dados do cliente', 'error');
        }
    }
    
    /**
     * Edita cliente
     */
    editClient(clientId) {
        this.openClientModal(clientId);
    }
    
    /**
     * Exclui cliente
     */
    async deleteClient(clientId) {
        const client = this.clients.find(c => c.id === clientId);
        if (!client) return;
        
        const confirmed = await ui.confirm(
            `Tem certeza que deseja excluir o cliente "${client.name}"?`,
            'Confirmar Exclusão'
        );
        
        if (!confirmed) return;
        
        try {
            await api.deleteClient(clientId);
            ui.showToast('Cliente excluído com sucesso!', 'success');
            
            this.loadClients();
            this.loadStats();
            
        } catch (error) {
            console.error('Erro ao excluir cliente:', error);
            ui.showToast('Erro ao excluir cliente', 'error');
        }
    }
    
    /**
     * Exporta clientes
     */
    async exportClients() {
        try {
            ui.showToast('Iniciando exportação...', 'info');
            
            const response = await api.get('/crm/clients/export', {
                format: 'csv',
                ...this.filters
            });
            
            // Criar download
            const blob = new Blob([response.data], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `clientes_${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            ui.showToast('Exportação concluída!', 'success');
            
        } catch (error) {
            console.error('Erro ao exportar:', error);
            ui.showToast('Erro na exportação', 'error');
        }
    }
    
    /**
     * Seleção de clientes
     */
    toggleClientSelection(clientId) {
        const index = this.selectedClients.indexOf(clientId);
        if (index > -1) {
            this.selectedClients.splice(index, 1);
        } else {
            this.selectedClients.push(clientId);
        }
        
        this.updateSelectAllCheckbox();
    }
    
    /**
     * Selecionar todos
     */
    toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('#clientsTable tbody input[type="checkbox"]');
        
        if (selectAll.checked) {
            this.selectedClients = this.clients.map(client => client.id);
            checkboxes.forEach(cb => cb.checked = true);
        } else {
            this.selectedClients = [];
            checkboxes.forEach(cb => cb.checked = false);
        }
    }
    
    /**
     * Atualiza checkbox selecionar todos
     */
    updateSelectAllCheckbox() {
        const selectAll = document.getElementById('selectAll');
        const total = this.clients.length;
        const selected = this.selectedClients.length;
        
        selectAll.checked = selected === total && total > 0;
        selectAll.indeterminate = selected > 0 && selected < total;
    }
    
    /**
     * Event listeners
     */
    setupEventListeners() {
        // CEP auto-complete
        const zipCodeInput = document.getElementById('clientZipCode');
        if (zipCodeInput) {
            zipCodeInput.addEventListener('blur', this.autoCompleteAddress.bind(this));
        }
        
        // Máscaras de input
        this.setupInputMasks();
    }
    
    /**
     * Auto-complete de endereço por CEP
     */
    async autoCompleteAddress(event) {
        const zipCode = event.target.value.replace(/\D/g, '');
        
        if (zipCode.length !== 8) return;
        
        try {
            const response = await fetch(`https://viacep.com.br/ws/${zipCode}/json/`);
            const data = await response.json();
            
            if (!data.erro) {
                document.getElementById('clientStreet').value = data.logradouro || '';
                document.getElementById('clientCity').value = data.localidade || '';
                document.getElementById('clientState').value = data.uf || '';
            }
        } catch (error) {
            console.error('Erro ao buscar CEP:', error);
        }
    }
    
    /**
     * Configurar máscaras de input
     */
    setupInputMasks() {
        // Máscara de telefone
        const phoneInput = document.getElementById('clientPhone');
        if (phoneInput) {
            phoneInput.addEventListener('input', (e) => {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length <= 11) {
                    value = value.replace(/(\d{2})(\d{4,5})(\d{4})/, '($1) $2-$3');
                }
                e.target.value = value;
            });
        }
        
        // Máscara de CEP
        const zipCodeInput = document.getElementById('clientZipCode');
        if (zipCodeInput) {
            zipCodeInput.addEventListener('input', (e) => {
                let value = e.target.value.replace(/\D/g, '');
                value = value.replace(/(\d{5})(\d{3})/, '$1-$2');
                e.target.value = value;
            });
        }
        
        // Máscara de CPF/CNPJ
        const documentInput = document.getElementById('clientDocument');
        if (documentInput) {
            documentInput.addEventListener('input', (e) => {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length <= 11) {
                    // CPF
                    value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                } else {
                    // CNPJ
                    value = value.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
                }
                e.target.value = value;
            });
        }
    }
    
    /**
     * Utilitários
     */
    getClientInitials(name) {
        return name.split(' ')
            .map(word => word.charAt(0))
            .slice(0, 2)
            .join('')
            .toUpperCase();
    }
    
    getStatusLabel(status) {
        const labels = {
            'active': 'Ativo',
            'inactive': 'Inativo',
            'blocked': 'Bloqueado'
        };
        return labels[status] || status;
    }
    
    formatAddress(address) {
        if (!address) return '-';
        
        const parts = [
            address.street,
            address.city,
            address.state,
            address.zip_code
        ].filter(Boolean);
        
        return parts.join(', ') || '-';
    }
    
    showTableLoading(show) {
        const tbody = document.querySelector('#clientsTable tbody');
        if (show) {
            tbody.innerHTML = `
                <tr class="loading-row">
                    <td colspan="8">
                        <div class="table-loading">
                            <i class="fas fa-spinner fa-spin"></i>
                            Carregando clientes...
                        </div>
                    </td>
                </tr>
            `;
        }
    }
}

// Funções globais
function openClientModal(clientId = null) {
    crm.openClientModal(clientId);
}

function closeClientModal() {
    crm.closeClientModal();
}

function saveClient(event) {
    return crm.saveClient(event);
}

function searchClients(query) {
    crm.searchClients(query);
}

function filterClients() {
    crm.filterClients();
}

function clearFilters() {
    crm.clearFilters();
}

function viewClient(clientId) {
    crm.viewClient(clientId);
}

function editClient(clientId) {
    crm.editClient(clientId);
}

function deleteClient(clientId) {
    crm.deleteClient(clientId);
}

function exportClients() {
    crm.exportClients();
}

function toggleClientSelection(clientId) {
    crm.toggleClientSelection(clientId);
}

function toggleSelectAll() {
    crm.toggleSelectAll();
}

function logout() {
    if (confirm('Tem certeza que deseja sair do sistema?')) {
        api.logout();
    }
}

// Inicializar quando página carregar
document.addEventListener('DOMContentLoaded', () => {
    window.crm = new CRMManager();
});