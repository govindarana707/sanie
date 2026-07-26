// Auth Module
class AuthManager {
    constructor() {
        this.currentUser = null;
        this.initPromise = this.init();
    }

    async init() {
        // Check if user is logged in
        const token = localStorage.getItem('token');
        if (token) {
            api.setToken(token);
            try {
                const response = await authAPI.getMe();
                if (response.success) {
                    this.currentUser = response.data;
                    this.showMainApp();
                } else {
                    this.showAuthScreen();
                }
            } catch (error) {
                api.clearToken();
                this.showAuthScreen();
            }
        } else {
            api.clearToken();
            this.showAuthScreen();
        }

        this.setupEventListeners();
    }

    setupEventListeners() {
        // Auth tabs
        document.querySelectorAll('.auth-tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                this.switchAuthTab(e.target.dataset.tab);
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
    }

    switchAuthTab(tab) {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`[data-tab="${tab}"]`).classList.add('active');

        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
        document.getElementById(`${tab}-form`).classList.add('active');
    }

    async handleLogin() {
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;

        try {
            const response = await authAPI.login({ email, password });
            
            if (response.success) {
                api.setToken(response.data.token);
                this.currentUser = response.data.user;
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
            }
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Login Failed',
                text: error.message || 'Please check your credentials and try again.',
                confirmButtonText: 'Try Again',
                confirmButtonColor: '#10B981',
                background: 'var(--glass-bg)',
                backdrop: 'rgba(0, 0, 0, 0.7)',
                customClass: {
                    popup: 'glass-modal',
                    confirmButton: 'btn-premium'
                }
            });
        }
    }

    async handleRegister() {
        const firstName = document.getElementById('register-first-name').value;
        const lastName = document.getElementById('register-last-name').value;
        const email = document.getElementById('register-email').value;
        const password = document.getElementById('register-password').value;

        try {
            const response = await authAPI.register({
                email,
                password,
                first_name: firstName,
                last_name: lastName
            });
            
            if (response.success) {
                api.setToken(response.data.token);
                this.currentUser = response.data.user;
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
            Swal.fire({
                icon: 'error',
                title: 'Registration Failed',
                text: error.message || 'Please check your information and try again.',
                confirmButtonText: 'Try Again',
                confirmButtonColor: '#10B981',
                background: 'var(--glass-bg)',
                backdrop: 'rgba(0, 0, 0, 0.7)',
                customClass: {
                    popup: 'glass-modal',
                    confirmButton: 'btn-premium'
                }
            });
        }
    }

    handleLogout() {
        Swal.fire({
            title: 'Logout',
            text: 'Are you sure you want to logout?',
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
        }).then((result) => {
            if (result.isConfirmed) {
                api.clearToken();
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
                });
            }
        });
    }

    isAuthenticated() {
        return Boolean(localStorage.getItem('token') || api?.token);
    }

    showAuthScreen() {
        document.getElementById('loading-screen').classList.add('hidden');
        document.getElementById('auth-screen').classList.remove('hidden');
        document.getElementById('main-app').classList.add('hidden');
        window.dispatchEvent(new CustomEvent('auth:unauthenticated'));
    }

    showMainApp() {
        document.getElementById('loading-screen').classList.add('hidden');
        document.getElementById('auth-screen').classList.add('hidden');
        document.getElementById('main-app').classList.remove('hidden');
        
        // Update user info in sidebar
        document.getElementById('user-name').textContent = 
            `${this.currentUser.first_name} ${this.currentUser.last_name}`;
        document.getElementById('user-email').textContent = this.currentUser.email;
        window.dispatchEvent(new CustomEvent('auth:authenticated'));
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
