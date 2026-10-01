// Centralized HTTP client. Configuration is supplied by config.js.
const AppLogger = {
    debug() {},
    error(event, details = {}) {
        console.error(`[SanIE API] ${event}`, details);
    }
};

class APIError extends Error {
    constructor(message, { category = 'server_error', code = 'API_ERROR', status = 0, retryable = false, cause = null, details = null } = {}) {
        super(message);
        this.name = 'APIError';
        this.category = category;
        this.code = code;
        this.status = status;
        this.retryable = retryable;
        this.details = details;
        if (cause) this.cause = cause;
    }
}

function reportMissingApiConfiguration() {
    AppLogger.error('API configuration missing.');
    if (window.Swal) {
        window.Swal.fire({
            icon: 'error',
            title: 'Configuration Error',
            text: 'Please contact administrator.',
            confirmButtonColor: '#EF4444'
        });
    }
}

// API Client with Response Cache
class APIClient {
    static _cache = new Map();
    static _inflightGets = new Map();
    static _cacheGeneration = 0;
    static _CACHE_TTL = 5000;
    static DEFAULT_TIMEOUT_MS = 15000;
    static UPLOAD_TIMEOUT_MS = 60000;
    static GET_RETRY_LIMIT = 1;
    static RETRYABLE_STATUSES = new Set([502, 503, 504]);

    static invalidateCache(pattern) {
        APIClient._cacheGeneration++;
        if (!pattern) { APIClient._cache.clear(); APIClient._inflightGets.clear(); return; }
        for (const key of APIClient._cache.keys()) {
            if (key.includes(pattern)) APIClient._cache.delete(key);
        }
        for (const key of APIClient._inflightGets.keys()) {
            if (key.includes(pattern)) APIClient._inflightGets.delete(key);
        }
    }

    static invalidateFinancialDependents(endpoint) {
        const path = endpoint.split('?')[0];
        const affectsStatements = path === '/transactions' || path.startsWith('/transactions/')
            || path === '/karobar' || path.startsWith('/karobar/')
            || (/^\/goals\/\d+\/contribute/.test(path))
            || (/^\/goals\/\d+\/contributions\//.test(path));
        if (affectsStatements) {
            ['/accounts', '/dashboard', '/ledger', '/reports', '/budgets']
                .forEach(pattern => APIClient.invalidateCache(pattern));
        }
        const affectsBudgets = path === '/budgets' || path.startsWith('/budgets/')
            || path === '/categories' || path.startsWith('/categories/')
            || path === '/subcategories' || path.startsWith('/subcategories/');
        if(affectsBudgets){
            ['/budgets','/categories','/subcategories','/dashboard','/reports']
                .forEach(pattern=>APIClient.invalidateCache(pattern));
        }
    }

    constructor() {
        // Keep the bearer token scoped to this browser tab. Migrate and remove
        // legacy persistent tokens created by older releases.
        this.token = null;
        try {
            this.token = window.sessionStorage.getItem('token') || window.localStorage.getItem('token');
            if (this.token) window.sessionStorage.setItem('token', this.token);
            window.localStorage.removeItem('token');
        } catch (error) {
            // Authentication can continue in memory for this page when browser
            // storage is unavailable; a reload will require a new login.
        }
    }

    isAuthenticated() {
        return Boolean(this.token);
    }

    getDataGeneration() {
        try {
            let encoded = String(this.token || '').split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
            encoded += '='.repeat((4 - encoded.length % 4) % 4);
            const payload = JSON.parse(atob(encoded));
            return Math.max(1, Number(payload.data_generation) || 1);
        } catch (error) { return 1; }
    }

    setToken(token) {
        this.token = token;
        APIClient._cache.clear();
        APIClient._inflightGets.clear();
        APIClient._cacheGeneration++;
        try { window.sessionStorage.setItem('token', token); } catch (error) {}
    }

    clearToken() {
        this.token = null;
        APIClient._cache.clear();
        APIClient._inflightGets.clear();
        APIClient._cacheGeneration++;
        try { window.sessionStorage.removeItem('token'); } catch (error) {}
        try { window.localStorage.removeItem('token'); } catch (error) {}
    }

    getHeaders(includeContentType = true) {
        const headers = {};
        if (includeContentType) {
            headers['Content-Type'] = 'application/json';
        }
        
        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
            headers['X-SanIE-Data-Generation'] = String(this.getDataGeneration());
        }
        
        return headers;
    }

