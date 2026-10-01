<!DOCTYPE html>
<html lang="en" data-bs-theme="light" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f172a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SanIE">
    <meta name="application-name" content="SanIE">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/favicon/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/icons/apple-touch-icon.png">
    <title>Transaction Ledger - SanIE</title>

    <!-- Resource Hints -->
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">

    <!-- Inter Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.min.css" rel="stylesheet">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/transaction.css?v=4">
</head>
<body>
    <div class="ledger-page">
        <!-- Sidebar -->
        <aside class="sidebar sidebar-premium" role="navigation" aria-label="Sidebar">
            <div class="sidebar-header">
                <h1 class="logo" aria-label="SanIE">SanIE</h1>
            </div>
            <nav class="sidebar-nav" aria-label="Main navigation">
                <a href="index.html#dashboard" class="nav-item" data-page="dashboard">
                    <i class="bi bi-grid-1x2-fill"></i>
                    <span>Dashboard</span>
                </a>
                <a href="index.html#transactions" class="nav-item" data-page="transactions">
                    <i class="bi bi-arrow-left-right"></i>
                    <span>Transactions</span>
                </a>
                <a href="index.html#budgets" class="nav-item" data-page="budgets">
                    <i class="bi bi-wallet2"></i>
                    <span>Budgets</span>
                </a>
                <a href="index.html#goals" class="nav-item" data-page="goals">
                    <i class="bi bi-bullseye"></i>
                    <span>Goals</span>
                </a>
                <a href="index.html#savings" class="nav-item" data-page="savings">
                    <i class="bi bi-piggy-bank"></i>
                    <span>Savings</span>
                </a>
                <div class="nav-divider" role="separator"></div>
                <a href="index.html#accounts" class="nav-item" data-page="accounts">
                    <i class="bi bi-wallet2"></i>
                    <span>Accounts</span>
                </a>
                <a href="transaction.php" class="nav-item active" data-page="ledger">
                    <i class="bi bi-journal-text"></i>
                    <span>Ledger</span>
                </a>
                <div class="nav-divider" role="separator"></div>
                <a href="index.html#categories" class="nav-item" data-page="categories">
                    <i class="bi bi-tags-fill"></i>
                    <span>Categories</span>
                </a>
                <div class="nav-divider" role="separator"></div>
                <a href="index.html#karobar-overview" class="nav-item has-submenu" data-page="karobar-overview">
                    <i class="bi bi-people-fill"></i>
                    <span>Karobar</span>
                    <i class="bi bi-chevron-down submenu-toggle"></i>
                </a>
                <div class="submenu" id="karobar-submenu">
                    <a href="index.html#karobar-overview" class="nav-item" data-page="karobar-overview">
                        <i class="bi bi-pie-chart-fill"></i>
                        <span>Overview</span>
                    </a>
                    <a href="index.html#karobar-people" class="nav-item" data-page="karobar-people">
                        <i class="bi bi-people-fill"></i>
                        <span>People</span>
                    </a>
                    <a href="index.html#karobar-transactions" class="nav-item" data-page="karobar-transactions">
                        <i class="bi bi-arrow-left-right"></i>
                        <span>Transactions</span>
                    </a>
                    <a href="index.html#karobar-reports" class="nav-item" data-page="karobar-reports">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                        <span>Reports</span>
                    </a>
                </div>
                <div class="nav-divider" role="separator"></div>
                <a href="index.html#analysis" class="nav-item" data-page="analysis">
                    <i class="bi bi-cpu"></i>
                    <span>AI Analysis</span>
                </a>
                <a href="index.html#reports" class="nav-item" data-page="reports">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                    <span>Reports</span>
                </a>
                <a href="index.html#notifications" class="nav-item" data-page="notifications">
                    <i class="bi bi-bell-fill"></i>
                    <span>Notifications</span>
                    <span class="nav-badge" id="sidebar-notif-badge" style="display:none;"></span>
                </a>
                <a href="index.html#settings" class="nav-item" data-page="settings">
                    <i class="bi bi-gear-fill"></i>
                    <span>Settings</span>
                </a>
            </nav>
            <div class="sidebar-footer">
                <div class="user-info-card">
                    <div class="user-avatar"><i class="bi bi-person-fill"></i></div>
                    <div class="user-details">
                        <p class="user-name" id="user-name">User Name</p>
                        <p class="user-email" id="user-email">user@email.com</p>
                    </div>
                    <button class="btn btn-icon btn-sm btn-logout-icon" id="logout-btn" title="Logout" aria-label="Logout">
                        <i class="bi bi-box-arrow-right"></i>
                    </button>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="ledger-main">
            <div class="container-fluid">
                <!-- Header -->
                <div class="ledger-header">
                    <div class="ledger-header-left">
                        <div class="ledger-header-badge"><i class="bi bi-journal-text"></i></div>
                        <div>
                            <h1 class="ledger-title">Transaction Ledger</h1>
                            <p class="ledger-subtitle">View, search, filter and export your complete financial history.</p>
                        </div>
                    </div>
                    <div class="ledger-header-actions">
                        <button class="btn btn-outline-primary ledger-action-btn" id="ledger-print" title="Print" data-bs-toggle="tooltip" data-bs-placement="bottom" aria-label="Print ledger">
                            <i class="bi bi-printer"></i>
                            <span>Print</span>
                        </button>
                        <button class="btn btn-outline-primary ledger-action-btn" id="ledger-export-csv" title="Export CSV" data-bs-toggle="tooltip" data-bs-placement="bottom" aria-label="Export to CSV">
                            <i class="bi bi-download"></i>
                            <span>CSV</span>
                        </button>
                        <button class="btn btn-outline-primary ledger-action-btn" id="ledger-refresh" title="Refresh" data-bs-toggle="tooltip" data-bs-placement="bottom" aria-label="Refresh data">
                            <i class="bi bi-arrow-clockwise"></i>
                            <span>Refresh</span>
                        </button>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="ldg-cards" id="ledger-summary">
                    <div class="ldg-card ldg-card-opening">
                        <div class="ldg-card-icon"><i class="bi bi-calculator"></i></div>
                        <div class="ldg-card-body">
                            <p class="ldg-card-label">Opening Balance</p>
                            <p class="ldg-card-value" id="ldg-opening-balance">--</p>
                        </div>
                    </div>
                    <div class="ldg-card ldg-card-income">
                        <div class="ldg-card-icon"><i class="bi bi-arrow-down-left"></i></div>
                        <div class="ldg-card-body">
                            <p class="ldg-card-label">Period Income</p>
                            <p class="ldg-card-value" id="ldg-period-income">--</p>
                        </div>
                    </div>
                    <div class="ldg-card ldg-card-expense">
                        <div class="ldg-card-icon"><i class="bi bi-arrow-up-right"></i></div>
                        <div class="ldg-card-body">
                            <p class="ldg-card-label">Period Expense</p>
                            <p class="ldg-card-value" id="ldg-period-expense">--</p>
                        </div>
                    </div>
                    <div class="ldg-card ldg-card-net">
                        <div class="ldg-card-icon"><i class="bi bi-graph-up-arrow"></i></div>
                        <div class="ldg-card-body">
                            <p class="ldg-card-label">Net Flow</p>
                            <p class="ldg-card-value" id="ldg-period-net">--</p>
                        </div>
                    </div>
                    <div class="ldg-card ldg-card-count">
                        <div class="ldg-card-icon"><i class="bi bi-receipt-cutoff"></i></div>
                        <div class="ldg-card-body">
                            <p class="ldg-card-label">Transactions</p>
                            <p class="ldg-card-value" id="ldg-period-count">--</p>
                        </div>
                    </div>
                    <div class="ldg-card ldg-card-closing">
                        <div class="ldg-card-icon"><i class="bi bi-safe"></i></div>
                        <div class="ldg-card-body">
                            <p class="ldg-card-label">Closing Balance</p>
                            <p class="ldg-card-value" id="ldg-closing-balance">--</p>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card filter-card">
                    <div class="card-body">
                        <div class="ldg-filter-bar" id="ledger-filters">
                            <!-- Search Row -->
                            <div class="ldg-filter-row ldg-filter-search-row">
                                <div class="ldg-filter-search">
                                    <i class="bi bi-search"></i>
                                    <input type="search" id="ledger-search" placeholder="Search transactions..." aria-label="Search transactions">
                                </div>
                            </div>
                            <!-- Filter Controls Row -->
                            <div class="ldg-filter-row ldg-filter-controls-row">
                                <select class="form-select ldg-filter-select" id="ledger-type" aria-label="Filter by type">
                                    <option value="">All Types</option>
                                    <option value="income">Income</option>
                                    <option value="expense">Expense</option>
                                    <option value="transfer">Transfer</option>
                                </select>
                                <select class="form-select ldg-filter-select" id="ledger-category" aria-label="Filter by category">
                                    <option value="">All Categories</option>
                                </select>
                                <select class="form-select ldg-filter-select" id="ledger-account" aria-label="Filter by account">
                                    <option value="">All Accounts</option>
                                </select>
                                <input type="date" id="ledger-date-start" class="form-control ldg-filter-date" title="From" aria-label="From date">
                                <input type="date" id="ledger-date-end" class="form-control ldg-filter-date" title="To" aria-label="To date">
                                <button class="btn btn-success ldg-btn-apply" id="ledger-apply-filters" aria-label="Apply filters">
                                    <i class="bi bi-check-lg"></i>
                                    <span>Apply</span>
                                </button>
                                <button class="btn btn-outline-secondary ldg-btn-reset" id="ledger-clear-filters" aria-label="Reset filters">
                                    <i class="bi bi-x-lg"></i>
                                    <span>Reset</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="ldg-table-area">
                    <div class="table-responsive ldg-table-wrapper" id="ledger-table-wrapper">
                        <table id="ledger-table" class="table table-striped table-hover mb-0" style="width:100%">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Description</th>
                                    <th scope="col">Category</th>
                                    <th scope="col">Account</th>
                                    <th scope="col" class="text-end">Amount</th>
                                    <th scope="col" class="text-end">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>

                        <div class="d-flex align-items-center justify-content-between p-3 border-top" id="ledger-pagination">
                            <div class="d-flex align-items-center gap-2">
                                <label for="ledger-page-size" class="small text-muted mb-0">Rows</label>
                                <select id="ledger-page-size" class="form-select form-select-sm" style="width:auto"><option>10</option><option selected>25</option><option>50</option><option>100</option></select>
                            </div>
                            <small class="text-muted" id="ledger-page-info">Page 1 of 0</small>
                            <div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary" id="ledger-page-prev" onclick="ledgerManager.goToPage(ledgerManager.currentPage-1)" disabled>Previous</button><button class="btn btn-outline-secondary" id="ledger-page-next" onclick="ledgerManager.goToPage(ledgerManager.currentPage+1)" disabled>Next</button></div>
                        </div>

                    <!-- Loading State -->
                    <div class="ldg-state ldg-loading" id="ledger-loading" style="display:none;">
                        <div class="ldg-state-icon"><i class="bi bi-arrow-repeat"></i></div>
                        <p>Loading ledger data...</p>
                    </div>

                    <!-- Empty State -->
                    <div class="ldg-state ldg-empty" id="ledger-empty" style="display:none;">
                        <div class="ldg-state-icon"><i class="bi bi-journal-text"></i></div>
                        <h5>No Transactions Found</h5>
                        <p>Try adjusting your filters or add transactions from the Dashboard.</p>
                        <a href="index.html#transactions" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-lg me-2"></i>Add Transaction
                        </a>
                    </div>

                    <!-- Error State -->
                    <div class="ldg-state ldg-error" id="ledger-error" style="display:none;">
                        <div class="ldg-state-icon" style="color:var(--sanie-expense)"><i class="bi bi-exclamation-triangle-fill"></i></div>
                        <h5>Something went wrong</h5>
                        <p class="error-message text-muted"></p>
                        <button class="btn btn-primary btn-sm mt-2" onclick="window.ledgerManager?.loadLedger()">
                            <i class="bi bi-arrow-clockwise me-2"></i>Try Again
                        </button>
                    </div>
                </div>
            </div>

                <!-- Detail Modal Container -->
                <div id="ledger-detail-container"></div>
            </div>
        </main>
    </div>

    <!-- Scripts -->

    <!-- jQuery -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

    <!-- Bootstrap 5.3 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25"></script>

    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <!-- Application modules -->
    <script src="assets/js/config.js"></script>
    <script src="assets/js/utils.js"></script>
    <script src="assets/js/api.js"></script>

    <!-- Auth check (standalone page — lightweight, no SPA DOM dependency) -->
    <script>
    (async function checkAuth() {
        let token = null;
        try { token = window.sessionStorage.getItem('token'); } catch (error) {}
        if (!token) { window.location.href = 'index.html'; return; }
        api.setToken(token);
        try {
            const res = await api.get('/auth/me');
            if (!res.success) throw new Error('Auth failed');
            const user = res.data;
            document.getElementById('user-name').textContent =
                `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'User';
            document.getElementById('user-email').textContent = user.email || '';
            document.getElementById('logout-btn').addEventListener('click', () => {
                api.clearToken();
                window.location.href = 'index.html';
            });
        } catch (e) {
            api.clearToken();
            window.location.href = 'index.html';
        }
    })();
    </script>

    <!-- Transaction Ledger Module -->
    <script src="assets/js/transaction.js?v=3"></script>
</body>
</html>
