// Settings Management Module - Refactored with lifecycle hooks
class SettingsManager {
    constructor() {
        this._mounted = false;
        this._listeners = {};
        this._profileDirty = false;
        this._storagePersistenceAttempted = false;
        this._passwordChangeInProgress = false;
        try {
            this._storagePersistenceAttempted = window.sessionStorage.getItem('sanie_storage_persist_attempted') === '1';
        } catch (error) {}
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        this.loadProfile();
        this.loadPreferences();
        this.loadOfflineDataStatus();
    }

    onUnmount() {
        this._mounted = false;
        document.getElementById('change-password-form')?.reset();
        clearTimeout(this._offlineStatusTimer);

        if (this._listeners.profileSubmit) {
            const form = document.getElementById('profile-form');
            if (form) {
                form.removeEventListener('submit', this._listeners.profileSubmit);
                form.removeEventListener('input', this._listeners.profileInput);
            }
        }
        if (this._listeners.currencyChange) {
            document.getElementById('settings-currency')?.removeEventListener('change', this._listeners.currencyChange);
        }
        document.getElementById('change-password-form')?.removeEventListener('submit', this._listeners.passwordSubmit);
        if (this._listeners.languageChange) {
            document.getElementById('settings-language')?.removeEventListener('change', this._listeners.languageChange);
        }
        if (this._listeners.themeChange) {
            document.getElementById('settings-theme')?.removeEventListener('change', this._listeners.themeChange);
        }
        document.getElementById('settings-avatar-input')?.removeEventListener('change', this._listeners.avatarChange);
        document.getElementById('settings-logout-btn')?.removeEventListener('click', this._listeners.logoutClick);
        document.getElementById('offline-data-clear')?.removeEventListener('click', this._listeners.offlineClearClick);
        document.getElementById('offline-storage-persist')?.removeEventListener('click', this._listeners.storagePersistClick);
        if (this._listeners.offlineStateChanged) {
            window.removeEventListener('offline:pending-changed', this._listeners.offlineStateChanged);
            window.removeEventListener('sync:state', this._listeners.offlineStateChanged);
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
            this._listeners.profileInput = () => this.setProfileDirty(true);
            profileForm.addEventListener('input', this._listeners.profileInput);
        }
        const passwordForm=document.getElementById('change-password-form');
        if(passwordForm){this._listeners.passwordSubmit=e=>{e.preventDefault();this.changePassword();};passwordForm.addEventListener('submit',this._listeners.passwordSubmit);}

        const currencySelect = document.getElementById('settings-currency');
        if (currencySelect) {
            this._listeners.currencyChange = (e) => {
                this.setPreference('currency', e.target.value);
                NotificationService.success(`Currency updated to ${e.target.value}`);
            };
            currencySelect.addEventListener('change', this._listeners.currencyChange);
        }

        const langSelect = document.getElementById('settings-language');
        if (langSelect) {
            this._listeners.languageChange = (e) => {
                this.setPreference('language', e.target.value);
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

        const avatarInput = document.getElementById('settings-avatar-input');
        if (avatarInput) {
            this._listeners.avatarChange = (e) => this.uploadAvatar(e.target.files?.[0]);
            avatarInput.addEventListener('change', this._listeners.avatarChange);
        }

        const logoutButton = document.getElementById('settings-logout-btn');
        if (logoutButton) {
            this._listeners.logoutClick = () => window.authManager?.handleLogout();
            logoutButton.addEventListener('click', this._listeners.logoutClick);
        }

        const clearOfflineButton = document.getElementById('offline-data-clear');
        if (clearOfflineButton) {
            this._listeners.offlineClearClick = () => this.clearCachedOfflineData();
            clearOfflineButton.addEventListener('click', this._listeners.offlineClearClick);
        }

        const persistButton = document.getElementById('offline-storage-persist');
        if (persistButton) {
            this._listeners.storagePersistClick = () => this.requestStoragePersistence();
            persistButton.addEventListener('click', this._listeners.storagePersistClick);
        }

        this._listeners.offlineStateChanged = () => {
            clearTimeout(this._offlineStatusTimer);
            this._offlineStatusTimer = setTimeout(() => this.loadOfflineDataStatus(), 250);
        };
        window.addEventListener('offline:pending-changed', this._listeners.offlineStateChanged);
        window.addEventListener('sync:state', this._listeners.offlineStateChanged);
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
        this.renderAvatar(user.avatar);
        this.setProfileDirty(false);
    }

    setProfileDirty(dirty) {
        this._profileDirty = Boolean(dirty);
        const button = document.getElementById('profile-save-button');
        const state = document.getElementById('profile-save-state');
        if (button) button.disabled = !this._profileDirty;
        if (!state) return;
        state.classList.toggle('is-dirty', this._profileDirty);
        state.replaceChildren();
        const icon = document.createElement('i');
        icon.className = this._profileDirty ? 'bi bi-exclamation-circle-fill' : 'bi bi-check-circle-fill';
        state.append(icon, document.createTextNode(this._profileDirty ? ' Unsaved changes' : ' All changes saved'));
    }

    loadPreferences() {
        const currency = this.getPreference('currency', 'NPR');
        const language = this.getPreference('language', 'en');
        const theme = this.getPreference('theme', 'light');

        const currencySelect = document.getElementById('settings-currency');
        const langSelect = document.getElementById('settings-language');
        const themeSelect = document.getElementById('settings-theme');

        if (currencySelect) currencySelect.value = currency;
        if (langSelect) langSelect.value = language;
        if (themeSelect) themeSelect.value = theme;
    }

    getPreference(name, fallback) {
        try { return window.localStorage.getItem(name) || fallback; } catch (error) { return fallback; }
    }

    setPreference(name, value) {
        try { window.localStorage.setItem(name, value); } catch (error) {}
    }

    async loadOfflineDataStatus() {
        const userId = window.authManager?.getCurrentUser()?.id;
        if (userId === undefined || userId === null || !window.OfflineStorage) return;
        const status = await window.OfflineStorage.getStorageStatus(userId);
        if (!status || !this._mounted) return;

        const attentionCount = status.failedCount + status.conflictCount;
        const summary = attentionCount > 0
            ? `${attentionCount} change${attentionCount === 1 ? ' needs' : 's need'} attention`
            : status.pendingCount > 0
                ? `${status.pendingCount} change${status.pendingCount === 1 ? '' : 's'} waiting to sync`
                : 'Ready';
        const snapshots = [status.dashboardCachedAt, status.transactionsCachedAt].filter(Boolean).sort();
        const latestSnapshot = snapshots.length ? snapshots[snapshots.length - 1] : null;

        document.getElementById('offline-data-summary').textContent = summary;
        document.getElementById('offline-data-updated').textContent = this.formatTimestamp(latestSnapshot, 'No saved snapshot');
        document.getElementById('offline-data-last-sync').textContent = this.formatTimestamp(status.lastSyncedAt, 'Not synced yet');
        document.getElementById('offline-data-usage').textContent = status.quota > 0
            ? `${this.formatBytes(status.usage)} of ${this.formatBytes(status.quota)} used${status.persisted === true ? ' · protected' : ''}`
            : (status.persisted === true ? 'Storage protected' : 'Usage unavailable');

        const persistButton = document.getElementById('offline-storage-persist');
        if (persistButton) {
            persistButton.hidden = status.persisted !== false
                || this._storagePersistenceAttempted
                || !navigator.storage?.persist;
        }
    }

    formatTimestamp(value, fallback) {
        if (!value) return fallback;
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return fallback;
        return date.toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' });
    }

    formatBytes(value) {
        const bytes = Number(value) || 0;
        if (bytes < 1024) return `${bytes} B`;
        const units = ['KB', 'MB', 'GB'];
        let amount = bytes / 1024;
        let unit = units[0];
        for (let index = 1; index < units.length && amount >= 1024; index++) {
            amount /= 1024;
            unit = units[index];
        }
        return `${amount.toFixed(amount >= 10 ? 0 : 1)} ${unit}`;
    }

    async clearCachedOfflineData() {
        const userId = window.authManager?.getCurrentUser()?.id;
        if (userId === undefined || userId === null || !window.OfflineStorage) return;
        const status = await window.OfflineStorage.getStorageStatus(userId);
        if (!status) {
            NotificationService.error('Unable to inspect offline data right now.');
            return;
        }
        const unsyncedCount = status.pendingCount + status.failedCount + status.conflictCount;
        const message = unsyncedCount > 0
            ? `${unsyncedCount} unsynced financial change${unsyncedCount === 1 ? '' : 's'} will be kept. Only disposable dashboard, transaction, reference, and metadata snapshots will be removed.`
            : 'Saved dashboard, transaction, reference, and metadata snapshots will be removed. The PWA app shell will remain available.';
        const result = window.Swal
            ? await window.Swal.fire({
                icon: 'warning',
                title: 'Clear cached offline data?',
                text: message,
                showCancelButton: true,
                confirmButtonText: 'Clear cached data',
                confirmButtonColor: '#ef4444'
            })
            : { isConfirmed: window.confirm(message) };
        if (!result.isConfirmed) return;

        const cleared = await window.OfflineStorage.clearOfflineUserData(userId);
        if (!cleared) {
            NotificationService.error('Unable to clear cached offline data. Please try again.');
            return;
        }
        NotificationService.success(unsyncedCount > 0
            ? 'Cached data cleared. Unsynced financial changes were kept.'
            : 'Cached offline data cleared.');
        await this.loadOfflineDataStatus();
    }

    async requestStoragePersistence() {
        const button = document.getElementById('offline-storage-persist');
        if (button) button.disabled = true;
        this._storagePersistenceAttempted = true;
        try { window.sessionStorage.setItem('sanie_storage_persist_attempted', '1'); } catch (error) {}
        const persisted = await window.OfflineStorage?.requestStoragePersistence();
        if (persisted === true) NotificationService.success('Browser storage protection enabled.');
        else if (persisted === false) NotificationService.info('The browser did not grant persistent storage. SanIE will continue normally.');
        else NotificationService.info('Persistent storage is not supported by this browser.');
        if (button) {
            button.disabled = false;
            button.hidden = true;
        }
        await this.loadOfflineDataStatus();
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
                this.setProfileDirty(false);
                NotificationService.success('Profile updated successfully');
            } else {
                NotificationService.error(response.message || 'Failed to update profile');
            }
        } catch (error) {
            NotificationService.error(error.message || 'Failed to update profile');
        }
    }