    async request(endpoint, options = {}) {
        const apiBase = window.APP_CONFIG?.API_BASE;
        if (!apiBase) {
            reportMissingApiConfiguration();
            throw new APIError('API configuration missing.', { category: 'server_error', code: 'CONFIGURATION_ERROR' });
        }

        const publicAuthEndpoints = ['/auth/login', '/auth/register', '/auth/forgot-password', '/auth/reset-password'];
        const requiresAuth = !publicAuthEndpoints.some(path => endpoint === path || endpoint.startsWith(`${path}/`));
        if (!this.isAuthenticated() && requiresAuth) {
            throw new APIError('Authentication required.', { category: 'auth_error', code: 'AUTH_REQUIRED', status: 401 });
        }

        const method = String(options.method || 'GET').toUpperCase();
        const isGet = method === 'GET';
        const cacheKey = `${method}:${endpoint}`;
        const cacheGeneration = APIClient._cacheGeneration;

        if (isGet) {
            const cached = APIClient._cache.get(cacheKey);
            if (cached && Date.now() - cached.ts < APIClient._CACHE_TTL) {
                return cached.data;
            }
        }

        const executeRequest = async () => {
        const url = `${apiBase}${endpoint}`;
        const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
        const {
            timeoutMs = APIClient.DEFAULT_TIMEOUT_MS,
            retries = isGet ? APIClient.GET_RETRY_LIMIT : 0,
            retryDelayMs = 200,
            signal: callerSignal,
            ...fetchOptions
        } = options;
        const config = {
            ...fetchOptions,
            method,
            headers: {
                ...this.getHeaders(!isFormData),
                ...options.headers
            }
        };

        const maxAttempts = Math.max(1, 1 + Math.max(0, Number(retries) || 0));
        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                const data = await this._fetchOnce(url, config, { method, endpoint, timeoutMs, callerSignal, requiresAuth });
                if (isGet && data && cacheGeneration === APIClient._cacheGeneration) {
                    APIClient._cache.set(cacheKey, { data, ts: Date.now() });
                }
                return data || {};
            } catch (error) {
                const normalized = this._normalizeError(error, { method, endpoint, callerSignal });
                const shouldRetry = isGet && normalized.retryable && normalized.category !== 'aborted_error' && attempt < maxAttempts;
                if (shouldRetry) {
                    await this._retryDelay(retryDelayMs * attempt, callerSignal);
                    continue;
                }
                AppLogger.error('Request failed', { method, endpoint, status: normalized.status || null, category: normalized.category, code: normalized.code });
                throw normalized;
            }
        }
        };

        if (isGet && !options.signal) {
            const existingRequest = APIClient._inflightGets.get(cacheKey);
            if (existingRequest) return existingRequest;
            const requestPromise = executeRequest();
            APIClient._inflightGets.set(cacheKey, requestPromise);
            try {
                return await requestPromise;
            } finally {
                if (APIClient._inflightGets.get(cacheKey) === requestPromise) {
                    APIClient._inflightGets.delete(cacheKey);
                }
            }
        }

        return executeRequest();
    }

    async _fetchOnce(url, config, { method, endpoint, timeoutMs, callerSignal, requiresAuth }) {
        const controller = new AbortController();
        let timedOut = false;
        const duration = Number.isFinite(Number(timeoutMs)) && Number(timeoutMs) > 0
            ? Number(timeoutMs)
            : APIClient.DEFAULT_TIMEOUT_MS;
        const abortFromCaller = () => controller.abort(callerSignal?.reason);
        if (callerSignal?.aborted) abortFromCaller();
        else callerSignal?.addEventListener?.('abort', abortFromCaller, { once: true });
        const timer = setTimeout(() => {
            timedOut = true;
            controller.abort();
        }, duration);

        try {
            AppLogger.debug('Request', { method, endpoint });
            const response = await fetch(url, { ...config, signal: controller.signal });
            AppLogger.debug('Response received', { method, endpoint, status: response.status });

            const responseText = await response.text();
            let data = null;

            if (responseText) {
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    AppLogger.error('Response parsing failed', { method, endpoint, status: response.status });
                    const error = new APIError('Invalid server response.', {
                        category: 'server_error', code: 'RESPONSE_PARSE_ERROR', status: response.status,
                        retryable: APIClient.RETRYABLE_STATUSES.has(response.status)
                    });
                    throw error;
                }
            }

            if (!response.ok) {
                if (response.status === 401 && requiresAuth) {
                    this.clearToken();
                    window.dispatchEvent(new CustomEvent('auth:session-expired'));
                }
                AppLogger.error('Response error', { method, endpoint, status: response.status });
                const category = this._httpCategory(response.status);
                const safeMessage = this._safeServerMessage(data?.message, category);
                const error = new APIError(safeMessage, {
                    category,
                    code: 'HTTP_ERROR',
                    status: response.status,
                    retryable: APIClient.RETRYABLE_STATUSES.has(response.status),
                    details: data?.errors || null
                });
                error.apiCode = data?.code || null;
                error.serverData = data?.serverData || null;
                throw error;
            }

            AppLogger.debug('Response', { method, endpoint, status: response.status });

            return data || {};
        } catch (error) {
            if (timedOut) {
                throw new APIError('Request timed out.', { category: 'timeout_error', code: 'TIMEOUT_ERROR', retryable: true, cause: error });
            }
            if (callerSignal?.aborted || (error?.name === 'AbortError' && !timedOut)) {
                throw new APIError('Request was cancelled.', { category: 'aborted_error', code: 'ABORTED_ERROR', cause: error });
            }
            throw error;
        } finally {
            clearTimeout(timer);
            callerSignal?.removeEventListener?.('abort', abortFromCaller);
        }
    }

    _normalizeError(error, { callerSignal }) {
        if (error instanceof APIError) return error;
        if (callerSignal?.aborted || error?.name === 'AbortError') {
            return new APIError('Request was cancelled.', { category: 'aborted_error', code: 'ABORTED_ERROR', cause: error });
        }
        if (error instanceof TypeError) {
            return new APIError('Unable to reach server.', { category: 'network_error', code: 'NETWORK_ERROR', retryable: true, cause: error });
        }
        return new APIError('Unable to complete request.', { category: 'server_error', code: error?.code || 'UNEXPECTED_ERROR', cause: error });
    }

    _httpCategory(status) {
        if ([401, 403, 419].includes(status)) return 'auth_error';
        if ([400, 422].includes(status)) return 'validation_error';
        if (status === 409) return 'conflict_error';
        return 'server_error';
    }

    _safeServerMessage(message, category) {
        const value = String(message || '').trim();
        const fallback = {
            auth_error: 'Session expired.', validation_error: 'Validation failed.',
            conflict_error: 'The request conflicts with newer data.', server_error: 'Server is temporarily unavailable.'
        }[category] || 'Unable to complete request.';
        if (!value || /sql|pdo|database|query|stack|exception|connection refused|constraint/i.test(value)) return fallback;
        return value.slice(0, 180);
    }

    _retryDelay(delayMs, signal) {
        return new Promise((resolve, reject) => {
            if (signal?.aborted) {
                reject(new APIError('Request was cancelled.', { category: 'aborted_error', code: 'ABORTED_ERROR' }));
                return;
            }
            const timer = setTimeout(resolve, Math.max(0, Number(delayMs) || 0));
            signal?.addEventListener?.('abort', () => {
                clearTimeout(timer);
                reject(new APIError('Request was cancelled.', { category: 'aborted_error', code: 'ABORTED_ERROR' }));
            }, { once: true });
        });
    }

    async get(endpoint, options = {}) {
        return this.request(endpoint, { ...options, method: 'GET' });
    }

    async post(endpoint, data, options = {}) {
        APIClient.invalidateCache(endpoint.split('?')[0]);
        APIClient.invalidateFinancialDependents(endpoint);
        const result = await this.request(endpoint, {
            ...options,
            method: 'POST',
            body: JSON.stringify(data)
        });
        APIClient.invalidateFinancialDependents(endpoint);
        return result;
    }

    async put(endpoint, data, options = {}) {
        APIClient.invalidateCache(endpoint.split('?')[0].replace(/\/\d+$/, ''));
        APIClient.invalidateFinancialDependents(endpoint);
        const result = await this.request(endpoint, {
            ...options,
            method: 'PUT',
            body: JSON.stringify(data)
        });
        APIClient.invalidateFinancialDependents(endpoint);
        return result;
    }

    async patch(endpoint, data) {
        const result = await this.request(endpoint, {
            method: 'PATCH',
            body: JSON.stringify(data)
        });
        APIClient.invalidateCache(endpoint.split('/').slice(0, 2).join('/'));
        return result;
    }

    async delete(endpoint, data = null, options = {}) {
        APIClient.invalidateCache(endpoint.split('?')[0].replace(/\/\d+$/, ''));
        APIClient.invalidateFinancialDependents(endpoint);
        const result = await this.request(endpoint, {
            ...options,
            method: 'DELETE',
            ...(data ? { body: JSON.stringify(data) } : {})
        });
        APIClient.invalidateFinancialDependents(endpoint);
        return result;
    }

    async upload(endpoint, formData, method = 'POST') {
        if (typeof FormData === 'undefined' || !(formData instanceof FormData)) {
            throw new TypeError('upload requires a FormData payload');
        }
        APIClient.invalidateCache(endpoint.split('?')[0]);
        return this.request(endpoint, { method, body: formData, timeoutMs: APIClient.UPLOAD_TIMEOUT_MS });
    }
}

