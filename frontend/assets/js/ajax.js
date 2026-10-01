/**
 * SanIE AJAX Service
 * Centralized wrapper around api.js
 */

class AjaxService {

    /* ==========================================================
       PUBLIC METHODS
    ========================================================== */

    static async get(endpoint, opts = {}) {
        return this._request("GET", endpoint, null, opts);
    }

    static async post(endpoint, data = {}, opts = {}) {
        return this._request("POST", endpoint, data, {
            ...opts,
            mutate: true
        });
    }

    static async put(endpoint, data = {}, opts = {}) {
        return this._request("PUT", endpoint, data, {
            ...opts,
            mutate: true
        });
    }

    static async del(endpoint, opts = {}) {
        return this._request("DELETE", endpoint, null, {
            ...opts,
            mutate: true
        });
    }

    /* ==========================================================
       INTERNAL STATE
    ========================================================== */

    static _states = new Map();
    static _inflight = new Set();
    static _dataChangedTimer = null;

    static _dispatchDataChanged() {

        if (this._dataChangedTimer) return;

        this._dataChangedTimer = setTimeout(() => {

            this._dataChangedTimer = null;

            document.dispatchEvent(
                new CustomEvent("app:data-changed")
            );

        }, 50);

    }

    /* ==========================================================
       MAIN REQUEST
    ========================================================== */

    static async _request(method, endpoint, body, opts = {}) {

        const {
            loading = null,
            errorMsg = "Something went wrong",
            successMsg = null,
            mutate = false,
            silent = false
        } = opts;

        const loadingElement =
            typeof loading === "string"
                ? document.querySelector(loading)
                : loading;

        const requestKey =
            method + ":" + endpoint + ":" + JSON.stringify(body);

        if (this._inflight.has(requestKey)) {
            return;
        }

        this._inflight.add(requestKey);
        if (loadingElement) {
            this.showButtonLoading(loadingElement);
        }

        try {

            let result;

            switch (method) {

                case "GET":
                    result = await window.Api.get(endpoint);
                    break;

                case "POST":
                    result = await window.Api.post(endpoint, body);
                    break;

                case "PUT":
                    result = await window.Api.put(endpoint, body);
                    break;

                case "DELETE":
                    result = await window.Api.delete(endpoint);
                    break;

                default:
                    throw new Error("Unsupported request method");

            }

            if (result?.success) {

                if (successMsg && window.NotificationService) {
                    NotificationService.success(successMsg);
                }

                if (mutate) {
                    this._dispatchDataChanged();
                }

                return result;

            }

            if (!silent && window.NotificationService) {
                NotificationService.error(
                    result?.message || errorMsg
                );
            }

            return result;

        }
        catch (error) {

            console.error(`[Ajax] ${method} ${endpoint}`, {
                status: error?.status || null,
                category: error?.category || 'server_error',
                code: error?.code || 'UNEXPECTED_ERROR'
            });

            if (!silent && error?.category !== 'aborted_error' && window.NotificationService) {
                NotificationService.error(
                    error?.category === 'timeout_error' ? 'Request timed out. Please try again.'
                        : error?.category === 'network_error' ? 'Unable to reach server.'
                            : error.message || errorMsg
                );
            }

            throw error;

        }
        finally {

            this._inflight.delete(requestKey);

            if (loadingElement) {
                this.hideButtonLoading(loadingElement);
            }

        }

    }

    /* ==========================================================
       BUTTON LOADING
    ========================================================== */

    static showButtonLoading(button) {

        if (!button) return;

        if (button.dataset.loading === "true") return;

        button.dataset.loading = "true";

        button.dataset.originalHtml = button.innerHTML;

        button.disabled = true;

        const width = button.offsetWidth;

        button.style.minWidth = width + "px";

        button.innerHTML = `
            <span class="spinner-border spinner-border-sm me-2"></span>
            Loading...
        `;

    }

    static hideButtonLoading(button) {

        if (!button) return;

        button.disabled = false;

        button.innerHTML =
            button.dataset.originalHtml || "Save";

        button.style.minWidth = "";

        delete button.dataset.loading;

        delete button.dataset.originalHtml;

    }

    /* ==========================================================
       GENERIC LOADING
    ========================================================== */

    static _setLoading(element, enable) {

        if (!element) return;

        const tag = element.tagName.toLowerCase();

        if (
            tag === "button" ||
            tag === "a" ||
            element.role === "button"
        ) {

            if (enable) {
                this.showButtonLoading(element);
            } else {
                this.hideButtonLoading(element);
            }

            return;
        }

        if (enable) {
            element.classList.add("ajax-loading");
        } else {
            element.classList.remove("ajax-loading");
        }

    }

    /* ==========================================================
       SKELETON HELPERS
    ========================================================== */

    static showSkeleton(container, count = 3, type = "card") {

        const el =
            typeof container === "string"
                ? document.querySelector(container)
                : container;

        if (!el) return;

        const templates = {

            card:
                '<div class="skeleton-card"><div class="skeleton-shimmer"></div></div>',

            row:
                '<div class="skeleton-row"><div class="skeleton-shimmer"></div></div>',

            chart:
                '<div class="skeleton-chart"><div class="skeleton-shimmer"></div></div>',

            text:
                '<div class="skeleton-text"><div class="skeleton-shimmer"></div></div>'

        };

        el.innerHTML = Array.from(
            { length: count },
            () => templates[type] || templates.card
        ).join("");

    }

    static hideSkeleton(container) {

        const el =
            typeof container === "string"
                ? document.querySelector(container)
                : container;

        // A successful render replaces the placeholders before its request
        // completes. Do not clear that fresh content in the `finally` block.
        if (el?.querySelector('.skeleton-card, .skeleton-row, .skeleton-chart, .skeleton-text')) {
            el.innerHTML = "";
        }

    }

}

window.AjaxService = AjaxService;