    async changePassword() {
        if(this._passwordChangeInProgress)return;
        const form=document.getElementById('change-password-form');const button=document.getElementById('change-password-button');
        if(!form?.checkValidity()){form?.reportValidity();return;}
        const current=document.getElementById('settings-current-password')?.value||'';const next=document.getElementById('settings-new-password')?.value||'';const confirmation=document.getElementById('settings-confirm-password')?.value||'';
        if(next!==confirmation){NotificationService.error('New password confirmation does not match.');return;}
        if(next===current){NotificationService.error('New password must be different from the current password.');return;}
        this._passwordChangeInProgress=true;if(button){button.disabled=true;button.setAttribute('aria-busy','true');}
        try{const response=await authAPI.changePassword({current_password:current,new_password:next,new_password_confirmation:confirmation});if(!response.success||!response.data?.token)throw new Error(response.message||'Password change failed.');api.setToken(response.data.token);form.reset();NotificationService.success('Password changed. Other sessions have been signed out.');}
        catch(error){NotificationService.error(error.message||'Password change failed.');}
        finally{this._passwordChangeInProgress=false;if(button){button.disabled=false;button.removeAttribute('aria-busy');}}
    }

    avatarUrl(path) {
        if (!path) return '';
        if (/^https?:/i.test(path)) {
            try {
                const url = new URL(path, window.location.href);
                return url.origin === window.location.origin ? url.href : '';
            } catch (error) {
                return '';
            }
        }
        if (/^(data:|blob:|javascript:)/i.test(path)) return '';
        return `${window.APP_CONFIG.API_BASE.replace(/\/api\/?$/, '')}/${String(path).replace(/^\/+/, '')}`;
    }