// Create API client instance
const api = new APIClient();
window.Api = api;
window.AppLogger = AppLogger;
window.APIError = APIError;

// Auth API
const authAPI = {
    async register(data) {
        return api.post('/auth/register', data);
    },

    async login(data) {
        return api.post('/auth/login', data);
    },

    async getMe() {
        return api.get('/auth/me');
    },

    async update(data) {
        return api.put('/auth/update', data);
    },

    async uploadAvatar(file) {
        const formData = new FormData();
        formData.append('avatar', file);
        return api.upload('/auth/avatar', formData);
    },

    async changePassword(data) {
        return api.post('/auth/change-password', data);
    },
    async prepareFreshStart() { return api.post('/auth/fresh-start/prepare', {}); },
    async exportFreshStart(operationId, intentToken) {
        return api.post('/auth/fresh-start/export', { operation_id: operationId }, { headers: { 'X-SanIE-Reset-Intent': intentToken } });
    },
    async verifyFreshStart(data, intentToken) {
        return api.post('/auth/fresh-start/verify', data, { headers: { 'X-SanIE-Reset-Intent': intentToken } });
    },
    async executeFreshStart(operationId, intentToken, confirmationToken) {
        return api.post('/auth/fresh-start/execute', { operation_id: operationId, final_confirmation: true }, { headers: {
            'X-SanIE-Reset-Intent': intentToken,
            'X-SanIE-Reset-Confirmation': confirmationToken
        }, timeoutMs: 60000 });
    },

    async forgotPassword(data) {
        return api.post('/auth/forgot-password', data);
    },

    async validatePasswordReset(data) {
        return api.post('/auth/reset-password/validate', data);
    },

    async resetPassword(data) {
        return api.post('/auth/reset-password', data);
    }
};

