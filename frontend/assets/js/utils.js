// SanIE Frontend Utilities & Formatting Service
const DateUtils = {
    TIME_ZONE: 'Asia/Kathmandu',

    getKathmanduDateParts(value = new Date()) {
        const date = value instanceof Date ? value : new Date(value);
        if (Number.isNaN(date.getTime())) throw new TypeError('Invalid date value');
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: this.TIME_ZONE,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).formatToParts(date);
        const values = Object.fromEntries(parts.filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
        return { year: Number(values.year), month: Number(values.month), day: Number(values.day) };
    },

    getKathmanduDateString(value = new Date()) {
        const { year, month, day } = this.getKathmanduDateParts(value);
        return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    },

    addCalendarDays(dateString, days) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(dateString || ''));
        if (!match) throw new TypeError('Invalid calendar date');
        const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]) + Number(days)));
        return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
    },

    isValidCalendarDate(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match) return false;
        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const date = new Date(Date.UTC(year, month - 1, day));
        return date.getUTCFullYear() === year
            && date.getUTCMonth() === month - 1
            && date.getUTCDate() === day;
    },

    getKathmanduRange(period, value = new Date()) {
        const { year, month, day } = this.getKathmanduDateParts(value);
        const today = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        if (period === 'today') return { start: today, end: today };
        if (period === 'week') {
            const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
            const start = this.addCalendarDays(today, -(weekday === 0 ? 6 : weekday - 1));
            return { start, end: this.addCalendarDays(start, 6) };
        }
        if (period === 'month') {
            const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
            return { start: `${year}-${String(month).padStart(2, '0')}-01`, end: `${year}-${String(month).padStart(2, '0')}-${lastDay}` };
        }
        if (period === 'year') return { start: `${year}-01-01`, end: `${year}-12-31` };
        throw new TypeError('Unsupported calendar range');
    },

    parseKathmanduDateTime(value) {
        const text = String(value || '').trim().replace(' ', 'T');
        if (!text) return new Date(NaN);
        const zoned = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(text);
        return new Date(zoned ? text : `${text}+05:45`);
    }
};

// One CSV convention for exports: neutralize spreadsheet formulas only in
// textual cells, then independently apply RFC-style quote escaping.
const CSVUtils = {
    cell(value, formulaSafe = true) {
        let text = String(value ?? '');
        if (formulaSafe && /^[=+\-@]/.test(text)) text = `'${text}`;
        return `"${text.replace(/"/g, '""')}"`;
    },

    row(values, textColumns = null) {
        return values.map((value, index) => this.cell(value, textColumns === null || textColumns.has(index))).join(',');
    },

    document(headers, rows, textColumns = null) {
        return '\uFEFF' + [headers, ...rows].map(row => this.row(row, textColumns)).join('\r\n');
    }
};

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

    safeColor(value, fallback = '#6366f1') {
        const color = String(value || '').trim();
        return /^#[0-9a-f]{6}$/i.test(color) ? color : fallback;
    },

    safeIconClass(value, fallback = 'bi bi-circle') {
        const icon = String(value || '').trim().slice(0, 80);
        if (!/^[a-z0-9_-]+(?:\s+[a-z0-9_-]+)*$/i.test(icon)) return fallback;
        if (/^bi-/i.test(icon)) return `bi ${icon}`;
        if (/^fa-/i.test(icon)) return `fas ${icon}`;
        return icon;
    },

    truncate(str, maxLength = 30) {
        if (!str) return '';
        if (str.length <= maxLength) return str;
        return str.substring(0, maxLength) + '...';
    }
};

window.Formatters = Formatters;
window.DateUtils = DateUtils;
window.CSVUtils = CSVUtils;
