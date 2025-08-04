/**
 * UI Utilities - Sistema ERP
 * Funcionalidades gerais de interface e UX
 */

class UIManager {
    constructor() {
        this.init();
    }
    
    init() {
        this.setupSidebar();
        this.setupTooltips();
        this.setupModals();
        this.setupKeyboardShortcuts();
        this.setupTheme();
    }
    
    /**
     * Configuração da sidebar
     */
    setupSidebar() {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('sidebarToggle');
        
        if (toggle) {
            toggle.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
            });
        }
        
        // Restaurar estado da sidebar
        const collapsed = localStorage.getItem('sidebar_collapsed') === 'true';
        if (collapsed) {
            sidebar.classList.add('collapsed');
        }
        
        // Mobile menu
        this.setupMobileMenu();
    }
    
    /**
     * Menu mobile
     */
    setupMobileMenu() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
        
        // Abrir menu mobile
        const openMobileMenu = () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.add('open');
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        };
        
        // Fechar menu mobile
        const closeMobileMenu = () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        };
        
        // Event listeners
        overlay.addEventListener('click', closeMobileMenu);
        
        // Fechar ao clicar em link do menu
        const menuItems = sidebar.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    closeMobileMenu();
                }
            });
        });
        
        // Adicionar botão de menu mobile no header
        this.addMobileMenuButton(openMobileMenu);
    }
    
    /**
     * Adiciona botão do menu mobile
     */
    addMobileMenuButton(openCallback) {
        const header = document.querySelector('.main-header');
        if (header && window.innerWidth <= 768) {
            const mobileButton = document.createElement('button');
            mobileButton.className = 'mobile-menu-btn';
            mobileButton.innerHTML = '<i class="fas fa-bars"></i>';
            mobileButton.addEventListener('click', openCallback);
            
            header.insertBefore(mobileButton, header.firstChild);
        }
    }
    
    /**
     * Sistema de tooltips
     */
    setupTooltips() {
        const tooltips = document.querySelectorAll('[data-tooltip]');
        
        tooltips.forEach(element => {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = element.getAttribute('data-tooltip');
            document.body.appendChild(tooltip);
            
            element.addEventListener('mouseenter', (e) => {
                const rect = e.target.getBoundingClientRect();
                tooltip.style.display = 'block';
                tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                tooltip.style.top = rect.top - tooltip.offsetHeight - 8 + 'px';
                tooltip.classList.add('show');
            });
            
            element.addEventListener('mouseleave', () => {
                tooltip.classList.remove('show');
                setTimeout(() => {
                    tooltip.style.display = 'none';
                }, 200);
            });
        });
    }
    
    /**
     * Sistema de modais
     */
    setupModals() {
        // Fechar modal ao clicar no overlay
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                this.closeModal(e.target.querySelector('.modal'));
            }
        });
        
        // Fechar modal com ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const openModal = document.querySelector('.modal.show');
                if (openModal) {
                    this.closeModal(openModal);
                }
            }
        });
    }
    
    /**
     * Abre modal
     */
    openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Foco no primeiro input
            const firstInput = modal.querySelector('input, textarea, select');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 100);
            }
        }
    }
    
    /**
     * Fecha modal
     */
    closeModal(modal) {
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }
    
    /**
     * Atalhos de teclado
     */
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + K para busca rápida
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                this.openQuickSearch();
            }
            
            // Ctrl/Cmd + D para dashboard
            if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
                e.preventDefault();
                window.location.href = '/dashboard.html';
            }
            
            // Ctrl/Cmd + Shift + C para CRM
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'C') {
                e.preventDefault();
                window.location.href = '/crm.html';
            }
        });
    }
    
    /**
     * Busca rápida
     */
    openQuickSearch() {
        // Implementar busca rápida
        console.log('Busca rápida - Em desenvolvimento');
    }
    
    /**
     * Sistema de tema
     */
    setupTheme() {
        const theme = localStorage.getItem('app_theme') || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        // Botão de alternância de tema (se existir)
        const themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                this.toggleTheme();
            });
        }
    }
    
    /**
     * Alterna tema
     */
    toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme');
        const newTheme = current === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('app_theme', newTheme);
    }
    
    /**
     * Mostra notificação toast
     */
    showToast(message, type = 'info', duration = 5000) {
        const container = document.getElementById('toastContainer') || this.createToastContainer();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <i class="fas fa-${this.getToastIcon(type)}"></i>
                <span>${message}</span>
            </div>
            <button class="toast-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.appendChild(toast);
        
        // Animação de entrada
        setTimeout(() => toast.classList.add('show'), 10);
        
        // Auto-remover
        setTimeout(() => {
            toast.classList.add('hiding');
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }, duration);
        
        return toast;
    }
    
    /**
     * Cria container de toast se não existir
     */
    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
        return container;
    }
    
    /**
     * Obtém ícone do toast baseado no tipo
     */
    getToastIcon(type) {
        const icons = {
            'success': 'check-circle',
            'error': 'exclamation-circle',
            'warning': 'exclamation-triangle',
            'info': 'info-circle'
        };
        return icons[type] || 'info-circle';
    }
    
    /**
     * Confirma ação com modal
     */
    confirm(message, title = 'Confirmação') {
        return new Promise((resolve) => {
            const modal = this.createConfirmModal(message, title, resolve);
            document.body.appendChild(modal);
            this.openModal(modal.id);
        });
    }
    
    /**
     * Cria modal de confirmação
     */
    createConfirmModal(message, title, callback) {
        const modalId = 'confirmModal_' + Date.now();
        const modal = document.createElement('div');
        modal.id = modalId;
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal confirm-modal">
                <div class="modal-header">
                    <h3>${title}</h3>
                </div>
                <div class="modal-body">
                    <p>${message}</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" onclick="window.ui.handleConfirm('${modalId}', false)">
                        Cancelar
                    </button>
                    <button class="btn btn-primary" onclick="window.ui.handleConfirm('${modalId}', true)">
                        Confirmar
                    </button>
                </div>
            </div>
        `;
        
        modal._callback = callback;
        return modal;
    }
    
    /**
     * Manipula resposta do modal de confirmação
     */
    handleConfirm(modalId, result) {
        const modal = document.getElementById(modalId);
        if (modal && modal._callback) {
            modal._callback(result);
            this.closeModal(modal.querySelector('.modal'));
            setTimeout(() => modal.remove(), 300);
        }
    }
    
    /**
     * Loading state para botões
     */
    setButtonLoading(button, loading = true) {
        if (loading) {
            button.disabled = true;
            button.classList.add('loading');
            
            const originalText = button.innerHTML;
            button.dataset.originalText = originalText;
            button.innerHTML = `
                <i class="fas fa-spinner fa-spin"></i>
                Carregando...
            `;
        } else {
            button.disabled = false;
            button.classList.remove('loading');
            button.innerHTML = button.dataset.originalText || button.innerHTML;
        }
    }
    
    /**
     * Validação de formulário
     */
    validateForm(form) {
        const fields = form.querySelectorAll('[required]');
        let isValid = true;
        
        fields.forEach(field => {
            const value = field.value.trim();
            const fieldGroup = field.closest('.form-group');
            
            // Remover erros anteriores
            this.clearFieldError(field);
            
            // Validar campo obrigatório
            if (!value) {
                this.showFieldError(field, 'Este campo é obrigatório');
                isValid = false;
                return;
            }
            
            // Validações específicas por tipo
            if (field.type === 'email' && !this.isValidEmail(value)) {
                this.showFieldError(field, 'Email inválido');
                isValid = false;
            }
            
            if (field.type === 'tel' && !this.isValidPhone(value)) {
                this.showFieldError(field, 'Telefone inválido');
                isValid = false;
            }
            
            // Validação customizada
            const customValidation = field.dataset.validate;
            if (customValidation && window[customValidation]) {
                const result = window[customValidation](value);
                if (result !== true) {
                    this.showFieldError(field, result);
                    isValid = false;
                }
            }
        });
        
        return isValid;
    }
    
    /**
     * Mostra erro em campo
     */
    showFieldError(field, message) {
        field.classList.add('error');
        
        let errorElement = field.parentElement.querySelector('.field-error');
        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.className = 'field-error';
            field.parentElement.appendChild(errorElement);
        }
        
        errorElement.textContent = message;
    }
    
    /**
     * Remove erro de campo
     */
    clearFieldError(field) {
        field.classList.remove('error');
        const errorElement = field.parentElement.querySelector('.field-error');
        if (errorElement) {
            errorElement.remove();
        }
    }
    
    /**
     * Validações
     */
    isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
    
    isValidPhone(phone) {
        return /^[\d\s\(\)\-\+]{10,}$/.test(phone);
    }
    
    /**
     * Formatação de dados
     */
    formatCurrency(value) {
        return new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        }).format(value);
    }
    
    formatNumber(value) {
        return new Intl.NumberFormat('pt-BR').format(value);
    }
    
    formatDate(date, options = {}) {
        const defaultOptions = {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        };
        
        return new Intl.DateTimeFormat('pt-BR', { ...defaultOptions, ...options })
            .format(new Date(date));
    }
    
    formatDateTime(date) {
        return new Intl.DateTimeFormat('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(new Date(date));
    }
    
    /**
     * Utilitários de DOM
     */
    createElement(tag, className, innerHTML) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (innerHTML) element.innerHTML = innerHTML;
        return element;
    }
    
    /**
     * Debounce para otimizar eventos
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    /**
     * Throttle para limitar frequência de execução
     */
    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
}

// Instância global do UI Manager
window.ui = new UIManager();

// Event listeners globais
document.addEventListener('DOMContentLoaded', () => {
    // Auto-foco em campos com atributo autofocus
    const autofocusField = document.querySelector('[autofocus]');
    if (autofocusField) {
        setTimeout(() => autofocusField.focus(), 100);
    }
    
    // Confirmar antes de sair da página se há dados não salvos
    window.addEventListener('beforeunload', (e) => {
        const hasUnsavedData = document.querySelector('.form-dirty, [data-unsaved="true"]');
        if (hasUnsavedData) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
});

// Adicionar estilos CSS para componentes UI
const uiStyles = `
<style>
/* Sidebar Overlay para mobile */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.sidebar-overlay.active {
    opacity: 1;
    visibility: visible;
}

/* Mobile menu button */
.mobile-menu-btn {
    display: none;
    background: none;
    border: none;
    color: #4a5568;
    font-size: 1.25rem;
    padding: 0.5rem;
    border-radius: 0.375rem;
    cursor: pointer;
}

@media (max-width: 768px) {
    .mobile-menu-btn {
        display: block;
    }
}

/* Tooltips */
.tooltip {
    position: absolute;
    background: #1a202c;
    color: white;
    padding: 0.5rem 0.75rem;
    border-radius: 0.375rem;
    font-size: 0.75rem;
    white-space: nowrap;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    pointer-events: none;
}

.tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 4px solid transparent;
    border-top-color: #1a202c;
}

