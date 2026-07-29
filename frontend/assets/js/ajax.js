/**
 * SanIE AJAX Service — centralized wrapper around api.js
 *
 * Features:
 *   - Loading states (buttons, containers)
 *   - Success/error toast notifications
 *   - Auto app:data-changed dispatch on mutations
 *   - Duplicate submission prevention
 *   - Skeleton loader helpers
 */

class AjaxService {
    /**
     * Perform a GET request with loading state.
     * @param {string} endpoint  - API endpoint (e.g. '/transactions')
     * @param {object}   opts
     * @param {string|Element} opts.loading  - selector or element to show spinner on
     * @param {string}   opts.errorMsg      - fallback error message
     * @returns {Promise<object>} response data
     */
    static async get(endpoint, opts = {}) {
        return AjaxService._request('GET', endpoint, null, opts);
    }

    /**
     * Perform a POST (create) request.
     * Auto-dispatches app:data-changed on success.
     */
    static async post(endpoint, data, opts = {}) {
        return AjaxService._request('POST', endpoint, data, { ...opts, mutate: true });
    }

    /**
     * Perform a PUT (update) request.
     * Auto-dispatches app:data-changed on success.
     */
    static async put(endpoint, data, opts = {}) {
        return AjaxService._request('PUT', endpoint, data, { ...opts, mutate: true });
    }

    /**
     * Perform a DELETE request.
     * Auto-dispatches app:data-changed on success.
     */
    static async del(endpoint, opts = {}) {
        return AjaxService._request('DELETE', endpoint, null, { ...opts, mutate: true });
    }

    /* ---------- debounced data-changed dispatch ---------- */

    static _dataChangedTimer = null;

    static _dispatchDataChanged() {
        if (AjaxService._dataChangedTimer) return;
        AjaxService._dataChangedTimer = setTimeout(() => {
            AjaxService._dataChangedTimer = null;
            document.dispatchEvent(new CustomEvent('app:data-changed'));
        }, 50);
    }

    /* ---------- internal ---------- */

    static async _request(method, endpoint, body, opts = {}) {
        const {
            loading = null,         // selector or element for loading state
            errorMsg = 'Something went wrong',
            successMsg = null,      // if set, show success toast on 2xx
            mutate = false,         // if true, dispatch app:data-changed on success
            silent = false,         // if true, suppress error toasts
        } = opts;

        // --- loading state ---
        const loadEl = loading ? (typeof loading === 'string' ? document.querySelector(loading) : loading) : null;
        let loadingKey = null;
        if (loadEl) {
            loadingKey = AjaxService._setLoading(loadEl, true);
        }

        // --- duplicate prevention ---
        const reqKey = `${method}:${endpoint}:${JSON.stringify(body)}`;
        if (AjaxService._inflight.has(reqKey)) return;
        AjaxService._inflight.add(reqKey);

        try {
            let result;
            switch (method) {
                case 'GET':    result = await window.Api.get(endpoint); break;
                case 'POST':   result = await window.Api.post(endpoint, body); break;
                case 'PUT':    result = await window.Api.put(endpoint, body); break;
                case 'DELETE': result = await window.Api.delete(endpoint); break;
                default: throw new Error('Unsupported method');
            }

            if (result && result.success) {
                if (successMsg && window.NotificationService) {
                    NotificationService.success(successMsg);
                }
                if (mutate) {
                    AjaxService._dispatchDataChanged();
                }
                return result;
            }

            // API returned success:false
            if (!silent && window.NotificationService) {
                NotificationService.error(result?.message || errorMsg);
            }
            return result;
        } catch (err) {
            if (!silent && window.NotificationService) {
                NotificationService.error(err.message || errorMsg);
            }
            console.error(`[Ajax] ${method} ${endpoint}`, err);
            throw err;
        } finally {
            AjaxService._inflight.delete(reqKey);
            if (loadEl && loadingKey) {
                AjaxService._setLoading(loadEl, false, loadingKey);
            }
        }
    }

    /* ========== LOADING STATE HELPERS ========== */

    /** Track loading state by element + unique key */
    static _states = new Map();  // el -> { count, html, text }
    static _inflight = new Set();

    /**
     * Enable/disable loading spinner on an element.
     * For buttons: replaces innerHTML with spinner, disables.
     * For containers: adds skeleton overlay (via CSS class).
     * @returns {string|null} loading key (for paired disable)
     */
    static _setLoading(el, enable, prevKey = null) {
        if (!el) return null;
        const tag = el.tagName.toLowerCase();

        if (enable) {
            const key = `load_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
            if (tag === 'button' || tag === 'a' || el.role === 'button') {
                AjaxService._states.set(el, {
                    html: el.innerHTML,
                    disabled: el.disabled,
                });
                el.disabled = true;
                const width = el.offsetWidth;
                el.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ';
                el.style.minWidth = width + 'px';
            } else {
                el.classList.add('ajax-loading');
            }
            return key;
        }

        // disable
        if (AjaxService._states.has(el)) {
            const prev = AjaxService._states.get(el);
            el.innerHTML = prev.html;
            el.disabled = prev.disabled || false;
            el.style.minWidth = '';
            AjaxService._states.delete(el);
        }
        el.classList.remove('ajax-loading');
        return null;
    }

    /* ========== SKELETON HELPERS ========== */

    /**
     * Insert skeleton placeholders into a container.
     * @param {string|Element} container - selector or element
     * @param {number} count - number of skeleton items
     * @param {string} type  - 'card' | 'row' | 'chart' | 'text'
     */
    static showSkeleton(container, count = 3, type = 'card') {
        const el = typeof container === 'string' ? document.querySelector(container) : container;
        if (!el) return;

        const templates = {
            card: '<div class="skeleton-card"><div class="skeleton-shimmer"></div></div>',
            row: '<div class="skeleton-row"><div class="skeleton-shimmer"></div></div>',
            chart: '<div class="skeleton-chart"><div class="skeleton-shimmer"></div></div>',
            text: '<div class="skeleton-text"><div class="skeleton-shimmer"></div></div>',
        };
        const tpl = templates[type] || templates.card;
        el.innerHTML = Array.from({ length: count }, () => tpl).join('');
    }

    /**
     * Remove skeleton placeholders (just clear innerHTML).
     * Caller should then render real data.
     */
    static hideSkeleton(container) {
        const el = typeof container === 'string' ? document.querySelector(container) : container;
        if (el) el.innerHTML = '';
    }
}

window.AjaxService = AjaxService;
