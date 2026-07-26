// Centralized HTTP client. Configuration is supplied by config.js.
const AppLogger = {
    debug(event, details = {}) {
        console.debug(`[SanIE API] ${event}`, details);
    },
    error(event, details = {}) {
        console.error(`[SanIE API] ${event}`, details);
    }
};

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

// API Client
class APIClient {
    constructor() {
        this.token = localStorage.getItem('token');
    }

    isAuthenticated() {
        return Boolean(this.token);
    }

    setToken(token) {
        this.token = token;
        localStorage.setItem('token', token);
    }

    clearToken() {
        this.token = null;
        localStorage.removeItem('token');
    }

    getHeaders(includeContentType = true) {
        const headers = {};
        if (includeContentType) {
            headers['Content-Type'] = 'application/json';
        }
        
        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }
        
        return headers;
    }

    async request(endpoint, options = {}) {
        const apiBase = window.APP_CONFIG?.API_BASE;
        if (!apiBase) {
            reportMissingApiConfiguration();
            throw new Error('API configuration missing.');
        }

        const requiresAuth = !endpoint.startsWith('/auth/login') && !endpoint.startsWith('/auth/register');
        if (!this.isAuthenticated() && requiresAuth) {
            throw new Error('Authentication required');
        }

        const url = `${apiBase}${endpoint}`;
        const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
        const config = {
            ...options,
            headers: {
                ...this.getHeaders(!isFormData),
                ...options.headers
            }
        };

        try {
            AppLogger.debug('Request', { method: config.method || 'GET', url, apiBase });
            const response = await fetch(url, config);
            if (response.status === 401) {
                this.clearToken();
                throw new Error('Authentication expired');
            }

            const responseText = await response.text();
            let data = null;

            if (responseText) {
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    data = { message: responseText };
                }
            }

            if (!response.ok) {
                AppLogger.error('Response error', { method: config.method || 'GET', url, status: response.status, response: data });
                throw new Error(data?.message || `Request failed with status ${response.status}`);
            }

            AppLogger.debug('Response', { method: config.method || 'GET', url, status: response.status, response: data });
            return data || {};
        } catch (error) {
            AppLogger.error('Request failed', { method: config.method || 'GET', url, error: error.message });
            throw error;
        }
    }

    async get(endpoint) {
        return this.request(endpoint, { method: 'GET' });
    }

    async post(endpoint, data) {
        return this.request(endpoint, {
            method: 'POST',
            body: JSON.stringify(data)
        });
    }

    async put(endpoint, data) {
        return this.request(endpoint, {
            method: 'PUT',
            body: JSON.stringify(data)
        });
    }

    async delete(endpoint) {
        return this.request(endpoint, { method: 'DELETE' });
    }

    async upload(endpoint, formData, method = 'POST') {
        if (typeof FormData === 'undefined' || !(formData instanceof FormData)) {
            throw new TypeError('upload requires a FormData payload');
        }
        return this.request(endpoint, { method, body: formData });
    }
}

// Create API client instance
const api = new APIClient();
window.Api = api;
window.AppLogger = AppLogger;

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

    async changePassword(data) {
        return api.post('/auth/change-password', data);
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
        return api.get(`/categories/${parentId}/subcategories`);
    }
};

// Budgets API
const budgetsAPI = {
    async getAll() {
        return api.get('/budgets');
    },

    async getById(id) {
        return api.get(`/budgets/${id}`);
    },

    async create(data) {
        return api.post('/budgets', data);
    },

    async update(id, data) {
        return api.put(`/budgets/${id}`, data);
    },

    async delete(id) {
        return api.delete(`/budgets/${id}`);
    },

    async getProgress(id) {
        return api.get(`/budgets/${id}/progress`);
    }
};

// Goals API
const goalsAPI = {
    async getAll() {
        return api.get('/goals');
    },

    async getById(id) {
        return api.get(`/goals/${id}`);
    },

    async create(data) {
        return api.post('/goals', data);
    },

    async update(id, data) {
        return api.put(`/goals/${id}`, data);
    },

    async delete(id) {
        return api.delete(`/goals/${id}`);
    },

    async getProgress(id) {
        return api.get(`/goals/${id}/progress`);
    },

    async contribute(id, amount) {
        return api.post(`/goals/${id}/contribute`, { amount });
    }
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