// Transactions API
const transactionsAPI = {
    async getAll(filters = {}) {
        const params = new URLSearchParams(filters);
        return api.get(`/transactions?${params}`);
    },

    async getById(id) {
        return api.get(`/transactions/${id}`);
    },

    async create(data) {
        return api.post('/transactions', data);
    },

    async update(id, data) {
        return api.put(`/transactions/${id}`, data);
    },

    async delete(id) {
        return api.delete(`/transactions/${id}`);
    },

    async getStatistics(startDate, endDate) {
        return api.get(`/transactions/statistics?start_date=${startDate}&end_date=${endDate}`);
    },

    async getCategoryBreakdown(startDate, endDate, type) {
        return api.get(`/transactions/category-breakdown?start_date=${startDate}&end_date=${endDate}&type=${type}`);
    }
};

// Accounts API
const accountsAPI = {
    async getAll() {
        return api.get('/accounts');
    },

    async getById(id) {
        return api.get(`/accounts/${id}`);
    },

    async create(data) {
        return api.post('/accounts', data);
    },

    async update(id, data) {
        return api.put(`/accounts/${id}`, data);
    },

    async delete(id) {
        return api.delete(`/accounts/${id}`);
    },

    async getTotalBalance() {
        return api.get('/accounts/total-balance');
    },

    async getOverview() {
        return api.get('/accounts/overview');
    },

    async getStatement(id, filters = {}, options = {}) {
        const params = new URLSearchParams(filters);
        return api.get(`/accounts/${id}/statement?${params}`, options);
    },

    async getAnalytics(id) {
        return api.get(`/accounts/${id}/analytics`);
    }
};