    renderAvatar(path) {
        const preview = document.getElementById('settings-avatar-preview');
        if (!preview) return;
        const url = this.avatarUrl(path);
        preview.replaceChildren();
        if (url) {
            const image = document.createElement('img');
            image.src = url;
            image.alt = 'Profile photo';
            preview.appendChild(image);
        } else {
            const icon = document.createElement('i');
            icon.className = 'bi bi-person-fill';
            preview.appendChild(icon);
        }
    }

    async uploadAvatar(file) {
        const input = document.getElementById('settings-avatar-input');
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            NotificationService.error('Please choose a JPG, PNG or WebP image.');
            input.value = '';
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            NotificationService.error('Profile photo must be smaller than 2 MB.');
            input.value = '';
            return;
        }

        const preview = document.getElementById('settings-avatar-preview');
        preview?.classList.add('is-uploading');
        try {
            const response = await authAPI.uploadAvatar(file);
            if (!response.success) throw new Error(response.message || 'Unable to update profile photo.');
            window.authManager.currentUser = response.data;
            this.renderAvatar(response.data.avatar);
            window.authManager.showMainApp();
            NotificationService.success('Profile photo updated successfully');
        } catch (error) {
            NotificationService.error(error.message || 'Unable to update profile photo.');
        } finally {
            preview?.classList.remove('is-uploading');
            input.value = '';
        }
    }
}

window.SettingsManager = SettingsManager;
