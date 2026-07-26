// SanIE Frontend Utilities & Formatting Service
const Formatters = {
    currency(amount, currency = 'NPR') {
        const num = parseFloat(amount);
        if (isNaN(num)) return 'Rs 0.00';
        
        const symbols = {
            NPR: 'Rs',
            INR: '₹',
            USD: '$',
            EUR: '€'
        };
        const symbol = symbols[currency] || 'Rs';

        return `${symbol} ${num.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        })}`;
    },

    compactCurrency(amount, currency = 'NPR') {
        const num = parseFloat(amount);
        if (isNaN(num)) return 'Rs 0';
        const symbol = currency === 'USD' ? '$' : (currency === 'EUR' ? '€' : (currency === 'INR' ? '₹' : 'Rs'));
        return `${symbol} ${num.toLocaleString('en-IN')}`;
    },

    date(dateString) {
        if (!dateString) return 'N/A';
        const d = new Date(dateString);
        if (isNaN(d.getTime())) return dateString;
        return d.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    },

    statusBadgeClass(status) {
        switch (String(status).toLowerCase()) {
            case 'active':
            case 'completed':
            case 'success':
                return 'bg-success';
            case 'archived':
            case 'pending':
            case 'warning':
                return 'bg-warning text-dark';
            case 'deleted':
            case 'inactive':
            case 'danger':
            case 'failed':
                return 'bg-danger';
            case 'default':
            case 'info':
                return 'bg-primary';
            default:
                return 'bg-secondary';
        }
    },

    typeBadgeClass(type) {
        switch (String(type).toLowerCase()) {
            case 'income':
                return 'bg-success';
            case 'expense':
                return 'bg-danger';
            case 'transfer':
                return 'bg-primary';
            default:
                return 'bg-secondary';
        }
    },

    escapeHTML(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    truncate(str, maxLength = 30) {
        if (!str) return '';
        if (str.length <= maxLength) return str;
        return str.substring(0, maxLength) + '...';
    }
};

window.Formatters = Formatters;
