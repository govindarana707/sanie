// Karobar API Module
const karobarAPI = {
    async getPeople(filters = {}) {
        const params = new URLSearchParams(filters);
        return api.get(`/people?${params}`);
    },

    async getPerson(id) {
        return api.get(`/people/${id}`);
    },

    async createPerson(data) {
        return api.post('/people', data);
    },

    async updatePerson(id, data) {
        return api.put(`/people/${id}`, data);
    },

    async deletePerson(id) {
        return api.delete(`/people/${id}`);
    },

    async getPersonLedger(personId) {
        return api.get(`/people/${personId}/ledger`);
    },

    async getTransactions(filters = {}) {
        const params = new URLSearchParams(filters);
        return api.get(`/karobar?${params}`);
    },

    async getTransaction(id) {
        return api.get(`/karobar/${id}`);
    },

    async createTransaction(data) {
        return api.post('/karobar', data);
    },

    async updateTransaction(id, data) {
        return api.put(`/karobar/${id}`, data);
    },

    async deleteTransaction(id) {
        return api.delete(`/karobar/${id}`);
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
        return api.post('/karobar/repayment', data);
    },

    async createReceiving(data) {
        return api.post('/karobar/receiving', data);
    }
};

window.karobarAPI = karobarAPI;
