// SPA Router & Component Lifecycle Manager
class RouterService {
    constructor() {
        this.currentPage = null;
        this.routes = new Map();
        this.previousPage = null;
        this.isNavigating = false;
        this.currentRoute = null;
        this.currentRouteIdentity = null;
        this._routeController = null;
        this._pendingNavigation = null;
        this._navigationLoop = null;
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
            const hash = window.location.hash.slice(1);
            const destination = hash || e.state?.page || '';
            if (destination) this.navigate(destination, false);
        });
    }

    navigate(page, updateHistory = true) {
        const route = this.parseRoute(page);
        if (!route.basePage) return Promise.resolve(false);

        const previousPending = this._pendingNavigation;
        if (previousPending) previousPending.resolve(false);
        const promise = new Promise(resolve => {
            this._pendingNavigation = { route, updateHistory, resolve };
        });

        // Cancel only the active route request. The mounted page still uses an
        // identity guard because a response may complete despite cancellation.
        if (this.routeIdentity(route) !== this.currentRouteIdentity) this._routeController?.abort();
        if (!this._navigationLoop) this._navigationLoop = this._drainNavigations();
        return promise;
    }

    parseRoute(page) {
        const raw = String(page || '').replace(/^#/, '');
        const separator = raw.indexOf('?');
        const basePage = (separator >= 0 ? raw.slice(0, separator) : raw).trim();
        const query = new URLSearchParams(separator >= 0 ? raw.slice(separator + 1) : '');
        const sortedQuery = new URLSearchParams();
        [...new Set(query.keys())].sort().forEach(key => query.getAll(key).forEach(value => sortedQuery.append(key, value)));
        const queryString = sortedQuery.toString();
        return { raw: queryString ? `${basePage}?${queryString}` : basePage, basePage, query: sortedQuery };
    }

    routeIdentity(route) {
        const module = this.routes.get(route.basePage);
        const dependencies = Array.isArray(module?.routeQueryKeys) ? module.routeQueryKeys : [];
        if (!dependencies.length) return route.basePage;
        const relevant = new URLSearchParams();
        dependencies.forEach(key => route.query.getAll(key).forEach(value => relevant.append(key, value)));
        const queryString = relevant.toString();
        return queryString ? `${route.basePage}?${queryString}` : route.basePage;
    }

    async _drainNavigations() {
        this.isNavigating = true;
        try {
            while (this._pendingNavigation) {
                const navigation = this._pendingNavigation;
                this._pendingNavigation = null;
                const completed = await this._performNavigation(navigation.route, navigation.updateHistory);
                navigation.resolve(completed);
            }
        } finally {
            this.isNavigating = false;
            this._navigationLoop = null;
        }
    }

    async _performNavigation(route, updateHistory) {
        const { basePage } = route;
        const identity = this.routeIdentity(route);
        const targetEl = document.getElementById(`${basePage}-page`);
        if (identity === this.currentRouteIdentity && targetEl?.classList.contains('active')) {
            if (updateHistory && route.raw !== this.currentRoute) history.pushState({ page: route.raw }, '', `#${route.raw}`);
            this.currentRoute = route.raw;
            return true;
        }

        {
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
            this.currentRoute = route.raw;
            this.currentRouteIdentity = identity;

            // 3. Update active nav items (sidebar + bottom nav)
            document.querySelectorAll('.nav-item, .mobile-nav-item').forEach(item => {
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
                history.pushState({ page: route.raw }, '', `#${route.raw}`);
            }

            // 6. Mount new page module if authenticated
            if (window.authManager?.isAuthenticated()) {
                const targetModule = this.routes.get(basePage);
                if (targetModule && typeof targetModule.onMount === 'function') {
                    const controller = new AbortController();
                    this._routeController = controller;
                    const context = {
                        page: basePage,
                        route: route.raw,
                        identity,
                        query: new URLSearchParams(route.query),
                        signal: controller.signal,
                        isCurrent: () => !controller.signal.aborted && this.currentRouteIdentity === identity
                    };
                    try {
                        Promise.resolve(targetModule.onMount(context)).catch(e => {
                            if (e?.category !== 'aborted_error' && e?.code !== 'ABORTED_ERROR') {
                                console.error(`[Router] Error during mount of ${basePage}:`, {
                                    category: e?.category || 'server_error', code: e?.code || 'MOUNT_ERROR'
                                });
                            }
                        });
                    } catch (e) {
                        if (e?.category !== 'aborted_error' && e?.code !== 'ABORTED_ERROR') {
                            console.error(`[Router] Error during mount of ${basePage}:`, {
                                category: e?.category || 'server_error', code: e?.code || 'MOUNT_ERROR'
                            });
                        }
                    }
                }
            }

            // 7. Resize active charts after layout settles
            setTimeout(() => {
                if (window.ChartService) {
                    window.ChartService.resizeAll();
                }
            }, 100);
            return true;
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
