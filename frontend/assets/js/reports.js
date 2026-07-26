// Reports Management Module
class ReportsManager {
    constructor() {
        this.init();
    }

    init() {
        this.setupEventListeners();
    }

    onMount() {
        this.renderReportsUI();
    }

    onUnmount() {
        // Cleanup if needed
    }

    setupEventListeners() {
        // Report event listeners
    }

    renderReportsUI() {
        const container = document.querySelector('#reports-page .reports-content');
        if (!container) return;

        container.innerHTML = `
            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="card-premium p-4 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="category-icon bg-primary bg-opacity-10 text-primary">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <div>
                                <h5 class="mb-1">Income & Expense Report</h5>
                                <small class="text-muted">Summary of all transactions</small>
                            </div>
                        </div>
                        <p class="text-muted small">Download or print a comprehensive ledger of all income and expenses for custom date ranges.</p>
                        <div class="d-flex gap-2 mt-auto">
                            <button class="btn btn-outline-primary btn-sm flex-fill" onclick="reportsManager.exportReport('csv', 'Income_Expense')">
                                <i class="fas fa-file-csv me-1"></i> Export CSV
                            </button>
                            <button class="btn btn-primary btn-sm flex-fill" onclick="window.print()">
                                <i class="fas fa-print me-1"></i> Print
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="card-premium p-4 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="category-icon bg-success bg-opacity-10 text-success">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <div>
                                <h5 class="mb-1">Category Breakdown</h5>
                                <small class="text-muted">Detailed spending by category</small>
                            </div>
                        </div>
                        <p class="text-muted small">View exact spending distributions across expense categories and subcategories.</p>
                        <div class="d-flex gap-2 mt-auto">
                            <button class="btn btn-outline-primary btn-sm flex-fill" onclick="reportsManager.exportReport('csv', 'Categories')">
                                <i class="fas fa-file-csv me-1"></i> Export CSV
                            </button>
                            <button class="btn btn-primary btn-sm flex-fill" onclick="window.print()">
                                <i class="fas fa-print me-1"></i> Print
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="card-premium p-4 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="category-icon bg-warning bg-opacity-10 text-warning">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div>
                                <h5 class="mb-1">Budget Health Report</h5>
                                <small class="text-muted">Budget utilization summary</small>
                            </div>
                        </div>
                        <p class="text-muted small">Detailed audit of active budget caps, actual spending, and threshold alerts.</p>
                        <div class="d-flex gap-2 mt-auto">
                            <button class="btn btn-outline-primary btn-sm flex-fill" onclick="reportsManager.exportReport('csv', 'Budgets')">
                                <i class="fas fa-file-csv me-1"></i> Export CSV
                            </button>
                            <button class="btn btn-primary btn-sm flex-fill" onclick="window.print()">
                                <i class="fas fa-print me-1"></i> Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    async exportReport(type = 'csv', name = 'Report') {
        try {
            NotificationService.info(`Generating ${name} ${type.toUpperCase()} report...`);
            const response = await transactionsAPI.getAll();
            if (response.success && response.data.length > 0) {
                const headers = ['Date', 'Description', 'Category', 'Account', 'Type', 'Amount'];
                const csvRows = [headers.join(',')];

                response.data.forEach(t => {
                    csvRows.push([
                        `"${t.date}"`,
                        `"${(t.description || '').replace(/"/g, '""')}"`,
                        `"${(t.category_name || '').replace(/"/g, '""')}"`,
                        `"${(t.account_name || '').replace(/"/g, '""')}"`,
                        `"${t.type}"`,
                        t.amount
                    ].join(','));
                });

                const blob = new Blob([csvRows.join('\n')], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `SanIE_${name}_Report_${new Date().toISOString().split('T')[0]}.csv`;
                a.click();
                window.URL.revokeObjectURL(url);
                NotificationService.success(`${name} report downloaded successfully`);
            } else {
                NotificationService.warning('No data available to export');
            }
        } catch (error) {
            console.error('Export report failed:', error);
            NotificationService.error('Failed to export report');
        }
    }
}

window.ReportsManager = ReportsManager;
