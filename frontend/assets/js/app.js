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
        this.setupProfileButton();
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

        let backdrop = document.querySelector('.sidebar-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'sidebar-backdrop';
            document.body.appendChild(backdrop);
        }

        const closeSidebar = () => {
            sidebar.classList.remove('mobile-show');
            backdrop.classList.remove('show');
            if (window.closeAllFloatingMenus) window.closeAllFloatingMenus();
        };

        toggle.addEventListener('click', () => {
            const isOpen = sidebar.classList.contains('mobile-show');
            if (isOpen) {
                closeSidebar();
            } else {
                sidebar.classList.add('mobile-show');
                backdrop.classList.add('show');
                if (window.closeAllFloatingMenus) window.closeAllFloatingMenus();
            }
        });

        backdrop.addEventListener('click', closeSidebar);

        document.querySelectorAll('.sidebar .nav-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth < 992) {
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
            case 'savings':
                if (!window.savingsManager && window.SavingsManager) {
                    window.savingsManager = new window.SavingsManager();
                }
                if (window.savingsManager) {
                    window.savingsManager.loadSavingsData();
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
            case 'karobar-overview':
            case 'karobar-people':
            case 'karobar-person-profile':
            case 'karobar-transactions':
            case 'karobar-reports':
            case 'karobar-credit-reports':
            case 'karobar-ai-analysis':
                if (window.karobarManager) {
                    if (page === 'karobar-overview') window.karobarManager.loadOverview();
                    else if (page === 'karobar-people') window.karobarManager.loadPeople();
                    else if (page === 'karobar-person-profile') {
                        const params = new URLSearchParams(window.location.hash.split('?')[1] || '');
                        const pid = params.get('id');
                        if (pid) window.karobarManager.loadPersonProfile(parseInt(pid));
                    }
                    else if (page === 'karobar-transactions') window.karobarManager.loadTransactions();
                    else if (page === 'karobar-reports') window.karobarManager.loadReports();
                    else if (page === 'karobar-credit-reports') window.karobarManager.loadCreditReports();
                    else if (page === 'karobar-ai-analysis') window.karobarManager.loadAIAnalysis();
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
            icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars';
        }
    }

    setupQuickAdd() {
        const quickAddBtn = document.getElementById('quick-add-btn');
        if (!quickAddBtn) return;

        quickAddBtn.addEventListener('click', () => {
            const mgr = window.transactionsManager || transactionsManager;
            if (mgr && typeof mgr.showAddTransactionModal === 'function') {
                mgr.showAddTransactionModal();
            } else {
                console.warn('[QuickAdd] transactionsManager not available');
            }
        });
    }

    setupProfileButton() {
        const profileBtn = document.getElementById('profile-btn');
        if (!profileBtn) return;

        profileBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (this.router) {
                this.router.navigate('settings');
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

    window.app = new App();
    window.premiumModal = new PremiumModal();
    window.modalService = new ModalService();

    const router = new RouterService();
    window.appRouter = router;
    window.app.router = router;

    // Register feature modules with router for lifecycle management
    if (window.DashboardManager) router.registerRoute('dashboard', new DashboardManager());
    if (window.TransactionsManager) router.registerRoute('transactions', new TransactionsManager());
    if (window.BudgetsManager) router.registerRoute('budgets', new BudgetsManager());
    if (window.GoalsManager) router.registerRoute('goals', new GoalsManager());
    if (window.SavingsManager) router.registerRoute('savings', new SavingsManager());
    if (window.CategoriesManager) router.registerRoute('categories', new CategoriesManager());
    if (window.SubcategoriesManager) router.registerRoute('subcategories', new SubcategoriesManager());
    if (window.AnalysisManager) router.registerRoute('analysis', new AnalysisManager());
    if (window.ReportsManager) router.registerRoute('reports', new ReportsManager());
    if (window.SettingsManager) router.registerRoute('settings', new SettingsManager());
    if (window.NotificationsManager) router.registerRoute('notifications', new NotificationsManager());
    if (window.AccountDetailsManager) router.registerRoute('account-details', new AccountDetailsManager());
    if (window.AccountsManager) router.registerRoute('accounts', new AccountsManager());

    // Karobar Module
    if (window.KarobarManager) {
        const karobarMgr = new KarobarManager();
        router.registerRoute('karobar-overview', {
            onMount() { karobarMgr.currentPage = 'karobar-overview'; karobarMgr.onMount(); karobarMgr.loadOverview(); },
            onUnmount() {}
        });
        router.registerRoute('karobar-people', {
            onMount() { karobarMgr.currentPage = 'karobar-people'; karobarMgr.onMount(); karobarMgr.loadPeople(); },
            onUnmount() {}
        });
        router.registerRoute('karobar-person-profile', {
            onMount() {
                karobarMgr.currentPage = 'karobar-person-profile';
                karobarMgr.onMount();
                const hashParts = window.location.hash.split('?');
                const params = new URLSearchParams(hashParts[1] || '');
                const pid = params.get('id');
                if (pid) karobarMgr.loadPersonProfile(parseInt(pid));
            },
            onUnmount() {}
        });
        router.registerRoute('karobar-transactions', {
            onMount() { karobarMgr.currentPage = 'karobar-transactions'; karobarMgr.onMount(); karobarMgr.loadTransactions(); },
            onUnmount() { karobarMgr.destroyDataTable(); }
        });
        router.registerRoute('karobar-reports', {
            onMount() { karobarMgr.currentPage = 'karobar-reports'; karobarMgr.onMount(); karobarMgr.loadReports(); },
            onUnmount() {}
        });
        router.registerRoute('karobar-credit-reports', {
            onMount() { karobarMgr.currentPage = 'karobar-credit-reports'; karobarMgr.onMount(); karobarMgr.loadCreditReports(); },
            onUnmount() {}
        });
        router.registerRoute('karobar-ai-analysis', {
            onMount() { karobarMgr.currentPage = 'karobar-ai-analysis'; karobarMgr.onMount(); karobarMgr.loadAIAnalysis(); },
            onUnmount() {}
        });
        window.karobarManager = karobarMgr;
    }

    // Expose globally for inline onclick handlers
    window.categoriesManager = router.routes.get('categories') || null;
    window.subcategoriesManager = router.routes.get('subcategories') || null;
    window.transactionsManager = router.routes.get('transactions') || null;
    window.budgetsManager = router.routes.get('budgets') || null;
    window.goalsManager = router.routes.get('goals') || null;
    window.dashboardManager = router.routes.get('dashboard') || null;
    window.savingsManager = router.routes.get('savings') || null;
    window.accountsManager = router.routes.get('accounts') || null;
    window.reportsManager = router.routes.get('reports') || null;
    window.analysisManager = router.routes.get('analysis') || null;
    window.notificationsManager = router.routes.get('notifications') || null;

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
        const baseHash = hash.split('?')[0];
        const initialPage = (baseHash && router.routes.has(baseHash)) ? hash : 'dashboard';
        router.navigate(initialPage, false);

            const notifManager = router.routes.get('notifications');
            if (notifManager) {
                notifManager.setupBellDropdown();
                notifManager.startPolling(60000);
                notifManager._fetchUnreadCount();
            }
        }
    });
});
