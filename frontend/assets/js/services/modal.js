// Centralized Bootstrap 5.3 Modal Service with automatic event cleanup
class ModalService {
    constructor() {
        this.el = document.getElementById('appModal');
        this.bsModal = this.el && window.bootstrap ? new bootstrap.Modal(this.el, { keyboard: true, backdrop: 'static' }) : null;
        this._cleanupHandlers = [];
        this._activeForm = null;
        this.init();
    }

    init() {
        if (!this.el) return;
        this.el.addEventListener('hidden.bs.modal', () => {
            this.cleanup();
        });
    }

    cleanup() {
        if (window.DatePickerManager) {
            window.DatePickerManager.destroyAll();
        }

        const saveBtn = document.getElementById('modal-save-btn');
        if (saveBtn) {
            saveBtn.onclick = null;
            saveBtn.textContent = '';
        }

        const cancelBtn = document.getElementById('modal-cancel-btn');
        if (cancelBtn) {
            cancelBtn.onclick = null;
        }

        const draftBtn = document.getElementById('modal-draft-btn');
        if (draftBtn) {
            draftBtn.onclick = null;
            draftBtn.style.display = '';
        }

        const resetBtn = document.getElementById('modal-reset-btn');
        if (resetBtn) {
            resetBtn.onclick = null;
            resetBtn.style.display = '';
        }

        this._cleanupHandlers.forEach(fn => {
            try { fn(); } catch (e) {}
        });
        this._cleanupHandlers = [];
        this._activeForm = null;
    }

    onCleanup(fn) {
        if (typeof fn === 'function') {
            this._cleanupHandlers.push(fn);
        }
    }

    open({
        title = 'Modal Title',
        subtitle = '',
        icon = 'fa-wallet',
        bodyHTML = '',
        showFooter = true,
        onSave = null,
        onCancel = null,
        saveText = '<i class="fas fa-check me-1"></i> Save'
    } = {}) {
        if (!this.el || !this.bsModal) return;

        this.cleanup();

        const saveBtn = document.getElementById('modal-save-btn');
        if (saveBtn) {
            saveBtn.innerHTML = saveText;
            saveBtn.disabled = false;
        }

        const titleEl = document.getElementById('appModalLabel');
        const subtitleEl = document.getElementById('modal-subtitle');
        const iconEl = document.getElementById('modal-icon-pill');
        const bodyEl = document.getElementById('modal-body');
        const footerEl = document.getElementById('modal-footer');

        if (titleEl) titleEl.textContent = title;
        if (subtitleEl) subtitleEl.textContent = subtitle;
        if (iconEl) iconEl.innerHTML = `<i class="fas ${icon}"></i>`;
        if (bodyEl) bodyEl.innerHTML = bodyHTML;
        if (footerEl) footerEl.classList.toggle('hidden', !showFooter);

        if (saveBtn && typeof onSave === 'function') {
            saveBtn.onclick = async (e) => {
                e.preventDefault();
                e.stopPropagation();
                await onSave();
            };
        }

        const cancelBtn = document.getElementById('modal-cancel-btn');
        if (cancelBtn && typeof onCancel === 'function') {
            cancelBtn.onclick = () => onCancel();
        } else if (cancelBtn) {
            cancelBtn.onclick = () => this.close();
        }

        this._activeForm = bodyEl ? bodyEl.querySelector('form') : null;
        this.bsModal.show();
    }

    close() {
        if (this.bsModal) {
            this.bsModal.hide();
        }
        this.cleanup();
    }

    getForm() {
        const bodyEl = document.getElementById('modal-body');
        return bodyEl ? bodyEl.querySelector('form') : null;
    }

    async submitForm() {
        const form = this.getForm();
        if (!form) return false;
        if (!form.checkValidity()) {
            form.reportValidity();
            return false;
        }
        return true;
    }
}

window.ModalService = ModalService;
