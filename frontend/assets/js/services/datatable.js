// Centralized DataTable Service to prevent duplicate initializations and memory leaks
class DataTableService {
    static instances = new Map();

    static init(selector, options = {}) {
        const $ = window.jQuery || window.$;
        if (!$) return null;

        const tableEl = document.querySelector(selector);
        if (!tableEl) return null;

        // Destroy existing instance if it exists
        this.destroy(selector);

        if (!$.fn || !$.fn.DataTable) {
            console.warn('DataTables plugin is not available.');
            return null;
        }

        const defaultOptions = {
            responsive: true,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            language: {
                search: '_INPUT_',
                searchPlaceholder: 'Search...',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                paginate: {
                    first: 'First',
                    last: 'Last',
                    next: 'Next',
                    previous: 'Previous'
                }
            },
            dom: '<"row g-3 mb-3"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row g-3 mt-3"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
        };

        const mergedOptions = { ...defaultOptions, ...options };
        const dt = $(tableEl).DataTable(mergedOptions);

        this.instances.set(selector, dt);
        return dt;
    }

    static destroy(selector) {
        const $ = window.jQuery || window.$;
        const dt = this.instances.get(selector);
        if (dt) {
            try {
                dt.clear();
                dt.destroy();
            } catch (e) {
                // Ignore destruction error if already destroyed
            }
            this.instances.delete(selector);
        } else if ($ && document.querySelector(selector)) {
            if ($.fn && $.fn.DataTable && $.fn.DataTable.isDataTable(selector)) {
                try {
                    $(selector).DataTable().clear().destroy();
                } catch (e) {}
            }
        }
    }

    static destroyAll() {
        this.instances.forEach((dt, selector) => {
            try {
                dt.clear();
                dt.destroy();
            } catch (e) {}
        });
        this.instances.clear();
    }

    static get(selector) {
        return this.instances.get(selector);
    }
}

window.DataTableService = DataTableService;
