// Centralized Notification Service (Toastify + SweetAlert2 Wrapper)
class NotificationService {
    static toast(message, type = 'success', duration = 3000) {
        const colors = {
            success: 'linear-gradient(135deg, #10B981 0%, #059669 100%)',
            error: 'linear-gradient(135deg, #EF4444 0%, #DC2626 100%)',
            warning: 'linear-gradient(135deg, #F59E0B 0%, #D97706 100%)',
            info: 'linear-gradient(135deg, #3B82F6 0%, #2563EB 100%)'
        };

        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };

        if (typeof Toastify === 'function') {
            Toastify({
                text: `<div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 1.25rem; font-weight: bold;">${icons[type] || icons.info}</span>
                    <span>${Formatters ? Formatters.escapeHTML(message) : message}</span>
                </div>`,
                duration,
                gravity: 'top',
                position: 'right',
                style: { background: colors[type] || colors.success },
                stopOnFocus: true,
                escapeMarkup: false
            }).showToast();
            return;
        }

        if (window.Swal) {
            window.Swal.fire({
                toast: true,
                position: 'top-end',
                icon: type,
                title: message,
                showConfirmButton: false,
                timer: duration
            });
            return;
        }

        console.log(`[${type.toUpperCase()}]: ${message}`);
    }

    static success(message) {
        this.toast(message, 'success');
    }

    static error(message) {
        this.toast(message, 'error');
    }

    static warning(message) {
        this.toast(message, 'warning');
    }

    static info(message) {
        this.toast(message, 'info');
    }

    static async confirm({
        title = 'Are you sure?',
        text = 'This action cannot be undone.',
        icon = 'warning',
        confirmButtonText = 'Yes, Proceed',
        cancelButtonText = 'Cancel',
        confirmButtonColor = '#EF4444',
        cancelButtonColor = '#6B7280'
    } = {}) {
        if (window.Swal) {
            const result = await window.Swal.fire({
                title,
                text,
                icon,
                showCancelButton: true,
                confirmButtonText,
                cancelButtonText,
                confirmButtonColor,
                cancelButtonColor,
                background: 'var(--glass-bg)',
                backdrop: 'rgba(15, 23, 42, 0.4)',
                customClass: {
                    popup: 'glass-modal',
                    confirmButton: 'btn-premium',
                    cancelButton: 'btn-premium'
                }
            });
            return result.isConfirmed;
        }
        return window.confirm(`${title}\n${text}`);
    }
}

window.NotificationService = NotificationService;
// Fallback showToast function
window.showToast = function(message, type = 'success') {
    NotificationService.toast(message, type);
};
