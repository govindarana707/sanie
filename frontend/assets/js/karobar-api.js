// Karobar API Module
const karobarAPI = {
    invalidatePersonCaches() {
        ['/people', '/karobar', '/dashboard', '/reports']
            .forEach(pattern => api.constructor.invalidateCache(pattern));
    },
    invalidateCreditPurchaseCaches() {
        ['/karobar', '/people', '/transactions', '/accounts', '/dashboard', '/reports', '/ledger', '/budgets']
            .forEach(pattern => api.constructor.invalidateCache(pattern));
    },
    async getPeople(filters = {}) {
        const params = new URLSearchParams(filters);
        return api.get(`/people?${params}`);
    },

    async getPerson(id) {
        return api.get(`/people/${id}`);
    },

    async createPerson(data) {
        const result=await api.post('/people', data);
        this.invalidatePersonCaches();
        return result;
    },

    async updatePerson(id, data) {
        const result=await api.put(`/people/${id}`, data);
        this.invalidatePersonCaches();
        return result;
    },

    async deletePerson(id) {
        const result=await api.delete(`/people/${id}`);
        this.invalidatePersonCaches();
        return result;
    },

    async getPersonLedger(personId, options = {}) {
        return api.get(`/people/${personId}/ledger`, options);
    },

    async getTransactions(filters = {}) {
        const params = new URLSearchParams(filters);
        return api.get(`/karobar?${params}`);
    },

    async getTransaction(id) {
        return api.get(`/karobar/${id}`);
    },

    async createTransaction(data) {
        const result = await api.post('/karobar', data);
        this.invalidateCreditPurchaseCaches();
        return result;
    },

    async updateTransaction(id, data) {
        const result = await api.put(`/karobar/${id}`, data);
        this.invalidateCreditPurchaseCaches();
        return result;
    },

    async deleteTransaction(id, version = null) {
        const result = await api.delete(`/karobar/${id}`, version ? { base_version: version } : null);
        this.invalidateCreditPurchaseCaches();
        return result;
    },

    async getDashboard() {
        return api.get('/karobar/dashboard');
    },

    async getReports(filters = {}) {
        const params = new URLSearchParams(filters);
        return api.get(`/karobar/reports?${params}`);
    },

    async getCreditReports(filters = {}) {
        const params = new URLSearchParams(filters);
        return api.get(`/karobar/reports?${params}`);
    },

    async getAIAnalysis() {
        return api.get('/karobar/ai-analysis');
    },

    async createRepayment(data) {
        const result = await api.post('/karobar/repayment', data);
        this.invalidateCreditPurchaseCaches();
        return result;
    },

    async createReceiving(data) {
        const result = await api.post('/karobar/receiving', data);
        this.invalidateCreditPurchaseCaches();
        return result;
    }
};

window.karobarAPI = karobarAPI;