// Categories API
const categoriesAPI = {
    async getAll(type = null) {
        const params = type ? `?type=${type}` : '';
        return api.get(`/categories${params}`);
    },

    async getById(id) {
        return api.get(`/categories/${id}`);
    },

    async create(data) {
        return api.post('/categories', data);
    },

    async update(id, data) {
        return api.put(`/categories/${id}`, data);
    },

    async delete(id) {
        return api.delete(`/categories/${id}`);
    },

    async getSubcategories(parentId) {
        return api.get(`/subcategories?category_id=${parentId}&status=active`);
    }
};

// Budgets API
const budgetsAPI = {
    async getAll() {
        return AjaxService.get('/budgets', { silent: true });
    },

    async getById(id) {
        return AjaxService.get(`/budgets/${id}`, { silent: true });
    },

    async create(data) {
        return AjaxService.post('/budgets', data, { silent: true });
    },

    async update(id, data) {
        return AjaxService.put(`/budgets/${id}`, data, { silent: true });
    },

    async delete(id) {
        return AjaxService.del(`/budgets/${id}`, { silent: true });
    },

    async getProgress(id) {
        return AjaxService.get(`/budgets/${id}/progress`, { silent: true });
    },

    async getBatchProgress(ids, startDate = null, endDate = null) {
        const params = new URLSearchParams({ ids: ids.join(',') });
        if (startDate) params.set('start_date', startDate);
        if (endDate) params.set('end_date', endDate);
        return AjaxService.get(`/budgets/progress?${params.toString()}`, { silent: true });
    },

    async getAggregateProgress(ids, startDate = null, endDate = null) {
        const params = new URLSearchParams({ ids: ids.join(',') });
        if (startDate) params.set('start_date', startDate);
        if (endDate) params.set('end_date', endDate);
        return AjaxService.get(`/budgets/aggregate?${params.toString()}`, { silent: true });
    },

    async bulkCreate(budgets) {
        return AjaxService.post('/budgets/bulk', { budgets }, { silent: true });
    },

    async getSuggestions(period = 'monthly', months = 3) {
        return AjaxService.get(`/budgets/suggestions?period=${period}&months=${months}`, { silent: true });
    },

    async copyPrevious(period = 'monthly', source = 'month') {
        return AjaxService.get(`/budgets/copy?period=${period}&source=${source}`, { silent: true });
    },

    async copyToMonth(budgetIds, sourceMonth, targetMonth) {
        return AjaxService.post('/budgets/copy', {
            budget_ids: budgetIds,
            source_month: sourceMonth,
            target_month: targetMonth
        }, { silent: true });
    }
};

// Goals API
function invalidateGoalFinancialCaches() {
    ['/goals', '/accounts', '/transactions', '/dashboard', '/savings', '/ledger', '/reports', '/budgets']
        .forEach(pattern => APIClient.invalidateCache(pattern));
}

const goalsAPI = {
    async getAll() {
        return api.get('/goals');
    },

    async getById(id) {
        return api.get(`/goals/${id}`);
    },

    async create(data) {
        const result = await api.post('/goals', data);
        invalidateGoalFinancialCaches();
        return result;
    },

    async update(id, data) {
        const result = await api.put(`/goals/${id}`, data);
        invalidateGoalFinancialCaches();
        return result;
    },

    async delete(id) {
        const result = await api.delete(`/goals/${id}`);
        invalidateGoalFinancialCaches();
        return result;
    },

    async getProgress(id) {
        return api.get(`/goals/${id}/progress`);
    },

    async contribute(id, data) {
        const result = await api.post(`/goals/${id}/contribute`, data);
        invalidateGoalFinancialCaches();
        return result;
    },

    async getContributions(id) {
        return api.get(`/goals/${id}/contributions`);
    },

    async updateContribution(goalId, contributionId, data) {
        const result = await api.put(`/goals/${goalId}/contributions/${contributionId}`, data);
        invalidateGoalFinancialCaches();
        return result;
    },

    async deleteContribution(goalId, contributionId, version) {
        const result = await api.delete(`/goals/${goalId}/contributions/${contributionId}`, { base_version: version });
        invalidateGoalFinancialCaches();
        return result;
    }
};

