// DatePicker Lifecycle Manager
class DatePickerManager {
    static instances = new Set();

    static bind(target, options = {}) {
        if (!window.flatpickr) return null;

        let elements = [];
        if (typeof target === 'string') {
            elements = Array.from(document.querySelectorAll(target));
        } else if (target instanceof NodeList || Array.isArray(target)) {
            elements = Array.from(target);
        } else if (target) {
            elements = [target];
        }

        const boundInstances = [];
        elements.forEach(el => {
            if (!el) return;
            if (el._flatpickr) {
                try {
                    DatePickerManager.instances.delete(el._flatpickr);
                    el._flatpickr.destroy();
                } catch (e) {}
            }
            const fp = flatpickr(el, {
                dateFormat: 'Y-m-d',
                allowInput: true,
                ...options
            });
            if (fp) {
                DatePickerManager.instances.add(fp);
                boundInstances.push(fp);
            }
        });

        return boundInstances.length === 1 ? boundInstances[0] : boundInstances;
    }

    static destroy(target) {
        let elements = [];
        if (typeof target === 'string') {
            elements = Array.from(document.querySelectorAll(target));
        } else if (target instanceof NodeList || Array.isArray(target)) {
            elements = Array.from(target);
        } else if (target) {
            elements = [target];
        }

        elements.forEach(el => {
            if (el && el._flatpickr) {
                try {
                    DatePickerManager.instances.delete(el._flatpickr);
                    el._flatpickr.destroy();
                } catch (e) {}
            }
        });
    }

    static destroyAll() {
        DatePickerManager.instances.forEach(fp => {
            try {
                fp.destroy();
            } catch (e) {}
        });
        DatePickerManager.instances.clear();

        document.querySelectorAll('.flatpickr-calendar').forEach(node => {
            node.remove();
        });
    }
}
window.DatePickerManager = DatePickerManager;

// Premium Modal Manager - uses Bootstrap 5.3 Modal API
class PremiumModal {
    constructor() {
        this.el = document.getElementById('appModal');
        this.bsModal = this.el ? new bootstrap.Modal(this.el, { keyboard: true, backdrop: 'static' }) : null;
        this.init();
    }

    init() {
        if (!this.el) return;
        this.el.addEventListener('hidden.bs.modal', () => {
            if (window.DatePickerManager) {
                window.DatePickerManager.destroyAll();
            }
        });
    }

    open() {
        if (this.bsModal) this.bsModal.show();
    }

    close() {
        if (window.DatePickerManager) {
            window.DatePickerManager.destroyAll();
        }
        if (this.bsModal) this.bsModal.hide();
    }

    setIcon(iconClass) {
        const pill = document.getElementById('modal-icon-pill');
        if (pill) pill.innerHTML = `<i class="fas ${iconClass}"></i>`;
    }

    setTitle(title) {
        const el = document.getElementById('appModalLabel');
        if (el) el.textContent = title;
    }

    setSubtitle(text) {
        const el = document.getElementById('modal-subtitle');
        if (el) el.textContent = text;
    }

    setFooterVisible(visible) {
        const footer = document.getElementById('modal-footer');
        if (footer) footer.classList.toggle('hidden', !visible);
    }
}

// Main Application
class App {
    constructor() {
        this.currentPage = 'dashboard';
        this.router = window.appRouter || null;
        this.init();
    }

    init() {
        this.setupMobileSidebar();
        this.setupThemeToggle();
        this.setupQuickAdd();
        this.setupGlobalSearch();
        this.hideLoadingScreen();
    }

    hideLoadingScreen() {
        setTimeout(() => {
            document.getElementById('loading-screen').classList.add('hidden');
        }, 1000);
    }

    setupMobileSidebar() {
        const toggle = document.getElementById('mobile-sidebar-toggle');
        const sidebar = document.querySelector('.sidebar');
        if (!toggle || !sidebar) return;

        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
        }

