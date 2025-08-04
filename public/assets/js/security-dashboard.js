/**
 * ERP Security Dashboard - JavaScript Controller
 * 
 * Dashboard interativo para monitoramento de segurança enterprise
 */

class SecurityDashboard {
    constructor() {
        this.charts = {};
        this.updateInterval = 10000; // 10 segundos
        this.apiBaseUrl = '/api/security';
        this.isUpdating = false;
        
        this.init();
        this.startRealTimeUpdates();
    }
    
    async init() {
        console.log('🛡️ Inicializando Security Dashboard...');
        
        // Inicializar gráficos
        this.initializeCharts();
        
        // Carregar dados iniciais
        await this.loadInitialData();
        
        // Configurar event listeners
        this.setupEventListeners();
        
        console.log('✅ Security Dashboard inicializado com sucesso!');
    }
    
    initializeCharts() {
        // Gráfico de Threat Landscape
        const threatCtx = document.getElementById('threatChart').getContext('2d');
        this.charts.threats = new Chart(threatCtx, {
            type: 'doughnut',
            data: {
                labels: ['Bloqueadas', 'Detectadas', 'Investigando', 'Resolvidas'],
                datasets: [{
                    data: [45, 23, 12, 67],
                    backgroundColor: [
                        '#ff3b30',
                        '#ff9500', 
                        '#007aff',
                        '#00ff88'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#ffffff',
                            padding: 20,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });
        
        // Gráfico de Performance de Resposta
        const responseCtx = document.getElementById('responseChart').getContext('2d');
        this.charts.response = new Chart(responseCtx, {
            type: 'line',
            data: {
                labels: ['00:00', '04:00', '08:00', '12:00', '16:00', '20:00'],
                datasets: [{
                    label: 'Tempo de Resposta (min)',
                    data: [5, 3, 8, 12, 6, 4],
                    borderColor: '#00ff88',
                    backgroundColor: 'rgba(0, 255, 136, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#ffffff'
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#ffffff' },
                        grid: { color: 'rgba(255, 255, 255, 0.1)' }
                    },
                    y: {
                        ticks: { color: '#ffffff' },
                        grid: { color: 'rgba(255, 255, 255, 0.1)' }
                    }
                }
            }
        });
        
        // Gráfico de Zero Trust Score
        const trustCtx = document.getElementById('trustChart').getContext('2d');
        this.charts.trust = new Chart(trustCtx, {
            type: 'bar',
            data: {
                labels: ['0-25', '26-50', '51-75', '76-100'],
                datasets: [{
                    label: 'Distribuição Trust Score',
                    data: [5, 15, 35, 45],
                    backgroundColor: [
                        '#ff3b30',
                        '#ff9500',
                        '#007aff',
                        '#00ff88'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#ffffff'
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#ffffff' },
                        grid: { color: 'rgba(255, 255, 255, 0.1)' }
                    },
                    y: {
                        ticks: { color: '#ffffff' },
                        grid: { color: 'rgba(255, 255, 255, 0.1)' }
                    }
                }
            }
        });
        
        // Gráfico de AI Accuracy
        const aiCtx = document.getElementById('aiChart').getContext('2d');
        this.charts.ai = new Chart(aiCtx, {
            type: 'radar',
            data: {
                labels: ['Anomaly Detection', 'Threat Classification', 'Behavioral Analysis', 'Predictive', 'NLP Threat Intel'],
                datasets: [{
                    label: 'Accuracy %',
                    data: [94, 96, 91, 88, 93],
                    borderColor: '#00ff88',
                    backgroundColor: 'rgba(0, 255, 136, 0.2)',
                    pointBackgroundColor: '#00ff88'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#ffffff'
                        }
                    }
                },
                scales: {
                    r: {
                        angleLines: {
                            color: 'rgba(255, 255, 255, 0.2)'
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.2)'
                        },
                        pointLabels: {
                            color: '#ffffff',
                            font: {
                                size: 10
                            }
                        },
                        ticks: {
                            color: '#ffffff',
                            backdropColor: 'transparent'
                        }
                    }
                }
            }
        });
    }
    
    async loadInitialData() {
        try {
            // Carregar dados do SOC Dashboard
            const socData = await this.fetchAPI('/soc/dashboard');
            this.updateSOCMetrics(socData);
            
            // Carregar dados dos sistemas individuais
            await this.loadSystemsData();
            
        } catch (error) {
            console.error('Erro ao carregar dados iniciais:', error);
            this.showError('Erro ao carregar dados do dashboard');
        }
    }
    
    async loadSystemsData() {
        try {
            // Carregar dados em paralelo
            const [aiData, threatIntelData, zeroTrustData] = await Promise.all([
                this.fetchAPI('/ai/dashboard'),
                this.fetchAPI('/threat-intel/dashboard'),
                this.fetchAPI('/zero-trust/dashboard')
            ]);
            
            this.updateAIMetrics(aiData);
            this.updateThreatIntelMetrics(threatIntelData);
            this.updateZeroTrustMetrics(zeroTrustData);
            
        } catch (error) {
            console.error('Erro ao carregar dados dos sistemas:', error);
        }
    }
    
    updateSOCMetrics(data) {
        if (!data || !data.soc_status) return;
        
        const status = data.soc_status;
        const metrics = data.unified_metrics;
        
        // Atualizar métricas principais
        this.updateElement('incidents-today', status.incident_count_today);
        this.updateElement('security-score', `${this.calculateOverallScore(data)}/100`);
        this.updateElement('automation-rate', `${Math.round(data.automated_responses?.automation_rate * 100 || 87)}%`);
        
        // Atualizar threat level
        const threatLevel = status.threat_level || 'medium';
        const threatElement = document.getElementById('threat-level');
        if (threatElement) {
            threatElement.textContent = this.translateThreatLevel(threatLevel);
            threatElement.className = `threat-level threat-${threatLevel}`;
        }
    }
    
    updateAIMetrics(data) {
        if (!data || !data.real_time_metrics) return;
        
        const metrics = data.real_time_metrics;
        
        this.updateElement('ai-accuracy', `${(metrics.prediction_accuracy * 100).toFixed(1)}%`);
        this.updateElement('anomalies', metrics.anomalies_found);
        this.updateElement('active-models', Object.keys(data.ml_models_performance || {}).length);
    }
    
    updateThreatIntelMetrics(data) {
        if (!data || !data.intelligence_metrics) return;
        
        const metrics = data.intelligence_metrics;
        
        this.updateElement('ti-iocs', metrics.total_iocs?.toLocaleString() || '0');
        this.updateElement('ti-feeds', data.collection_status?.active_feeds || '0');
        this.updateElement('ti-campaigns', metrics.tracked_actors || '0');
    }
    
    updateZeroTrustMetrics(data) {
        if (!data || !data.trust_metrics) return;
        
        const metrics = data.trust_metrics;
        const access = data.access_analytics;
        
        this.updateElement('trust-score', Math.round(metrics.average_trust_score));
        this.updateElement('verifications', access?.verification_requests_today?.toLocaleString() || '0');
        this.updateElement('denied-access', access?.access_denials_today || '0');
    }
    
    updateElement(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
            
            // Efeito de update
            element.style.transition = 'all 0.3s ease';
            element.style.transform = 'scale(1.05)';
            setTimeout(() => {
                element.style.transform = 'scale(1)';
            }, 300);
        }
    }
    
    calculateOverallScore(data) {
        // Calcular score geral baseado em múltiplas métricas
        const scores = [];
        
        if (data.unified_metrics?.ai_monitoring?.real_time_metrics?.prediction_accuracy) {
            scores.push(data.unified_metrics.ai_monitoring.real_time_metrics.prediction_accuracy * 100);
        }
        
        if (data.unified_metrics?.zero_trust?.trust_metrics?.average_trust_score) {
            scores.push(data.unified_metrics.zero_trust.trust_metrics.average_trust_score);
        }
        
        // Score padrão se não houver dados
        if (scores.length === 0) return 96;
        
        return Math.round(scores.reduce((a, b) => a + b, 0) / scores.length);
    }
    
    translateThreatLevel(level) {
        const translations = {
            'low': 'Baixo',
            'medium': 'Médio', 
            'high': 'Alto',
            'critical': 'Crítico'
        };
        return translations[level] || level;
    }
    
    async fetchAPI(endpoint) {
        const response = await fetch(`${this.apiBaseUrl}${endpoint}`, {
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });
        
        if (!response.ok) {
            throw new Error(`API Error: ${response.status} ${response.statusText}`);
        }
        
        return await response.json();
    }
    
    setupEventListeners() {
        // Event listener para resize
        window.addEventListener('resize', () => {
            Object.values(this.charts).forEach(chart => {
                chart.resize();
            });
        });
        
        // Event listeners para interações
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('system-item')) {
                this.showSystemDetails(e.target);
            }
        });
    }
    
    startRealTimeUpdates() {
        setInterval(async () => {
            if (!this.isUpdating) {
                await this.updateDashboard();
            }
        }, this.updateInterval);
    }
    
    async updateDashboard() {
        this.isUpdating = true;
        
        try {
            // Simular atualização de dados em tempo real
            this.updateRealTimeMetrics();
            
            // Atualizar gráficos
            this.updateCharts();
            
        } catch (error) {
            console.error('Erro na atualização do dashboard:', error);
        } finally {
            this.isUpdating = false;
        }
    }
    
    updateRealTimeMetrics() {
        // Simular dados em tempo real
        const metrics = {
            wafBlocked: Math.floor(Math.random() * 100) + 1500,
            wafBlockRate: (Math.random() * 3 + 3).toFixed(1),
            wafQuarantine: Math.floor(Math.random() * 10) + 20,
            idsEvents: Math.floor(Math.random() * 50) + 150,
            idsThreats: Math.floor(Math.random() * 5) + 10,
            idsAccuracy: (Math.random() * 5 + 95).toFixed(1),
            pentestScore: ['A+', 'A', 'A-', 'B+'][Math.floor(Math.random() * 4)]
        };
        
        this.updateElement('waf-blocked', metrics.wafBlocked.toLocaleString());
        this.updateElement('waf-block-rate', `${metrics.wafBlockRate}%`);
        this.updateElement('waf-quarantine', metrics.wafQuarantine);
        this.updateElement('ids-events', metrics.idsEvents);
        this.updateElement('ids-threats', metrics.idsThreats);
        this.updateElement('ids-accuracy', `${metrics.idsAccuracy}%`);
        this.updateElement('pentest-score', metrics.pentestScore);
    }
    
    updateCharts() {
        // Atualizar dados dos gráficos
        if (this.charts.threats) {
            const newData = [
                Math.floor(Math.random() * 20) + 40,
                Math.floor(Math.random() * 10) + 20,
                Math.floor(Math.random() * 5) + 10,
                Math.floor(Math.random() * 20) + 60
            ];
            this.charts.threats.data.datasets[0].data = newData;
            this.charts.threats.update('none');
        }
        
        if (this.charts.response) {
            const newData = Array.from({length: 6}, () => Math.floor(Math.random() * 10) + 2);
            this.charts.response.data.datasets[0].data = newData;
            this.charts.response.update('none');
        }
    }
    
    showSystemDetails(element) {
        const systemName = element.querySelector('div').textContent;
        console.log(`Mostrando detalhes do sistema: ${systemName}`);
        
        // Implementar modal ou página de detalhes
        alert(`Detalhes do sistema ${systemName} - Funcionalidade em desenvolvimento`);
    }
    
    showError(message) {
        console.error(message);
        
        // Criar notificação de erro
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert';
        errorDiv.innerHTML = `<strong>❌ Erro</strong><br>${message}`;
        
        const container = document.querySelector('.container');
        if (container) {
            container.insertBefore(errorDiv, container.firstChild);
            
            // Remover após 5 segundos
            setTimeout(() => {
                errorDiv.remove();
            }, 5000);
        }
    }
}

