// SPA Router & Component Lifecycle Manager
class RouterService {
    constructor() {
        this.currentPage = null;
        this.routes = new Map();
        this.previousPage = null;
        this.isNavigating = false;
    }

    registerRoute(pageName, moduleInstance) {
        this.routes.set(pageName, moduleInstance);
    }

    init() {
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (item.classList.contains('has-submenu')) {
                    e.preventDefault();
                    item.classList.toggle('expanded');
                    const submenu = item.nextElementSibling;
                    if (submenu && submenu.classList.contains('submenu')) {
                        submenu.classList.toggle('expanded');
                    }
                    return;
                }

                e.preventDefault();
                const page = item.dataset.page;
                if (page) {
                    this.navigate(page);
                }
            });
        });

        window.addEventListener('popstate', (e) => {
            if (e.state && e.state.page) {
                this.navigate(e.state.page, false);
            } else {
                const hash = window.location.hash.slice(1);
                if (hash) {
                    this.navigate(hash, false);
                }
            }
        });
    }

    async navigate(page, updateHistory = true) {
        if (!page) return;
        const basePage = page.split('?')[0];
        if (basePage === this.currentPage) {
            const targetEl = document.getElementById(`${basePage}-page`);
            if (targetEl && targetEl.classList.contains('active')) {
                return;
            }
        }

        if (this.isNavigating) return;
        this.isNavigating = true;

        try {
            // 1. Unmount current page module
            if (this.currentPage) {
                const currentModule = this.routes.get(this.currentPage);
                if (currentModule && typeof currentModule.onUnmount === 'function') {
                    try {
                        await currentModule.onUnmount();
                    } catch (e) {
                        console.error(`[Router] Error during unmount of ${this.currentPage}:`, e);
                    }
                }
            }

            // 2. Clean up datepickers
            if (window.DatePickerManager) {
                window.DatePickerManager.destroyAll();
            }

            this.previousPage = this.currentPage;
            this.currentPage = basePage;

            // 3. Update active nav items
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
                if (item.dataset.page === basePage) {
                    item.classList.add('active');
                }
            });

            const activeNav = document.querySelector(`.nav-item[data-page="${basePage}"]`);
            if (activeNav) {
                const parentSubmenu = activeNav.closest('.submenu');
                if (parentSubmenu) {
                    parentSubmenu.classList.add('expanded');
                    const parentToggle = parentSubmenu.previousElementSibling;
                    if (parentToggle) parentToggle.classList.add('expanded');
                }
            }

            // 4. Update page containers visibility
            document.querySelectorAll('.page').forEach(p => {
                p.classList.remove('active');
            });

            const targetPage = document.getElementById(`${basePage}-page`);
            if (targetPage) {
                targetPage.classList.add('active');
            }

            // 5. Update browser history
            if (updateHistory) {
                history.pushState({ page: basePage }, '', `#${page}`);
            }

            // 6. Mount new page module if authenticated
            if (window.authManager?.isAuthenticated()) {
                const targetModule = this.routes.get(basePage);
                if (targetModule && typeof targetModule.onMount === 'function') {
                    try {
                        await targetModule.onMount();
                    } catch (e) {
                        console.error(`[Router] Error during mount of ${basePage}:`, e);
                    }
                }
            }

            // 7. Resize active charts after layout settles
            setTimeout(() => {
                if (window.ChartService) {
                    window.ChartService.resizeAll();
                }
            }, 100);
        } finally {
            this.isNavigating = false;
        }
    }

    refreshCurrentPage() {
        if (this.currentPage) {
            const module = this.routes.get(this.currentPage);
            if (module && typeof module.onMount === 'function') {
                module.onMount();
            }
        }
    }
}

window.RouterService = RouterService;
