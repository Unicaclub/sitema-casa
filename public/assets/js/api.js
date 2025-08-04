/**
 * Cliente API para Sistema ERP
 * Gerencia todas as comunicações com o backend
 */
class ERPApi {
    constructor() {
        this.baseUrl = '/api/v1';
        this.token = localStorage.getItem('erp_token');
        this.refreshToken = localStorage.getItem('erp_refresh_token');
        
        // Interceptar respostas para renovar token automaticamente
        this.setupInterceptors();
    }
    
    /**
     * Configura interceptadores de requisição/resposta
     */
    setupInterceptors() {
        // Interceptar fetch global
        const originalFetch = window.fetch;
        window.fetch = async (...args) => {
            let [url, config = {}] = args;
            
            // Adicionar headers padrão
            config.headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...config.headers
            };
            
            // Adicionar token se disponível
            if (this.token && url.includes('/api/')) {
                config.headers['Authorization'] = `Bearer ${this.token}`;
                
                // Adicionar CSRF token se necessário
                const csrfToken = this.getCSRFToken();
                if (csrfToken && ['POST', 'PUT', 'DELETE', 'PATCH'].includes(config.method?.toUpperCase())) {
                    config.headers['X-CSRF-TOKEN'] = csrfToken;
                }
            }
            
            try {
                const response = await originalFetch(url, config);
                
                // Se token expirou, tentar renovar
                if (response.status === 401 && this.refreshToken && url.includes('/api/')) {
                    const renewed = await this.renewToken();
                    if (renewed) {
                        // Repetir requisição com novo token
                        config.headers['Authorization'] = `Bearer ${this.token}`;
                        return await originalFetch(url, config);
                    } else {
                        this.logout();
                        throw new Error('Sessão expirada. Faça login novamente.');
                    }
                }
                
                return response;
            } catch (error) {
                console.error('Erro na requisição:', error);
                throw error;
            }
        };
    }
    
    /**
     * Realiza requisição HTTP
     */
    async request(endpoint, options = {}) {
        const url = endpoint.startsWith('http') ? endpoint : `${this.baseUrl}${endpoint}`;
        
        const config = {
            method: 'GET',
            ...options,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...options.headers
            }
        };
        
        // Converter body para JSON se necessário
        if (config.body && typeof config.body === 'object') {
            config.body = JSON.stringify(config.body);
        }
        
        try {
            const response = await fetch(url, config);
            const data = await response.json();
            
            if (!response.ok) {
                throw new ApiError(data.error?.message || 'Erro na requisição', response.status, data);
            }
            
            return data;
        } catch (error) {
            if (error instanceof ApiError) {
                throw error;
            }
            throw new ApiError('Erro de conexão', 0, { network: true });
        }
    }
    
    /**
     * Métodos de conveniência HTTP
     */
    async get(endpoint, params = {}) {
        const queryString = new URLSearchParams(params).toString();
        const url = queryString ? `${endpoint}?${queryString}` : endpoint;
        return this.request(url, { method: 'GET' });
    }
    
    async post(endpoint, data = {}) {
        return this.request(endpoint, {
            method: 'POST',
            body: data
        });
    }
    
    async put(endpoint, data = {}) {
        return this.request(endpoint, {
            method: 'PUT',
            body: data
        });
    }
    
    async delete(endpoint) {
        return this.request(endpoint, { method: 'DELETE' });
    }
    
    // =================== AUTENTICAÇÃO ===================
    
    /**
     * Realiza login
     */
    async login(email, password, twoFactorCode = null) {
        const data = { email, password };
        if (twoFactorCode) {
            data.two_factor_code = twoFactorCode;
        }
        
        const response = await this.request('/auth/login', {
            method: 'POST',
            body: data
        });
        
        // Armazenar tokens
        this.token = response.data.token;
        this.refreshToken = response.data.refresh_token;
        
        localStorage.setItem('erp_token', this.token);
        localStorage.setItem('erp_refresh_token', this.refreshToken);
        localStorage.setItem('erp_user', JSON.stringify(response.data.user));
        
        return response;
    }
    
    /**
     * Renova token
     */
    async renewToken() {
        try {
            const response = await this.request('/auth/refresh', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.refreshToken}`
                }
            });
            
            this.token = response.data.token;
            localStorage.setItem('erp_token', this.token);
            
            return true;
        } catch (error) {
            console.error('Erro ao renovar token:', error);
            return false;
        }
    }
    
    /**
     * Realiza logout
     */
    async logout() {
        try {
            await this.request('/auth/logout', { method: 'POST' });
        } catch (error) {
            console.error('Erro no logout:', error);
        } finally {
            // Limpar dados locais
            this.token = null;
            this.refreshToken = null;
            localStorage.removeItem('erp_token');
            localStorage.removeItem('erp_refresh_token');
            localStorage.removeItem('erp_user');
            
            // Redirecionar para login
            window.location.href = '/login.html';
        }
    }
    
    /**
     * Verifica se usuário está autenticado
     */
    isAuthenticated() {
        return !!this.token;
    }
    
    /**
     * Obtém usuário atual
     */
    getCurrentUser() {
        const userData = localStorage.getItem('erp_user');
        return userData ? JSON.parse(userData) : null;
    }
    
    /**
     * Obtém token CSRF
     */
    getCSRFToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }
    
    // =================== DASHBOARD ===================
    
    async getDashboardMetrics(period = 30) {
        return this.get('/dashboard/metrics', { period });
    }
    
    async getSalesChart(type = 'monthly', period = 12) {
        return this.get('/dashboard/sales-chart', { type, period });
    }
    
    async getTopProducts(limit = 10, period = 30) {
        return this.get('/dashboard/top-products', { limit, period });
    }
    
    async getRecentActivities(limit = 10) {
        return this.get('/dashboard/recent-activities', { limit });
    }
    
    async getAlerts() {
        return this.get('/dashboard/alerts');
    }
    
    async saveWidgets(widgets) {
        return this.post('/dashboard/widgets/save', { widgets });
    }
    
    // =================== CRM ===================
    
    async getClients(params = {}) {
        return this.get('/crm/clients', params);
    }
    
    async getClient(id) {
        return this.get(`/crm/clients/${id}`);
    }
    
    async createClient(clientData) {
        return this.post('/crm/clients', clientData);
    }
    
    async updateClient(id, clientData) {
        return this.put(`/crm/clients/${id}`, clientData);
    }
    
    async deleteClient(id) {
        return this.delete(`/crm/clients/${id}`);
    }
    
    // =================== ESTOQUE ===================
    
    async getProducts(params = {}) {
        return this.get('/stock/products', params);
    }
    
    async getProduct(id) {
        return this.get(`/stock/products/${id}`);
    }
    
    async createProduct(productData) {
        return this.post('/stock/products', productData);
    }
    
    async updateProduct(id, productData) {
        return this.put(`/stock/products/${id}`, productData);
    }
    
    async deleteProduct(id) {
        return this.delete(`/stock/products/${id}`);
    }
    
    async addStockMovement(movementData) {
        return this.post('/stock/movements', movementData);
    }
    
    async getStockMovements(params = {}) {
        return this.get('/stock/movements', params);
    }
    
    // =================== PDV ===================
    
    async getSales(params = {}) {
        return this.get('/pos/sales', params);
    }
    
    async getSale(id) {
        return this.get(`/pos/sales/${id}`);
    }
    
    async createSale(saleData) {
        return this.post('/pos/sales', saleData);
    }
    
    async cancelSale(id, reason, refundPayment = true) {
        return this.post(`/pos/sales/${id}/cancel`, {
            reason,
            refund_payment: refundPayment
        });
    }
    
    // =================== FINANCEIRO ===================
    
    async getReceivables(params = {}) {
        return this.get('/financial/receivables', params);
    }
    
    async createReceivable(receivableData) {
        return this.post('/financial/receivables', receivableData);
    }
    
    async addPayment(receivableId, paymentData) {
        return this.post(`/financial/receivables/${receivableId}/payments`, paymentData);
    }
    
    async getPayables(params = {}) {
        return this.get('/financial/payables', params);
    }
    
    async getCashFlow(startDate, endDate) {
        return this.get('/financial/cash-flow', {
            start_date: startDate,
            end_date: endDate
        });
    }
    
    // =================== RELATÓRIOS ===================
    
    async getSalesReport(params = {}) {
        return this.get('/reports/sales', { format: 'json', ...params });
    }
    
    async getFinancialReport(params = {}) {
        return this.get('/reports/financial', { ...params });
    }
    
    async getInventoryReport(params = {}) {
        return this.get('/reports/inventory', { ...params });
    }
    
    // =================== SISTEMA ===================
    
    async getCompanySettings() {
        return this.get('/system/company/settings');
    }
    
    async updateCompanySettings(settings) {
        return this.put('/system/company/settings', settings);
    }
    
    async uploadFile(file, type, referenceId = null) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', type);
        if (referenceId) {
            formData.append('reference_id', referenceId);
        }
        
        return this.request('/system/upload', {
            method: 'POST',
            headers: {
                // Não definir Content-Type para multipart/form-data
                'Accept': 'application/json',
                'Authorization': `Bearer ${this.token}`
            },
            body: formData
        });
    }
}

/**
 * Classe de erro customizada para API
 */
class ApiError extends Error {
    constructor(message, status = 0, details = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.details = details;
    }
    
    isNetworkError() {
        return this.details.network === true;
    }
    
    isValidationError() {
        return this.status === 422;
    }
    
    isUnauthorized() {
        return this.status === 401;
    }
    
    isForbidden() {
        return this.status === 403;
    }
    
    isNotFound() {
        return this.status === 404;
    }
    
    isRateLimited() {
        return this.status === 429;
    }
    
    getValidationErrors() {
        return this.details.error?.details || {};
    }
}

// Instância global da API
window.api = new ERPApi();

// Verificar autenticação ao carregar página
document.addEventListener('DOMContentLoaded', () => {
    const currentPath = window.location.pathname;
    const isLoginPage = currentPath.includes('login') || currentPath.includes('auth');
    
    if (!isLoginPage && !window.api.isAuthenticated()) {
        window.location.href = '/login.html';
    }
});