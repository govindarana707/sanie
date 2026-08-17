// Centralized PWA install and service-worker update lifecycle.
(function initializeSanIEPWA(window) {
    'use strict';

    const APP_FRONTEND_PATH = '/sanie/frontend/';

    class SanIEPWAController {
        constructor() {
            this.deferredInstallPrompt = null;
            this.registration = null;
            this.waitingWorker = null;
            this.updateRequested = false;
            this.refreshing = false;
            this.updateDeferred = this.sessionGet('sanie-update-deferred') === '1';
            this.reloadGuardKey = 'sanie-sw-update-reload';
            this.installButton = document.getElementById('pwa-install-button');
            this.installSetting = document.getElementById('pwa-install-setting');
            this.installHelp = document.getElementById('pwa-install-help');

            this.bindInstallEvents();
            this.bindUpdateEvents();
            this.renderInstallOption();

            if (document.readyState === 'complete') this.registerServiceWorker();
            else window.addEventListener('load', () => this.registerServiceWorker(), { once: true });

            // A completed reload must not suppress a future update in this tab.
            if (this.sessionGet(this.reloadGuardKey) === '1') {
                window.setTimeout(() => this.sessionRemove(this.reloadGuardKey), 10000);
            }
        }

        sessionGet(key) {
            try { return window.sessionStorage.getItem(key); } catch (error) { return null; }
        }

        sessionSet(key, value) {
            try { window.sessionStorage.setItem(key, value); return true; } catch (error) { return false; }
        }

        sessionRemove(key) {
            try { window.sessionStorage.removeItem(key); } catch (error) {}
        }

        bindInstallEvents() {
            window.addEventListener('beforeinstallprompt', event => {
                event.preventDefault();
                this.deferredInstallPrompt = event;
                this.renderInstallOption();
            });

            window.addEventListener('appinstalled', () => {
                this.deferredInstallPrompt = null;
                this.sessionRemove('sanie-install-declined');
                this.renderInstallOption();
                this.notify('success', 'SanIE installed successfully.');
            });

            const standaloneQuery = window.matchMedia?.('(display-mode: standalone)');
            const render = () => this.renderInstallOption();
            if (standaloneQuery?.addEventListener) standaloneQuery.addEventListener('change', render);
            else standaloneQuery?.addListener?.(render);
            this.installButton?.addEventListener('click', () => this.requestInstall());
        }

        bindUpdateEvents() {
            navigator.serviceWorker?.addEventListener('controllerchange', () => {
                if (!this.updateRequested || this.refreshing) return;
                if (this.sessionGet(this.reloadGuardKey) === '1') return;
                this.refreshing = true;
                this.sessionSet(this.reloadGuardKey, '1');
                window.location.reload();
            });

            ['offline', 'offline:pending-changed', 'sync:state', 'auth:authenticated', 'auth:unauthenticated']
                .forEach(eventName => window.addEventListener(eventName, () => this.renderUpdateBanner()));
            window.addEventListener('online', () => window.setTimeout(() => this.renderUpdateBanner(), 3200));
        }

        isStandalone() {
            return Boolean(window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true);
        }

        isIOS() {
            return /iphone|ipad|ipod/i.test(navigator.userAgent)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        }

        isIOSSafari() {
            return this.isIOS()
                && /safari/i.test(navigator.userAgent)
                && !/crios|fxios|edgios|opios/i.test(navigator.userAgent);
        }

        renderInstallOption() {
            if (!this.installSetting || !this.installButton || !this.installHelp) return;

            const programmaticInstall = Boolean(this.deferredInstallPrompt)
                && this.sessionGet('sanie-install-declined') !== '1';
            const iosInstructions = this.isIOSSafari() && window.isSecureContext && !this.isStandalone();

            if (this.isStandalone() || (!programmaticInstall && !iosInstructions)) {
                this.installSetting.hidden = true;
                return;
            }

            const label = this.installButton.querySelector('span');
            const icon = this.installButton.querySelector('i');
            this.installSetting.hidden = false;
            this.installButton.disabled = false;

            if (programmaticInstall) {
                if (label) label.textContent = 'Install App';
                if (icon) icon.className = 'bi bi-download me-1';
                this.installHelp.textContent = 'Install SanIE for quick access and a standalone app experience.';
            } else {
                if (label) label.textContent = 'How to install';
                if (icon) icon.className = 'bi bi-box-arrow-up me-1';
                this.installHelp.textContent = 'Install from the iOS Share menu.';
            }
        }

        async requestInstall() {
            if (this.deferredInstallPrompt) {
                const prompt = this.deferredInstallPrompt;
                this.deferredInstallPrompt = null;
                this.installButton.disabled = true;
                try {
                    await prompt.prompt();
                    const choice = await prompt.userChoice;
                    if (choice?.outcome !== 'accepted') {
                        this.sessionSet('sanie-install-declined', '1');
                    }
                } catch (error) {
                    this.notify('warning', 'Installation is not available right now.');
                } finally {
                    this.renderInstallOption();
                }
                return;
            }

            if (this.isIOSSafari() && !this.isStandalone()) {
                if (window.Swal?.fire) {
                    window.Swal.fire({
                        icon: 'info',
                        title: 'Install SanIE',
                        html: 'In Safari, tap <strong>Share</strong>, then choose <strong>Add to Home Screen</strong>.',
                        confirmButtonText: 'Got it',
                        confirmButtonColor: '#10b981'
                    });
                } else {
                    this.notify('info', 'In Safari, use Share, then Add to Home Screen.');
                }
            }
        }

        async registerServiceWorker() {
            if (!('serviceWorker' in navigator)) return;

            try {
                const frontendUrl = new URL(APP_FRONTEND_PATH, window.location.origin);
                const serviceWorkerUrl = new URL('service-worker.js', frontendUrl);
                this.registration = await navigator.serviceWorker.register(serviceWorkerUrl.href, {
                    scope: APP_FRONTEND_PATH
                });
                this.observeRegistration(this.registration);
                await this.registration.update().catch(() => undefined);
            } catch (error) {
                console.warn('SanIE service worker registration failed:', error.message);
            }
        }

        observeRegistration(registration) {
            if (registration.waiting && navigator.serviceWorker.controller) {
                this.setWaitingWorker(registration.waiting);
            }

            registration.addEventListener('updatefound', () => {
                const installingWorker = registration.installing;
                if (!installingWorker) return;
                installingWorker.addEventListener('statechange', () => {
                    if (installingWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        this.updateDeferred = false;
                        this.sessionRemove('sanie-update-deferred');
                        this.setWaitingWorker(registration.waiting || installingWorker);
                    }
                });
            });
        }

        setWaitingWorker(worker) {
            this.waitingWorker = worker;
            this.renderUpdateBanner();
        }

        ensureUpdateBanner() {
            let banner = document.getElementById('pwa-update-banner');
            if (banner) return banner;

            banner = document.createElement('aside');
            banner.id = 'pwa-update-banner';
            banner.className = 'pwa-update-banner';
            banner.setAttribute('role', 'status');
            banner.setAttribute('aria-live', 'polite');
            banner.hidden = true;
            banner.innerHTML = `
                <div class="pwa-update-icon" aria-hidden="true"><i class="bi bi-arrow-repeat"></i></div>
                <div class="pwa-update-copy">
                    <strong>New version available</strong>
                    <span>Refresh SanIE to use the latest improvements.</span>
                </div>
                <div class="pwa-update-actions">
                    <button type="button" class="btn btn-sm btn-light" data-pwa-action="later">Later</button>
                    <button type="button" class="btn btn-sm btn-success" data-pwa-action="update"><i class="bi bi-arrow-clockwise"></i> Update</button>
                </div>`;
            banner.querySelector('[data-pwa-action="later"]')?.addEventListener('click', () => {
                this.updateDeferred = true;
                banner.hidden = true;
                this.sessionSet('sanie-update-deferred', '1');
            });
            banner.querySelector('[data-pwa-action="update"]')?.addEventListener('click', () => this.applyUpdate());
            document.body.appendChild(banner);
            this.ensureLegacyUpdateStyles(banner);
            return banner;
        }

        ensureLegacyUpdateStyles(banner) {
            // Phase 6 used cache-first CSS. This one-release bridge keeps the
            // update action usable while that older worker is still active.
            if (window.getComputedStyle(banner).position === 'fixed') return;
            if (document.getElementById('pwa-update-legacy-styles')) return;

            const style = document.createElement('style');
            style.id = 'pwa-update-legacy-styles';
            style.textContent = `
                .pwa-update-banner{position:fixed;left:1rem;bottom:calc(1rem + env(safe-area-inset-bottom,0px));z-index:1045;display:flex;align-items:center;gap:1rem;max-width:min(430px,calc(100vw - 2rem));padding:.8rem .9rem;color:#f8fafc;background:#0f172a;border:1px solid rgba(255,255,255,.14);border-radius:.8rem;box-shadow:0 12px 32px rgba(15,23,42,.28)}
                .pwa-update-banner[hidden]{display:none}.pwa-update-copy{display:flex;flex:1;flex-direction:column;gap:.1rem;min-width:0}.pwa-update-copy span{color:#cbd5e1;font-size:.78rem}.pwa-update-actions{display:flex;gap:.4rem;flex-shrink:0}
                @media(max-width:768px){.pwa-update-banner{right:.75rem;left:.75rem;bottom:calc(78px + env(safe-area-inset-bottom,0px));max-width:none;align-items:flex-start;flex-direction:column;gap:.65rem}.pwa-update-actions{align-self:flex-end}}
                @media(max-width:375px){.pwa-update-banner{bottom:calc(68px + env(safe-area-inset-bottom,0px))}}`;
            document.head.appendChild(style);
        }

        async renderUpdateBanner() {
            const banner = this.ensureUpdateBanner();
            if (!this.waitingWorker || this.updateDeferred) {
                banner.hidden = true;
                return;
            }

            const blocker = await this.getFinancialChangeBlocker();
            banner.hidden = Boolean(blocker);
        }

        async getFinancialChangeBlocker() {
            const syncState = window.SanIESync?.getSyncState?.() || {};
            if (syncState.isSyncing || syncState.state === 'syncing' || window.transactionsManager?._saveInProgress) {
                return 'SanIE is saving or syncing financial changes. Please wait for it to finish before updating.';
            }

            const visibleFinancialForm = document.querySelector('#appModal.show form, .modal.show form, .swal2-container.swal2-shown');
            if (visibleFinancialForm) {
                return 'Close or save the open financial form before updating SanIE.';
            }

            return '';
        }

        async applyUpdate() {
            const worker = this.registration?.waiting || this.waitingWorker;
            if (!worker) return;

            const blocker = await this.getFinancialChangeBlocker();
            if (blocker) {
                this.notify('warning', blocker);
                this.renderUpdateBanner();
                return;
            }

            const button = document.querySelector('#pwa-update-banner [data-pwa-action="update"]');
            if (button) {
                button.disabled = true;
                button.textContent = 'Updating…';
            }
            this.updateRequested = true;
            worker.postMessage({ type: 'SKIP_WAITING' });
        }

        notify(type, message) {
            const service = window.NotificationService;
            if (service && typeof service[type] === 'function') service[type](message);
            else console.info(message);
        }
    }

    window.SanIEPWAController = SanIEPWAController;
    window.SanIEPWA = new SanIEPWAController();
})(window);
