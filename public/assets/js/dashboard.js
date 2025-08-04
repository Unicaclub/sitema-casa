/**
 * Dashboard - Sistema ERP
 * Gerencia dados reais do dashboard conectado à API
 */
class Dashboard {
    constructor() {
        this.currentPeriod = 30;
        this.charts = {};
        this.refreshInterval = null;
        this.init();
    }
    
    async init() {
        try {
            await this.loadUserInfo();
            await this.loadDashboardData();
            this.setupEventListeners();
            this.startAutoRefresh();
        } catch (error) {
            console.error('Erro ao inicializar dashboard:', error);
            this.showError('Erro ao carregar dashboard. Tente novamente.');
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
     * Carrega todos os dados do dashboard
     */
    async loadDashboardData() {
        this.showLoading(true);
        
        try {
            // Carregar dados em paralelo
            const [metricsData, chartsData, topProductsData, activitiesData, alertsData] = await Promise.all([
                api.getDashboardMetrics(this.currentPeriod),
                api.getSalesChart('monthly', 12),
                api.getTopProducts(5, this.currentPeriod),
                api.getRecentActivities(10),
                api.getAlerts()
            ]);
            
            // Atualizar interface
            this.updateMetrics(metricsData.data);
            this.updateSalesChart(chartsData.data);
            this.updateTopProducts(topProductsData.data);
            this.updateRecentActivities(activitiesData.data);
            this.updateAlerts(alertsData.data);
            
        } catch (error) {
            console.error('Erro ao carregar dados:', error);
            this.showError('Erro ao carregar dados do dashboard.');
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Atualiza métricas principais
     */
    updateMetrics(data) {
        // Vendas
        document.getElementById('totalSales').textContent = this.formatNumber(data.sales.total);
        
        // Faturamento
        document.getElementById('totalRevenue').textContent = this.formatCurrency(data.sales.revenue);
        
        // Clientes
        document.getElementById('totalClients').textContent = this.formatNumber(data.clients.total);
        
        // Saldo
        document.getElementById('balance').textContent = this.formatCurrency(data.financial.balance);
        
        // Mudanças (calculadas baseado nos dados)
        this.updateMetricChanges(data);
        
        // Animação dos números
        this.animateNumbers();
    }
    
    /**
     * Calcula e atualiza mudanças nas métricas
     */
    updateMetricChanges(data) {
        // Simular mudanças baseado nos dados atuais vs hoje
        const salesGrowth = data.sales.today_sales > 0 ? 
            ((data.sales.today_sales / (data.sales.total / this.currentPeriod)) - 1) * 100 : 0;
        
        const revenueGrowth = data.sales.today_revenue > 0 ? 
            ((data.sales.today_revenue / (data.sales.revenue / this.currentPeriod)) - 1) * 100 : 0;
        
        const clientsNew = data.clients.new;
        const balanceChange = data.financial.income > data.financial.expense ? 
            ((data.financial.income - data.financial.expense) / data.financial.expense) * 100 : 0;
        
        this.updateChangeIndicator('salesChange', salesGrowth, '%');
        this.updateChangeIndicator('revenueChange', revenueGrowth, '%');
        this.updateChangeIndicator('clientsChange', clientsNew, '', 'number');
        this.updateChangeIndicator('balanceChange', balanceChange, '%');
    }
    
    /**
     * Atualiza indicador de mudança
     */
    updateChangeIndicator(elementId, value, suffix, type = 'percentage') {
        const element = document.getElementById(elementId);
        const isPositive = value >= 0;
        
        element.className = `metric-change ${isPositive ? 'positive' : 'negative'}`;
        
        let displayValue;
        if (type === 'number') {
            displayValue = `+${Math.abs(value)}`;
        } else {
            displayValue = `${isPositive ? '+' : ''}${value.toFixed(1)}${suffix}`;
        }
        
        element.textContent = displayValue;
    }
    
    /**
     * Atualiza gráfico de vendas
     */
    updateSalesChart(data) {
        const ctx = document.getElementById('salesChart').getContext('2d');
        
        // Destruir gráfico anterior se existir
        if (this.charts.sales) {
            this.charts.sales.destroy();
        }
        
        this.charts.sales = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Vendas',
                        data: data.datasets[0].data,
                        borderColor: data.datasets[0].borderColor,
                        backgroundColor: data.datasets[0].backgroundColor,
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Faturamento (R$)',
                        data: data.datasets[1].data,
                        borderColor: data.datasets[1].borderColor,
                        backgroundColor: data.datasets[1].backgroundColor,
                        tension: 0.4,
                        fill: false,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                if (context.datasetIndex === 1) {
                                    return `${context.dataset.label}: R$ ${context.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;
                                }
                                return `${context.dataset.label}: ${context.parsed.y}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Período'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Quantidade de Vendas'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Faturamento (R$)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });
    }
    
    /**
     * Atualizar gráfico financeiro
     */
    async updateFinancialChart() {
        try {
            const currentMonth = new Date().toISOString().slice(0, 7);
            const financialData = await api.getFinancialSummary(currentMonth);
            
            const ctx = document.getElementById('financialChart').getContext('2d');
            
            if (this.charts.financial) {
                this.charts.financial.destroy();
            }
            
            this.charts.financial = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['A Receber', 'A Pagar', 'Recebido', 'Pago'],
                    datasets: [{
                        data: [
                            financialData.data.receivable.pending_amount,
                            financialData.data.payable.pending_amount,
                            financialData.data.receivable.paid_amount,
                            financialData.data.payable.paid_amount
                        ],
                        backgroundColor: [
                            '#4ade80',
                            '#f87171',
                            '#22c55e',
                            '#ef4444'
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.label}: R$ ${context.parsed.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;
                                }
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Erro ao carregar dados financeiros:', error);
        }
    }
    
    /**
     * Atualiza tabela de top produtos
     */
    updateTopProducts(data) {
        const tbody = document.querySelector('#topProductsTable tbody');
        
        if (!data || data.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="3" class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <p>Nenhum produto encontrado</p>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = data.map(product => `
            <tr>
                <td>
                    <div class="product-info">
                        <strong>${product.name}</strong>
                        <small>${product.sku}</small>
                    </div>
                </td>
                <td>
                    <span class="quantity-badge">${product.total_quantity}</span>
                </td>
                <td>
                    <span class="revenue-amount">${this.formatCurrency(product.total_revenue)}</span>
                </td>
            </tr>
        `).join('');
    }
    
    /**
     * Atualiza atividades recentes
     */
    updateRecentActivities(data) {
        const container = document.getElementById('activitiesContainer');
        
        if (!data || data.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>Nenhuma atividade recente</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = data.map(activity => `
            <div class="activity-item">
                <div class="activity-icon ${this.getActivityIconClass(activity.type)}">
                    <i class="${this.getActivityIcon(activity.type)}"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-title">${this.getActivityTitle(activity)}</div>
                    <div class="activity-time">${this.formatDateTime(activity.created_at)}</div>
                </div>
            </div>
        `).join('');
    }
    
    /**
     * Atualiza alertas do sistema
     */
    updateAlerts(data) {
        const alertsSection = document.getElementById('alertsSection');
        const alertsContainer = document.getElementById('alertsContainer');
        
        if (!data || data.length === 0) {
            alertsSection.style.display = 'none';
            return;
        }
        
        alertsSection.style.display = 'block';
        alertsContainer.innerHTML = data.map(alert => `
            <div class="alert alert-${alert.type}">
                <div class="alert-icon">
                    <i class="${this.getAlertIcon(alert.type)}"></i>
                </div>
                <div class="alert-content">
                    <strong>${alert.title}</strong>
                    <p>${alert.message}</p>
                </div>
                <div class="alert-actions">
                    ${alert.action ? `<a href="${alert.action}" class="btn btn-sm">Ver</a>` : ''}
                    <button class="btn btn-sm btn-ghost" onclick="dismissAlert(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }
    
    /**
     * Helpers para atividades
     */
    getActivityIcon(type) {
        const icons = {
            'sale': 'fas fa-shopping-cart',
            'client': 'fas fa-user-plus',
            'stock_alert': 'fas fa-exclamation-triangle',
            'payment': 'fas fa-money-bill-wave'
        };
        return icons[type] || 'fas fa-info-circle';
    }
    
    getActivityIconClass(type) {
        const classes = {
            'sale': 'success',
            'client': 'info',
            'stock_alert': 'warning',
            'payment': 'success'
        };
        return classes[type] || 'info';
    }
    
    getActivityTitle(activity) {
        switch (activity.type) {
            case 'sale':
                return `Nova venda #${activity.number} - ${this.formatCurrency(activity.total_amount)}`;
            case 'client':
                return `Novo cliente: ${activity.name}`;
            case 'stock_alert':
                return `Estoque baixo: ${activity.name} (${activity.current_stock} restantes)`;
            default:
                return 'Atividade do sistema';
        }
    }
    
    /**
     * Helpers para alertas
     */
    getAlertIcon(type) {
        const icons = {
            'danger': 'fas fa-exclamation-circle',
            'warning': 'fas fa-exclamation-triangle',
            'info': 'fas fa-info-circle',
            'success': 'fas fa-check-circle'
        };
        return icons[type] || 'fas fa-info-circle';
    }
    
    /**
     * Event listeners
     */
    setupEventListeners() {
        // Seletor de período
        document.getElementById('periodSelect').addEventListener('change', (e) => {
            this.currentPeriod = parseInt(e.target.value);
            this.loadDashboardData();
        });
        
        // Tipo de gráfico
        document.getElementById('chartType').addEventListener('change', (e) => {
            this.updateSalesChart();
        });
    }
    
    /**
     * Auto-refresh dos dados
     */
    startAutoRefresh() {
        // Atualizar a cada 5 minutos
        this.refreshInterval = setInterval(() => {
            this.loadDashboardData();
        }, 5 * 60 * 1000);
    }
    
    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }
    
    /**
     * Utilitários
     */
    formatNumber(value) {
        return new Intl.NumberFormat('pt-BR').format(value);
    }
    
    formatCurrency(value) {
        return new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        }).format(value);
    }
    
    formatDateTime(dateString) {
        return new Intl.DateTimeFormat('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        }).format(new Date(dateString));
    }
    
    showLoading(show) {
        const overlay = document.getElementById('loadingOverlay');
        overlay.style.display = show ? 'flex' : 'none';
    }
    
    showError(message) {
        this.showToast(message, 'error');
    }
    
    showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            </div>
            <button class="toast-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.appendChild(toast);
        
        // Auto-remover após 5 segundos
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 5000);
    }
    
    animateNumbers() {
        // Animação simples dos números das métricas
        const metricValues = document.querySelectorAll('.metric-value');
        metricValues.forEach(element => {
            element.style.animation = 'fadeInUp 0.6s ease';
        });
    }
}

// Funções globais
function refreshDashboard() {
    if (window.dashboard) {
        window.dashboard.loadDashboardData();
    }
}

function changePeriod() {
    // Já tratado no event listener
}

function updateSalesChart() {
    if (window.dashboard) {
        const type = document.getElementById('chartType').value;
        api.getSalesChart(type, 12).then(response => {
            window.dashboard.updateSalesChart(response.data);
        });
    }
}

function dismissAlert(button) {
    const alert = button.closest('.alert');
    if (alert) {
        alert.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => alert.remove(), 300);
    }
}

function logout() {
    if (confirm('Tem certeza que deseja sair do sistema?')) {
        api.logout();
    }
}

// Inicializar dashboard quando página carregar
document.addEventListener('DOMContentLoaded', () => {
    window.dashboard = new Dashboard();
});

// Limpar ao sair da página
window.addEventListener('beforeunload', () => {
    if (window.dashboard) {
        window.dashboard.stopAutoRefresh();
    }
});