// Tasks API
const tasksAPI = {
    async getAll(filters = {}, options = {}) {
        const params = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '' && value != null));
        return api.get(`/tasks${params.size ? `?${params}` : ''}`, options);
    },
    async getById(id) { return api.get(`/tasks/${id}`); },
    async create(data) { return api.post('/tasks', data); },
    async update(id, data) { return api.put(`/tasks/${id}`, data); },
    async setCompletion(id, completed) { return api.patch(`/tasks/${id}/completion`, { completed }); },
    async delete(id) { return api.delete(`/tasks/${id}`); },
    async getDeleted() { return api.get('/tasks/deleted'); },
    async restore(id) { return api.post(`/tasks/${id}/restore`, {}); },
    async processReminders() { return api.post('/tasks/reminders/process', {}); },
    async importBoardStudy() { return api.post('/tasks/board-study/import', {}); }
};

function invalidateRecurringFinancialCaches() {
    ['/recurring-transactions','/transactions','/accounts','/dashboard','/budgets','/reports','/ledger','/analysis']
        .forEach(pattern => APIClient.invalidateCache(pattern));
}

const recurringTransactionsAPI = {
    async getAll() { return api.get('/recurring-transactions'); },
    async getById(id) { return api.get(`/recurring-transactions/${id}`); },
    async create(data) { const result=await api.post('/recurring-transactions',data);APIClient.invalidateCache('/recurring-transactions');return result; },
    async update(id,data) { const result=await api.put(`/recurring-transactions/${id}`,data);APIClient.invalidateCache('/recurring-transactions');return result; },
    async delete(id) { const result=await api.delete(`/recurring-transactions/${id}`);APIClient.invalidateCache('/recurring-transactions');return result; },
    async activate(id,mode='resume') { const result=await api.post(`/recurring-transactions/${id}/activate`,{mode});APIClient.invalidateCache('/recurring-transactions');return result; },
    async deactivate(id) { const result=await api.post(`/recurring-transactions/${id}/deactivate`,{});APIClient.invalidateCache('/recurring-transactions');return result; },
    async review(id) { return api.get(`/recurring-transactions/${id}/review`); },
    async reconcile(id,decisions) { const result=await api.post(`/recurring-transactions/${id}/reconcile`,{decisions});invalidateRecurringFinancialCaches();return result; },
    async processDue() { const result=await api.post('/recurring-transactions/process',{});if((result?.data?.counts?.generated||0)>0||(result?.data?.counts?.already_processed||0)>0)invalidateRecurringFinancialCaches();return result; }
};

// Dashboard API
const dashboardAPI = {
    async getData(startDate, endDate) {
        const params = new URLSearchParams({ start_date: startDate, end_date: endDate });
        return api.get(`/dashboard?${params}`);
    },

    async getQuickStats() {
        return api.get('/dashboard/quick-stats');
    }
};

// Notifications API
const notificationsAPI = {
    async getAll(filters = {}) {
        const params = new URLSearchParams(filters);
        return api.get(`/notifications?${params}`);
    },

    async getRecent(limit = 10) {
        return api.get(`/notifications/recent?limit=${limit}`);
    },

    async getUnreadCount() {
        return api.get('/notifications/unread-count');
    },

    async markAsRead(id) {
        return api.post(`/notifications/${id}/read`);
    },

    async markAllAsRead() {
        return api.post('/notifications/read-all');
    },

    async delete(id) {
        return api.delete(`/notifications/${id}`);
    },

    async deleteAll() {
        return api.delete('/notifications');
    }
};

// Savings API
const savingsAPI = {
    async getData() {
        return api.get('/savings/data');
    }
};