.tooltip.show {
    opacity: 1;
    visibility: visible;
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.modal-overlay.show {
    opacity: 1;
    visibility: visible;
}

.modal {
    background: white;
    border-radius: 0.5rem;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 25px rgba(0, 0, 0, 0.1);
    transform: scale(0.95);
    transition: transform 0.3s ease;
}

.modal-overlay.show .modal {
    transform: scale(1);
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.modal-header h3 {
    margin: 0;
    color: #1a202c;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1.5rem;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
}

/* Confirm Modal */
.confirm-modal {
    max-width: 400px;
}

.confirm-modal .modal-body {
    text-align: center;
}

/* Form Validation */
.form-group input.error,
.form-group textarea.error,
.form-group select.error {
    border-color: #f56565;
    box-shadow: 0 0 0 3px rgba(245, 101, 101, 0.1);
}

.field-error {
    color: #f56565;
    font-size: 0.75rem;
    margin-top: 0.25rem;
    display: block;
}

/* Toast animations */
.toast {
    transform: translateX(100%);
    transition: transform 0.3s ease;
}

.toast.show {
    transform: translateX(0);
}

.toast.hiding {
    transform: translateX(100%);
}

/* Button loading state */
.btn.loading {
    position: relative;
    color: transparent;
}

.btn.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 16px;
    height: 16px;
    border: 2px solid transparent;
    border-top: 2px solid currentColor;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: translate(-50%, -50%) rotate(0deg); }
    100% { transform: translate(-50%, -50%) rotate(360deg); }
}

/* Primary button */
.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}
</style>
`;

document.head.insertAdjacentHTML('beforeend', uiStyles);