        const closeSidebar = () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
        };

        toggle.addEventListener('click', () => {
            const isOpen = sidebar.classList.contains('open');
            if (isOpen) {
                closeSidebar();
            } else {
                sidebar.classList.add('open');
                overlay.classList.add('active');
                document.body.classList.add('sidebar-open');
            }
        });

        overlay.addEventListener('click', closeSidebar);

        document.querySelectorAll('.sidebar .nav-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    closeSidebar();
                }
            });
        });
    }

    navigateTo(page, updateHistory = true) {
        if (this.router) {
            this.router.navigate(page, updateHistory);
        } else {
            this._legacyNavigate(page, updateHistory);
        }
    }

    _legacyNavigate(page, updateHistory = true) {
        if (window.DatePickerManager) {
            window.DatePickerManager.destroyAll();
        }

        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
            if (item.dataset.page === page) {
                item.classList.add('active');
            }
        });

        document.querySelectorAll('.page').forEach(p => {
            p.classList.remove('active');
        });

        const targetPage = document.getElementById(`${page}-page`);
        if (targetPage) {
            targetPage.classList.add('active');
        }

        this.currentPage = page;

        if (updateHistory) {
            history.pushState({ page }, '', `#${page}`);
        }

        this.loadPageData(page);
    }

    loadPageData(page) {
        if (!window.authManager?.isAuthenticated()) {
            return;
        }

        switch (page) {
            case 'dashboard':
                if (dashboardManager) {
                    dashboardManager.loadDashboardData();
                }
                break;
            case 'transactions':
                if (transactionsManager) {
                    transactionsManager.loadTransactions();
                }
                break;
            case 'budgets':
                if (budgetsManager) {
                    budgetsManager.loadBudgets();
                }
                break;
            case 'goals':
                if (goalsManager) {
                    goalsManager.loadGoals();
                }
                break;
            case 'categories':
                if (!window.categoriesManager && window.CategoriesManager) {
                    window.categoriesManager = new window.CategoriesManager();
                }
                if (window.categoriesManager) {
                    window.categoriesManager.loadCategories();
                    window.categoriesManager.loadStatistics();
                }
                break;
            case 'subcategories':
                if (!window.subcategoriesManager && window.SubcategoriesManager) {
                    window.subcategoriesManager = new window.SubcategoriesManager();
                }
                if (window.subcategoriesManager) {
                    window.subcategoriesManager.loadSubcategories();
                }
                break;
        }
    }

    setupThemeToggle() {
        const themeToggle = document.getElementById('theme-toggle');
        const savedTheme = localStorage.getItem('theme') || 'light';

        this.setTheme(savedTheme);

        themeToggle.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            this.setTheme(newTheme);
        });
    }

    setTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);

        const icon = document.querySelector('#theme-toggle i');
        if (icon) {
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    setupQuickAdd() {
        const quickAddBtn = document.getElementById('quick-add-btn');

        quickAddBtn.addEventListener('click', () => {
            if (transactionsManager) {
                transactionsManager.showAddTransactionModal();
            }
        });
    }

    setupGlobalSearch() {
        const searchInput = document.getElementById('global-search');

        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase();

            if (query.length >= 2) {
                this.performSearch(query);
            }
        });
    }

    async performSearch(query) {
        try {
            const response = await transactionsAPI.getAll({ search: query });

            if (response.success && response.data.length > 0) {
                this.navigateTo('transactions');

                if (transactionsManager) {
                    transactionsManager.transactions = response.data;
                    transactionsManager.renderTransactions();
                }
            }
        } catch (error) {
            console.error('Search failed:', error);
        }
    }
}

// Initialize app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    if (window.DatePickerManager) {
        window.DatePickerManager.destroyAll();
    }

    if (window.AOS) {
        AOS.init({
            duration: 800,
            easing: 'ease-out-cubic',
            once: true,
            offset: 50,
            delay: 0
        });
    }

    window.app = new App();
    window.premiumModal = new PremiumModal();

    const router = new RouterService();
    window.appRouter = router;
    window.app.router = router;

    router.init();

    window.addEventListener('auth:authenticated', () => {
        router.navigate(router.currentPage || 'dashboard', false);
    });

    window.addEventListener('auth:unauthenticated', () => {
        router.currentPage = 'dashboard';
    });

    window.waitForAuth().then(() => {
        if (window.authManager?.isAuthenticated()) {
            const hash = window.location.hash.slice(1);
            const initialPage = (hash && router.routes.has(hash)) ? hash : 'dashboard';
            router.navigate(initialPage, false);
        }
    });
});
