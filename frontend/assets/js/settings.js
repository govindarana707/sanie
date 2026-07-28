// Settings Management Module - Refactored with lifecycle hooks
class SettingsManager {
    constructor() {
        this._mounted = false;
        this._listeners = {};
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        this.loadProfile();
        this.loadPreferences();
    }

    onUnmount() {
        this._mounted = false;

        if (this._listeners.profileSubmit) {
            const form = document.getElementById('profile-form');
            if (form) {
                form.removeEventListener('submit', this._listeners.profileSubmit);
            }
        }
        if (this._listeners.currencyChange) {
            document.getElementById('settings-currency')?.removeEventListener('change', this._listeners.currencyChange);
        }
        if (this._listeners.languageChange) {
            document.getElementById('settings-language')?.removeEventListener('change', this._listeners.languageChange);
        }
        if (this._listeners.themeChange) {
            document.getElementById('settings-theme')?.removeEventListener('change', this._listeners.themeChange);
        }
    }

    setupEventListeners() {
        const profileForm = document.getElementById('profile-form');
        if (profileForm) {
            profileForm.removeEventListener('submit', this._listeners.profileSubmit);
            this._listeners.profileSubmit = (e) => {
                e.preventDefault();
                this.saveProfile();
            };
            profileForm.addEventListener('submit', this._listeners.profileSubmit);
        }

        const currencySelect = document.getElementById('settings-currency');
        if (currencySelect) {
            this._listeners.currencyChange = (e) => {
                localStorage.setItem('currency', e.target.value);
                NotificationService.success(`Currency updated to ${e.target.value}`);
            };
            currencySelect.addEventListener('change', this._listeners.currencyChange);
        }

        const langSelect = document.getElementById('settings-language');
        if (langSelect) {
            this._listeners.languageChange = (e) => {
                localStorage.setItem('language', e.target.value);
                NotificationService.success('Language preferences saved');
            };
            langSelect.addEventListener('change', this._listeners.languageChange);
        }

        const themeSelect = document.getElementById('settings-theme');
        if (themeSelect) {
            this._listeners.themeChange = (e) => {
                const theme = e.target.value;
                if (window.app && typeof window.app.setTheme === 'function') {
                    window.app.setTheme(theme === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme);
                }
                NotificationService.success(`Theme updated to ${theme}`);
            };
            themeSelect.addEventListener('change', this._listeners.themeChange);
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
