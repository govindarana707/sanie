// Auth Module
class AuthManager {
    constructor() {
        this.currentUser = null;
        this.authState = 'UNKNOWN';
        this._bootstrapInProgress = false;
        this.setupEventListeners();
        this.initPromise = this.init();
    }

    async init() {
        const token = api?.token || null;
        if (token) {
            api.setToken(token);
            await this.verifySavedSession();
        } else {
            this.authState = 'UNAUTHENTICATED';
            this.showAuthScreen();
        }
    }

    async verifySavedSession() {
        if (this._bootstrapInProgress || !api?.token) return false;
        this._bootstrapInProgress = true;
        const retryButton = document.getElementById('auth-availability-retry');
        if (retryButton) {
            retryButton.disabled = true;
            retryButton.setAttribute('aria-busy', 'true');
        }
        try {
            const response = await authAPI.getMe();
            if (!response?.success || !response?.data) throw new APIError('Invalid server response.', { category: 'server_error' });
            this.authState = 'AUTHENTICATED';
            this.currentUser = response.data;
            window.OfflineStorage?.rememberIdentity(this.currentUser);
            this.showMainApp();
            return true;
        } catch (error) {
            if ([401, 403, 419].includes(Number(error?.status)) || (error?.category === 'auth_error' && error?.code !== 'AUTH_REQUIRED')) {
                api.clearToken();
                this.authState = 'UNAUTHENTICATED';
                this.currentUser = null;
                window.OfflineStorage?.clearRememberedIdentity();
                this.showAuthScreen();
            } else if (error?.category !== 'aborted_error') {
                this.authState = 'UNKNOWN';
                this.currentUser = null;
                this.showAvailabilityScreen(error);
            }
            return false;
        } finally {
            this._bootstrapInProgress = false;
            if (retryButton) {
                retryButton.disabled = false;
                retryButton.removeAttribute('aria-busy');
            }
        }
    }

