/**
 * PERFORMANCE DASHBOARD - Sistema de Monitoramento em Tempo Real
 * 
 * Dashboard interativo com IA e Machine Learning
 */

class PerformanceDashboard {
    constructor() {
        this.charts = {};
        this.updateInterval = 5000; // 5 segundos
        this.data = {
            performance: [],
            queries: [],
            resources: [],
            alerts: []
        };
        
        this.init();
    }
    
    init() {
        this.initializeCharts();
        this.startRealTimeUpdates();
        this.setupEventListeners();
        
        console.log('🚀 Performance Dashboard iniciado');
    }
    
    /**
     * Inicializar todos os gráficos
     */
    initializeCharts() {
        // Gráfico de Performance ao longo do tempo
        const performanceCtx = document.getElementById('performanceChart').getContext('2d');
        this.charts.performance = new Chart(performanceCtx, {
            type: 'line',
            data: {
                labels: this.generateTimeLabels(20),
                datasets: [{
                    label: 'Score de Performance',
                    data: this.generatePerformanceData(20),
                    borderColor: '#4299e1',
                    backgroundColor: 'rgba(66, 153, 225, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Tempo de Resposta (ms)',
                    data: this.generateResponseTimeData(20),
                    borderColor: '#48bb78',
                    backgroundColor: 'rgba(72, 187, 120, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Performance Score'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Response Time (ms)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                }
            }
        });
        
        // Gráfico de Distribuição de Queries
        const queriesCtx = document.getElementById('queriesChart').getContext('2d');
        this.charts.queries = new Chart(queriesCtx, {
            type: 'doughnut',
            data: {
                labels: ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'Cache Hits'],
                datasets: [{
                    data: [65, 12, 15, 3, 85],
                    backgroundColor: [
                        '#4299e1',
                        '#48bb78',
                        '#ed8936',
                        '#f56565',
                        '#9f7aea'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Gráfico de Uso de Recursos
        const resourcesCtx = document.getElementById('resourcesChart').getContext('2d');
        this.charts.resources = new Chart(resourcesCtx, {
            type: 'bar',
            data: {
                labels: ['CPU', 'Memória', 'Disco', 'Rede', 'Cache'],
                datasets: [{
                    label: 'Uso Atual (%)',
                    data: [45, 62, 28, 35, 78],
                    backgroundColor: [
                        '#4299e1',
                        '#48bb78',
                        '#ed8936',
                        '#9f7aea',
                        '#f56565'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Percentual de Uso'
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Iniciar atualizações em tempo real
     */
    startRealTimeUpdates() {
        this.updateData();
        
        setInterval(() => {
            this.updateData();
        }, this.updateInterval);
        
        console.log(`📊 Atualizações automáticas iniciadas (${this.updateInterval/1000}s)`);
    }
    
    /**
     * Atualizar dados do dashboard
     */
    async updateData() {
        this.showRefreshIndicator();
        
        try {
            // Simular chamada à API de performance
            const data = await this.fetchPerformanceData();
            
            // Atualizar métricas principais
            this.updateMainMetrics(data);
            
            // Atualizar gráficos
            this.updateCharts(data);
            
            // Atualizar alertas
            this.updateAlerts(data.alerts);
            
            // Atualizar timestamp
            this.updateLastUpdateTime();
            
        } catch (error) {
            console.error('Erro ao atualizar dados:', error);
            this.showError('Erro ao carregar dados de performance');
        } finally {
            this.hideRefreshIndicator();
        }
    }
    
    /**
     * Simular dados da API (substitua por chamada real)
     */
    async fetchPerformanceData() {
        // Simular delay da API
        await new Promise(resolve => setTimeout(resolve, 500));
        
        return {
            performanceScore: this.generateRandomValue(85, 98),
            responseTime: this.generateRandomValue(30, 80),
            memoryUsage: this.generateRandomValue(100, 200),
            cacheHitRate: this.generateRandomValue(92, 99),
            queriesPerSecond: this.generateRandomValue(1000, 1500),
            activeUsers: this.generateRandomValue(2000, 3000),
            throughput: this.generateRandomValue(700, 1000),
            alerts: this.generateAlerts(),
            predictions: this.generateMLPredictions(),
            autoActions: this.generateAutoActions()
        };
    }
    
    /**
     * Atualizar métricas principais
     */
    updateMainMetrics(data) {
        // Performance Score
        const scoreElement = document.getElementById('performanceScore');
        const statusElement = document.getElementById('performanceStatus');
        
        scoreElement.textContent = Math.round(data.performanceScore);
        
        // Determinar status baseado no score
        let status, statusClass;
        if (data.performanceScore >= 90) {
            status = 'Performance Excelente - Sistema Otimizado';
            statusClass = 'status-excellent';
        } else if (data.performanceScore >= 80) {
            status = 'Performance Boa - Funcionando Bem';
            statusClass = 'status-good';
        } else if (data.performanceScore >= 70) {
            status = 'Performance Regular - Atenção Necessária';
            statusClass = 'status-warning';
        } else {
            status = 'Performance Crítica - Ação Imediata';
            statusClass = 'status-critical';
        }
        
        statusElement.innerHTML = `<span class="status-indicator ${statusClass}"></span>${status}`;
        
        // Outras métricas
        document.getElementById('responseTime').textContent = `${Math.round(data.responseTime)}ms`;
        document.getElementById('memoryUsage').textContent = `${Math.round(data.memoryUsage)}MB`;
        document.getElementById('cacheHitRate').textContent = `${data.cacheHitRate.toFixed(1)}%`;
        document.getElementById('queriesPerSec').textContent = this.formatNumber(data.queriesPerSecond);
        document.getElementById('activeUsers').textContent = this.formatNumber(data.activeUsers);
        document.getElementById('throughput').textContent = `${Math.round(data.throughput)} req/s`;
        
        // Atualizar indicadores de mudança (simulado)
        this.updateChangeIndicators();
    }
    
    /**
     * Atualizar gráficos
     */
    updateCharts(data) {
        // Atualizar gráfico de performance
        const performanceChart = this.charts.performance;
        const newTime = new Date().toLocaleTimeString('pt-BR', { 
            hour: '2-digit', 
            minute: '2-digit' 
        });
        
        // Adicionar novo ponto
        performanceChart.data.labels.push(newTime);
        performanceChart.data.datasets[0].data.push(data.performanceScore);
        performanceChart.data.datasets[1].data.push(data.responseTime);
        
        // Manter apenas últimos 20 pontos
        if (performanceChart.data.labels.length > 20) {
            performanceChart.data.labels.shift();
            performanceChart.data.datasets[0].data.shift();
            performanceChart.data.datasets[1].data.shift();
        }
        
        performanceChart.update('none');
        
        // Atualizar gráfico de recursos com dados aleatórios
        const resourcesChart = this.charts.resources;
        resourcesChart.data.datasets[0].data = [
            this.generateRandomValue(30, 60),  // CPU
            this.generateRandomValue(50, 80),  // Memória
            this.generateRandomValue(20, 40),  // Disco
            this.generateRandomValue(25, 50),  // Rede
            this.generateRandomValue(70, 90)   // Cache
        ];
        resourcesChart.update('none');
        
        // Atualizar gráfico de queries com dados aleatórios
        const queriesChart = this.charts.queries;
        queriesChart.data.datasets[0].data = [
            this.generateRandomValue(60, 70),  // SELECT
            this.generateRandomValue(10, 15),  // INSERT
            this.generateRandomValue(12, 18),  // UPDATE
            this.generateRandomValue(2, 5),    // DELETE
            this.generateRandomValue(80, 95)   // Cache Hits
        ];
        queriesChart.update('none');
    }
    
    /**
     * Atualizar alertas
     */
    updateAlerts(alerts) {
        const alertsList = document.getElementById('alertsList');
        
        if (alerts.length === 0) {
            alertsList.innerHTML = `
                <div class="alert-item alert-info">
                    <span>✅</span>
                    <div>
                        <strong>Sistema Saudável</strong><br>
                        <small>Nenhum alerta ativo no momento</small>
                    </div>
                </div>
            `;
            return;
        }
        
        alertsList.innerHTML = alerts.map(alert => `
            <div class="alert-item alert-${alert.type}">
                <span>${alert.icon}</span>
                <div>
                    <strong>${alert.title}</strong><br>
                    <small>${alert.message}</small>
                </div>
            </div>
        `).join('');
    }
    
    /**
     * Configurar event listeners
     */
    setupEventListeners() {
        // Clique para atualização manual
        document.addEventListener('keydown', (e) => {
            if (e.key === 'F5' || (e.ctrlKey && e.key === 'r')) {
                e.preventDefault();
                this.updateData();
            }
        });
        
        // Visibilidade da página
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                console.log('📊 Dashboard pausado (página não visível)');
            } else {
                console.log('📊 Dashboard retomado');
                this.updateData();
            }
        });
    }
    
    /**
     * Utilitários
     */
    generateTimeLabels(count) {
        const labels = [];
        const now = new Date();
        
        for (let i = count - 1; i >= 0; i--) {
            const time = new Date(now.getTime() - (i * 30000)); // 30 segundos
            labels.push(time.toLocaleTimeString('pt-BR', { 
                hour: '2-digit', 
                minute: '2-digit' 
            }));
        }
        
        return labels;
    }
    
    generatePerformanceData(count) {
        const data = [];
        let base = 95;
        
        for (let i = 0; i < count; i++) {
            base += (Math.random() - 0.5) * 10;
            base = Math.max(70, Math.min(100, base));
            data.push(Math.round(base));
        }
        
        return data;
    }
    
    generateResponseTimeData(count) {
        const data = [];
        let base = 45;
        
        for (let i = 0; i < count; i++) {
            base += (Math.random() - 0.5) * 20;
            base = Math.max(20, Math.min(100, base));
            data.push(Math.round(base));
        }
        
        return data;
    }
    
    generateRandomValue(min, max) {
        return Math.random() * (max - min) + min;
    }
    
    generateAlerts() {
        const possibleAlerts = [
            {
                type: 'warning',
                icon: '⚠️',
                title: 'Uso de Memória Elevado',
                message: 'Uso de memória em 78% - monitorando'
            },
            {
                type: 'info',
                icon: 'ℹ️',
                title: 'Cache Warming Executado',
                message: 'Cache pré-aquecido com dados críticos'
            },
            {
                type: 'critical',
                icon: '🚨',
                title: 'Query Lenta Detectada',
                message: 'Query de relatório levou 2.3s para executar'
            }
        ];
        
        // Retornar 0-2 alertas aleatórios
        const alertCount = Math.floor(Math.random() * 3);
        const selectedAlerts = [];
        
        for (let i = 0; i < alertCount; i++) {
            const randomAlert = possibleAlerts[Math.floor(Math.random() * possibleAlerts.length)];
            if (!selectedAlerts.some(a => a.title === randomAlert.title)) {
                selectedAlerts.push(randomAlert);
            }
        }
        
        return selectedAlerts;
    }
    
    generateMLPredictions() {
        return [
            { text: 'Pico de carga previsto', value: '14:30 (+45min)' },
            { text: 'Aumento de memória', value: '+23% em 2h' },
            { text: 'Cache warming recomendado', value: 'Agora' }
        ];
    }
    
    generateAutoActions() {
        return [
            { text: 'Cache warming executado', status: 'completed' },
            { text: 'Connection pool otimizado', status: 'completed' },
            { text: 'Garbage collection executado', status: 'completed' },
            { text: 'Preload de dados críticos', status: 'in_progress' }
        ];
    }
    
    formatNumber(num) {
        return num.toLocaleString('pt-BR');
    }
    
    updateChangeIndicators() {
        // Simular mudanças positivas/negativas
        const indicators = [
            'responseTimeChange',
            'memoryChange',
            'cacheChange', 
            'queriesChange',
            'usersChange',
            'throughputChange'
        ];
        
        indicators.forEach(id => {
            const element = document.getElementById(id);
            const isPositive = Math.random() > 0.3; // 70% chance de ser positivo
            const change = Math.floor(Math.random() * 25) + 1;
            const arrow = isPositive ? '↑' : '↓';
            
            element.textContent = `${isPositive ? '+' : '-'}${change}% ${arrow}`;
            element.className = `metric-change ${isPositive ? 'positive' : 'negative'}`;
        });
    }
    
    updateLastUpdateTime() {
        const now = new Date();
        document.getElementById('lastUpdate').textContent = now.toLocaleTimeString('pt-BR');
    }
    
    showRefreshIndicator() {
        document.getElementById('refreshIndicator').style.display = 'block';
    }
    
    hideRefreshIndicator() {
        document.getElementById('refreshIndicator').style.display = 'none';
    }
    
    showError(message) {
        console.error(message);
        // Implementar notificação de erro se necessário
    }
}

// Inicializar dashboard quando DOM estiver pronto
document.addEventListener('DOMContentLoaded', () => {
    window.performanceDashboard = new PerformanceDashboard();
});

// Função global para API externa
window.updatePerformanceData = (data) => {
    if (window.performanceDashboard) {
        window.performanceDashboard.updateMainMetrics(data);
    }
};