// Função global para refresh manual
async function refreshDashboard() {
    const refreshBtn = document.getElementById('refresh-text');
    const originalText = refreshBtn.textContent;
    
    refreshBtn.innerHTML = '<div class="loading"></div>';
    
    try {
        await dashboard.loadInitialData();
        await dashboard.updateDashboard();
        
        refreshBtn.textContent = '✓ Atualizado';
        setTimeout(() => {
            refreshBtn.textContent = originalText;
        }, 2000);
        
    } catch (error) {
        refreshBtn.textContent = '❌ Erro';
        setTimeout(() => {
            refreshBtn.textContent = originalText;
        }, 2000);
    }
}

// Inicializar dashboard quando a página carregar
let dashboard;

document.addEventListener('DOMContentLoaded', () => {
    dashboard = new SecurityDashboard();
});

// Funções utilitárias
function formatNumber(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1) + 'M';
    }
    if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
    }
    return num.toString();
}

function formatTime(seconds) {
    if (seconds < 60) {
        return `${seconds}s`;
    }
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return `${minutes}m ${remainingSeconds}s`;
}

function calculateThreatLevel(score) {
    if (score >= 90) return 'critical';
    if (score >= 70) return 'high';
    if (score >= 40) return 'medium';
    return 'low';
}

// Animações de entrada
function animateOnScroll() {
    const cards = document.querySelectorAll('.card');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animation = 'fadeInUp 0.6s ease forwards';
            }
        });
    }, { threshold: 0.1 });
    
    cards.forEach(card => {
        observer.observe(card);
    });
}

// CSS para animação
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
`;
document.head.appendChild(style);

// Inicializar animações
document.addEventListener('DOMContentLoaded', animateOnScroll);