    setupEventListeners() {
        window.addEventListener('auth:session-expired', () => {
            const wasAuthenticated = this.authState === 'AUTHENTICATED';
            this.authState = 'UNAUTHENTICATED';
            this.currentUser = null;
            window.OfflineStorage?.clearRememberedIdentity();
            this.showAuthScreen();
            if (wasAuthenticated && window.NotificationService) {
                window.NotificationService.warning('Your session expired. Please sign in again.');
            }
        });

        // Auth tabs
        document.querySelectorAll('.auth-tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                this.switchAuthTab(e.currentTarget.dataset.tab);
            });
            tab.addEventListener('keydown', (e) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
                e.preventDefault();
                const tabs = Array.from(document.querySelectorAll('.auth-tab'));
                const currentIndex = tabs.indexOf(e.currentTarget);
                const nextIndex = e.key === 'Home'
                    ? 0
                    : e.key === 'End'
                        ? tabs.length - 1
                        : (currentIndex + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                const nextTab = tabs[nextIndex];
                this.switchAuthTab(nextTab.dataset.tab);
                nextTab.focus({ preventScroll: true });
            });
        });

        document.querySelectorAll('.auth-password-toggle').forEach(button => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.passwordTarget);
                if (!input) return;
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                const icon = button.querySelector('i');
                if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            });
        });

        // Login form
        document.getElementById('login-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleLogin();
        });

        // Register form
        document.getElementById('register-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleRegister();
        });

        // Logout button
        document.getElementById('logout-btn').addEventListener('click', () => {
            this.handleLogout();
        });

        document.getElementById('auth-availability-retry')?.addEventListener('click', () => this.verifySavedSession());
        window.addEventListener('online', () => {
            if (this.authState === 'UNKNOWN' && api?.token) this.verifySavedSession();
        });
    }

    switchAuthTab(tab) {
        document.querySelectorAll('.auth-tab').forEach(t => {
            const active = t.dataset.tab === tab;
            t.classList.toggle('active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
            t.tabIndex = active ? 0 : -1;
        });

        document.querySelectorAll('.auth-form').forEach(f => {
            const active = f.id === `${tab}-form`;
            f.classList.toggle('active', active);
            f.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
        const form = document.getElementById(`${tab}-form`);
        form.querySelector('input')?.focus({ preventScroll: true });
    }

    async handleLogin() {
        if (this._loginInProgress) return;

        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        const form = document.getElementById('login-form');
        const button = form.querySelector('button[type="submit"]');
        const errorElement = document.getElementById('login-error');
        const originalButtonHTML = button.innerHTML;

        this._loginInProgress = true;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Signing in...';
        errorElement.textContent = '';

        try {
            const response = await authAPI.login({ email, password });
            
            if (response.success) {
                api.setToken(response.data.token);
                this.authState = 'AUTHENTICATED';
                this.currentUser = response.data.user;
                window.OfflineStorage?.rememberIdentity(this.currentUser);
                this.showMainApp();
                
                Swal.fire({
                    icon: 'success',
                    title: 'Welcome Back!',
                    text: 'Login successful. Redirecting to dashboard...',
                    timer: 2000,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    background: 'var(--glass-bg)',
                    backdrop: 'rgba(0, 0, 0, 0.7)',
                    customClass: {
                        popup: 'glass-modal',
                        title: 'text-gradient',
                        icon: 'success-icon'
                    }
                });
            } else {
                throw new Error(response.message || 'Login failed. Please try again.');
            }
        } catch (error) {
            const message = this.getLoginErrorMessage(error);
            errorElement.textContent = message;
            errorElement.setAttribute('role', 'alert');
            if (window.Swal) {
                Swal.fire({
                    icon: 'error', title: 'Login Failed', text: message,
                    confirmButtonText: 'Try Again', confirmButtonColor: '#10B981',
                    background: 'var(--glass-bg)', backdrop: 'rgba(0, 0, 0, 0.7)',
                    customClass: { popup: 'glass-modal', confirmButton: 'btn-premium' }
                });
            }
        } finally {
            this._loginInProgress = false;
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.innerHTML = originalButtonHTML;
        }
    }

    getLoginErrorMessage(error) {
        if (navigator.onLine === false) return 'No internet or server connection.';
        if (error?.status === 401) return 'Invalid email or password.';
        if (error?.status === 403 || error?.status === 419) return 'Your session has expired. Please refresh and try again.';
        if (error?.category === 'timeout_error') return 'The sign-in request timed out. Please try again.';
        if (error?.status >= 500 || error?.code === 'RESPONSE_PARSE_ERROR') return 'Something went wrong. Please try again.';
        if (error?.category === 'network_error' || error?.code === 'NETWORK_ERROR') return 'Unable to connect to the server. Please try again.';
        if (error?.status === 429) return error.message || 'Too many login attempts. Please try again later.';
        return 'Login failed. Please try again.';
    }

    async handleRegister() {
        if (this._registerInProgress) return;

        const firstName = document.getElementById('register-first-name').value;
        const lastName = document.getElementById('register-last-name').value;
        const email = document.getElementById('register-email').value;
        const password = document.getElementById('register-password').value;
        const form = document.getElementById('register-form');
        const button = form.querySelector('button[type="submit"]');
        const errorElement = document.getElementById('register-error');
        const originalButtonHTML = button.innerHTML;

        this._registerInProgress = true;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Creating account...';
        errorElement.textContent = '';

        try {
            const response = await authAPI.register({
                email,
                password,
                first_name: firstName,
                last_name: lastName
            });
            
            if (response.success) {
                api.setToken(response.data.token);
                this.authState = 'AUTHENTICATED';
                this.currentUser = response.data.user;
                window.OfflineStorage?.rememberIdentity(this.currentUser);
                this.showMainApp();
                
                Swal.fire({
                    icon: 'success',
                    title: 'Account Created!',
                    text: 'Registration successful. Welcome to SanIE!',
                    timer: 2000,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    background: 'var(--glass-bg)',
                    backdrop: 'rgba(0, 0, 0, 0.7)',
                    customClass: {
                        popup: 'glass-modal',
                        title: 'text-gradient',
                        icon: 'success-icon'
                    }
                });
            }
        } catch (error) {
            const message = error.message || 'Please check your information and try again.';
            errorElement.textContent = message;
            errorElement.setAttribute('role', 'alert');
            Swal.fire({
                icon: 'error',
                title: 'Registration Failed',
                text: message,
                confirmButtonText: 'Try Again',
                confirmButtonColor: '#10B981',
                background: 'var(--glass-bg)',
                backdrop: 'rgba(0, 0, 0, 0.7)',
                customClass: {
                    popup: 'glass-modal',
                    confirmButton: 'btn-premium'
                }
            });
        } finally {
            this._registerInProgress = false;
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.innerHTML = originalButtonHTML;
        }
    }

    async handleLogout() {
        const userId = this.currentUser?.id;
        const pendingCount = userId !== undefined && userId !== null
            ? await window.OfflineStorage?.countPendingActions(userId) || 0
            : 0;
        Swal.fire({
            title: 'Logout',
            text: pendingCount > 0
                ? `You have ${pendingCount} change${pendingCount === 1 ? '' : 's'} that haven't synced yet. Logging out will keep them safely isolated for this account.`
                : 'Are you sure you want to logout?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Logout',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#EF4444',
            cancelButtonColor: '#6B7280',
            background: 'var(--glass-bg)',
            backdrop: 'rgba(0, 0, 0, 0.7)',
            customClass: {
                popup: 'glass-modal',
                confirmButton: 'btn-premium',
                cancelButton: 'btn-premium'
            }
        }).then(async (result) => {
            if (result.isConfirmed) {
                if (userId !== undefined && userId !== null) {
                    await window.OfflineStorage?.clearOfflineUserData(userId);
                }
                window.OfflineStorage?.clearRememberedIdentity();
                api.clearToken();
                this.authState = 'UNAUTHENTICATED';
                this.currentUser = null;
                this.showAuthScreen();
                
                Swal.fire({
                    icon: 'success',
                    title: 'Logged Out',
                    text: 'You have been logged out successfully.',
                    timer: 1500,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    background: 'var(--glass-bg)',
                    backdrop: 'rgba(0, 0, 0, 0.7)',
                    customClass: {
                        popup: 'glass-modal',
                        icon: 'success-icon'
                    }
                }).then(() => window.location.reload());
            }
        });
    }

    isAuthenticated() {
        return this.authState === 'AUTHENTICATED' && Boolean(api?.isAuthenticated?.());
    }

    showAuthScreen() {
        document.getElementById('loading-screen').classList.add('hidden');
        document.getElementById('auth-availability-screen')?.setAttribute('hidden', '');
        document.getElementById('auth-screen').style.removeProperty('display');
        document.getElementById('main-app').style.display = 'none';
        window.dispatchEvent(new CustomEvent('auth:unauthenticated'));
    }

    showMainApp() {
        document.getElementById('loading-screen').classList.add('hidden');
        document.getElementById('auth-availability-screen')?.setAttribute('hidden', '');
        document.getElementById('auth-screen').style.display = 'none';
        document.getElementById('main-app').style.removeProperty('display');
        
        // Update user info in sidebar
        document.getElementById('user-name').textContent = 
            `${this.currentUser.first_name} ${this.currentUser.last_name}`;
        document.getElementById('user-email').textContent = this.currentUser.email;
        const sidebarAvatar = document.getElementById('sidebar-user-avatar');
        if (sidebarAvatar) {
            const path = this.currentUser.avatar;
            const url = path ? `${window.APP_CONFIG.API_BASE.replace(/\/api\/?$/, '')}/${String(path).replace(/^\/+/, '')}` : '';
            sidebarAvatar.replaceChildren();
            if (url) {
                const image = document.createElement('img');
                image.src = url;
                image.alt = 'Profile photo';
                sidebarAvatar.appendChild(image);
            } else {
                const icon = document.createElement('i');
                icon.className = 'bi bi-person-fill';
                sidebarAvatar.appendChild(icon);
            }
        }
        window.dispatchEvent(new CustomEvent('auth:authenticated'));
    }

    showAvailabilityScreen(error) {
        document.getElementById('loading-screen').classList.add('hidden');
        document.getElementById('auth-screen').style.display = 'none';
        document.getElementById('main-app').style.display = 'none';
        const screen = document.getElementById('auth-availability-screen');
        const message = document.getElementById('auth-availability-message');
        if (message) {
            message.textContent = error?.category === 'timeout_error'
                ? 'Session verification timed out. Your saved session is still safe.'
                : 'SanIE cannot reach the server right now. Your saved session is still safe.';
        }
        screen?.removeAttribute('hidden');
        window.dispatchEvent(new CustomEvent('auth:temporarily-unavailable', { detail: { category: error?.category || 'server_error' } }));
    }

    getAuthState() {
        return this.authState;
    }

    getCurrentUser() {
        return this.currentUser;
    }
}

// Initialize auth manager
const authManager = new AuthManager();
window.authManager = authManager;

// Expose a helper that waits for auth initialization to complete
window.waitForAuth = async function() {
    if (window.authManager?.initPromise) {
        await window.authManager.initPromise;
    }
};
