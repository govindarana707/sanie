// Settings Management Module
class SettingsManager {
    constructor() {
        this.init();
    }

    init() {
        this.setupEventListeners();
    }

    onMount() {
        this.loadProfile();
        this.loadPreferences();
    }

    onUnmount() {
        // Cleanup if needed
    }

    setupEventListeners() {
        const profileForm = document.getElementById('profile-form');
        if (profileForm) {
            profileForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveProfile();
            });
        }

        const currencySelect = document.getElementById('settings-currency');
        if (currencySelect) {
            currencySelect.addEventListener('change', (e) => {
                localStorage.setItem('currency', e.target.value);
                NotificationService.success(`Currency updated to ${e.target.value}`);
            });
        }

        const langSelect = document.getElementById('settings-language');
        if (langSelect) {
            langSelect.addEventListener('change', (e) => {
                localStorage.setItem('language', e.target.value);
                NotificationService.success('Language preferences saved');
            });
        }

        const themeSelect = document.getElementById('settings-theme');
        if (themeSelect) {
            themeSelect.addEventListener('change', (e) => {
                const theme = e.target.value;
                if (window.app && typeof window.app.setTheme === 'function') {
                    window.app.setTheme(theme === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme);
                }
                NotificationService.success(`Theme updated to ${theme}`);
            });
        }
    }

    loadProfile() {
        const user = window.authManager?.getCurrentUser();
        if (!user) return;

        const firstNameInput = document.getElementById('settings-first-name');
        const lastNameInput = document.getElementById('settings-last-name');
        const emailInput = document.getElementById('settings-email');
        const phoneInput = document.getElementById('settings-phone');

        if (firstNameInput) firstNameInput.value = user.first_name || '';
        if (lastNameInput) lastNameInput.value = user.last_name || '';
        if (emailInput) emailInput.value = user.email || '';
        if (phoneInput) phoneInput.value = user.phone || '';
    }

    loadPreferences() {
        const currency = localStorage.getItem('currency') || 'NPR';
        const language = localStorage.getItem('language') || 'en';
        const theme = localStorage.getItem('theme') || 'light';

        const currencySelect = document.getElementById('settings-currency');
        const langSelect = document.getElementById('settings-language');
        const themeSelect = document.getElementById('settings-theme');

        if (currencySelect) currencySelect.value = currency;
        if (langSelect) langSelect.value = language;
        if (themeSelect) themeSelect.value = theme;
    }

    async saveProfile() {
        const firstName = document.getElementById('settings-first-name')?.value;
        const lastName = document.getElementById('settings-last-name')?.value;
        const phone = document.getElementById('settings-phone')?.value;

        try {
            const response = await authAPI.update({
                first_name: firstName,
                last_name: lastName,
                phone: phone
            });

            if (response.success) {
                if (window.authManager) {
                    window.authManager.currentUser = response.data;
                    window.authManager.showMainApp();
                }
                NotificationService.success('Profile updated successfully');
            } else {
                NotificationService.error(response.message || 'Failed to update profile');
            }
        } catch (error) {
            console.error('Failed to update profile:', error);
            NotificationService.error(error.message || 'Failed to update profile');
        }
    }
}

window.SettingsManager = SettingsManager;
