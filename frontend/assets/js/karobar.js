// Karobar Module - Borrow & Lend Management
class KarobarManager {
    constructor() {
        this.currentPage = 'karobar-overview';
        this.people = [];
        this.transactions = [];
        this.currentPerson = null;
        this.filters = {};
        this._listeners = {};
        this._mounted = false;
        this.dataTable = null;
        this._editingPersonId = null;
        this._editingTxId = null;
        this._transactionSubmitting = false;
        this._paymentRequestId = null;
        this._profileRequestController = null;
        this._profileRequestSequence = 0;
        this._profileRouteSignal = null;
        this._profileRouteAbortHandler = null;
        this._requestedPersonId = null;
        this.peoplePage = 1;
        this.peoplePagination = {};
        this.peopleSearch = '';
        this._peoplePageRows = [];
        this.transactionsPage = 1;
        this.transactionsPagination = {};
        this.transactionsSummary = {};
        this.profilePage = 1;
        this.profilePagination = {};
        this.historyPageSize = 25;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this.setupEventListeners();
        window.addEventListener('app:data-changed', this._onDataChanged = () => {
            const page = this.currentPage || 'karobar-overview';
            if (page === 'karobar-overview') this.loadOverview();
            else if (page === 'karobar-people') this.loadPeople();
            else if (page === 'karobar-person-profile' && this.currentPerson?.id) this.loadPersonProfile(this.currentPerson.id);
            else if (page === 'karobar-transactions') this.loadTransactions();
            else if (page === 'karobar-reports') this.loadReports();
            else if (page === 'karobar-credit-reports') this.loadCreditReports();
            else if (page === 'karobar-ai-analysis') this.loadAIAnalysis();
        });
    }

    onUnmount() {
        this._mounted = false;
        if (this._onDataChanged) {
            window.removeEventListener('app:data-changed', this._onDataChanged);
        }
        this.destroyDataTable();
        this.removeEventListeners();
    }

    destroyDataTable() {
        if (this.dataTable) {
            try { this.dataTable.clear(); this.dataTable.destroy(); } catch (e) {}
            this.dataTable = null;
        }
        const tableEl = document.getElementById('karobar-transactions-table');
        if (tableEl && window.$ && $.fn.DataTable && $.fn.DataTable.isDataTable(tableEl)) {
            try { $(tableEl).DataTable().clear().destroy(); } catch (e) {}
        }
    }

    setupEventListeners() {
        this._listeners.karobarOverview = () => this.loadOverview();
        this._listeners.karobarPeople = () => this.loadPeople();
        this._listeners.karobarTransactions = () => this.loadTransactions();
        this._listeners.karobarReports = () => this.loadReports();
        this._listeners.karobarCreditReports = () => this.loadCreditReports();
        this._listeners.karobarAIAnalysis = () => this.loadAIAnalysis();
    }

    removeEventListeners() {}

    formatCurrency(amount) {
        const num = Math.abs(parseFloat(amount) || 0);
        return 'Rs ' + num.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        return Formatters ? Formatters.date(dateStr) : dateStr;
    }

    getLocalDateValue() {
        return DateUtils.getKathmanduDateString();
    }

    createClientRequestId() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        return `karobar-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    getPersonInitials(name) {
        if (!name) return '?';
        return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
    }

    getPersonTypeIcon(type) {
        const icons = { person: '\u{1F464}', friend: '\u{1F91D}', family: '\u{1F3E0}', shop: '\u{1F3EA}', vendor: '\u{1F4E6}', business: '\u{1F3E2}', other: '\u{2753}' };
        return icons[type] || '\u{2753}';
    }

    getBalanceClass(balance) {
        const b = parseFloat(balance);
        if (b > 0) return 'positive';
        if (b < 0) return 'negative';
        return 'zero';
    }

    getBalanceLabel(balance) {
        const b = parseFloat(balance);
        if (b > 0) return 'Will Receive';
        if (b < 0) return 'Need to Pay';
        return 'Settled';
    }

    getStatusBadge(balance) {
        const b = parseFloat(balance);
        if (b > 0) return '<span class="karobar-status-badge receivable" title="This person still needs to pay you."><i class="fas fa-circle" style="font-size:0.5rem;"></i> Will Receive</span>';
        if (b < 0) return '<span class="karobar-status-badge payable" title="You still need to pay this person."><i class="fas fa-circle" style="font-size:0.5rem;"></i> Need to Pay</span>';
        return '<span class="karobar-status-badge settled" title="All balances settled."><i class="fas fa-circle" style="font-size:0.5rem;"></i> Settled</span>';
    }

    getTypeBadge(type) {
        const labels = { lent: 'Money Lent', borrowed: 'Money Borrowed', returned: 'Money Returned', repaid: 'Money Repaid', adjustment: 'Adjustment' };
        const icons = { lent: 'fa-arrow-up', borrowed: 'fa-arrow-down', returned: 'fa-undo', repaid: 'fa-check-circle', adjustment: 'fa-sliders-h' };
        return `<span class="karobar-type-badge ${type}"><i class="fas ${icons[type] || 'fa-circle'}"></i> ${labels[type] || type}</span>`;
    }

    // ========================
    // OVERVIEW / DASHBOARD
    // ========================
    async loadOverview() {
        AjaxService?.showSkeleton('karobar-overview-content');
        try {
            const result = await karobarAPI.getDashboard();
            if (result.success) {
                this.renderOverviewDashboard(result.data);
            } else {
                this.renderOverviewDashboard(null);
            }
        } catch (error) {
            console.error('Failed to load Karobar overview:', error);
            this.renderOverviewDashboard(null);
        } finally {
            AjaxService?.hideSkeleton('karobar-overview-content');
        }
    }

    renderOverviewDashboard(data) {
        const container = document.getElementById('karobar-overview-content');
        if (!container) return;

        if (!data) {
            container.innerHTML = this.getEmptyState('No Karobar Data Yet', 'Add your first person and transaction to start tracking money given and taken.', 'karobar-people');
            return;
        }

        const totalReceivable = parseFloat(data.total_receivable) || 0;
        const totalPayable = parseFloat(data.total_payable) || 0;
        const net = parseFloat(data.net_karobar) || 0;
        const peopleCount = parseInt(data.people_count) || 0;
        const overdue = parseFloat(data.overdue_amount) || 0;
        const overdueCount = parseInt(data.overdue_count) || 0;
        const overdueReceivable = parseFloat(data.overdue_receivable) || 0;
        const overduePayable = parseFloat(data.overdue_payable) || 0;
        const largestDebtor = data.largest_debtor || null;
        const largestCreditor = data.largest_creditor || null;
        const recentTx = data.recent_transactions || [];
        const peopleBalances = data.people_balances || [];
        const monthlyData = data.monthly_data || [];

        container.innerHTML = `
            <div class="karobar-stats-grid">
                <div class="karobar-stat-card">
                    <div class="karobar-stat-icon receivable"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="karobar-stat-content">
                        <p class="stat-label">Total You Will Receive</p>
                        <p class="stat-value" style="color:#10B981;">${this.formatCurrency(totalReceivable)}</p>
                        <p class="stat-sub">Others need to pay you</p>
                    </div>
                </div>
                <div class="karobar-stat-card">
                    <div class="karobar-stat-icon payable"><i class="fas fa-hand-holding"></i></div>
                    <div class="karobar-stat-content">
                        <p class="stat-label">Total You Need to Pay</p>
                        <p class="stat-value" style="color:#EF4444;">${this.formatCurrency(totalPayable)}</p>
                        <p class="stat-sub">You need to pay others</p>
                    </div>
                </div>
                <div class="karobar-stat-card">
                    <div class="karobar-stat-icon net"><i class="fas fa-balance-scale"></i></div>
                    <div class="karobar-stat-content">
                        <p class="stat-label">Net Karobar</p>
                        <p class="stat-value" style="color:${net >= 0 ? '#10B981' : '#EF4444'};">${this.formatCurrency(Math.abs(net))}</p>
                        <p class="stat-sub">${net >= 0 ? 'Net you will receive' : 'Net you need to pay'}</p>
                    </div>
                </div>
                <div class="karobar-stat-card">
                    <div class="karobar-stat-icon payable"><i class="fas fa-clock"></i></div>
                    <div class="karobar-stat-content">
                        <p class="stat-label">Overdue Outstanding</p>
                        <p class="stat-value" style="color:#EF4444;">${this.formatCurrency(overdue)}</p>
                        <p class="stat-sub">${overdueCount} item${overdueCount === 1 ? '' : 's'} · receive ${this.formatCurrency(overdueReceivable)} · pay ${this.formatCurrency(overduePayable)}</p>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4 karobar-overview-charts">
                <div class="col-lg-8">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-chart-bar me-2 text-primary"></i>Monthly Overview</h5>
                        <div id="karobar-monthly-chart" class="karobar-overview-chart"></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-chart-pie me-2 text-warning"></i>Received vs Paid</h5>
                        <div id="karobar-ratio-chart" class="karobar-overview-chart"></div>
                    </div>
                </div>
            </div>

            <div class="row g-4 karobar-overview-lists">
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-users me-2 text-success"></i>People Outstanding</h5>
                            <a href="#" class="text-primary small fw-semibold" onclick="window.appRouter?.navigate('karobar-people'); return false;">View All</a>
                        </div>
                        <div id="karobar-people-balances-list">
                            ${this.renderPeopleBalancesList(peopleBalances)}
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-history me-2 text-info"></i>Recent Transactions</h5>
                            <a href="#" class="text-primary small fw-semibold" onclick="window.appRouter?.navigate('karobar-transactions'); return false;">View All</a>
                        </div>
                        <div id="karobar-recent-tx-list">
                            ${this.renderRecentTransactionsList(recentTx)}
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.renderOverviewCharts(monthlyData, totalReceivable, totalPayable);
    }

    renderPeopleBalancesList(people) {
        if (!people || people.length === 0) {
            return '<p class="text-muted text-center py-3">No people added yet</p>';
        }

        return people.slice(0, 6).map(p => {
            const receivable = parseFloat(p.receivable_outstanding) || 0;
            const payable = parseFloat(p.payable_outstanding) || 0;
            return `
                <div class="karobar-overview-list-row d-flex align-items-center justify-content-between py-2 border-bottom" style="cursor:pointer;" onclick="window.karobarManager?.showPersonLedger(${p.id})">
                    <div class="karobar-overview-list-primary d-flex align-items-center gap-2">
                        <div class="karobar-person-card person-avatar" style="width:36px;height:36px;font-size:0.8rem;border-radius:50%;background:linear-gradient(135deg,#6366f1,#4f46e5);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;">
                            ${this.getPersonInitials(p.name)}
                        </div>
                        <span class="fw-semibold" style="font-size:0.9rem;">${Formatters.escapeHTML(p.name)}</span>
                    </div>
                    <span class="karobar-overview-list-amounts text-end" style="font-size:0.85rem;">
                        <span class="d-block balance-positive">Receive ${this.formatCurrency(receivable)}</span>
                        <span class="d-block balance-negative">Pay ${this.formatCurrency(payable)}</span>
                    </span>
                </div>
            `;
        }).join('');
    }

    renderRecentTransactionsList(transactions) {
        if (!transactions || transactions.length === 0) {
            return '<p class="text-muted text-center py-3">No transactions yet</p>';
        }

        return transactions.slice(0, 6).map(tx => {
            return `
                <div class="karobar-overview-list-row d-flex align-items-center justify-content-between py-2 border-bottom">
                    <div class="karobar-overview-list-primary d-flex align-items-center gap-2">
                        ${this.getTypeBadge(tx.type)}
                        <div>
                            <span class="fw-semibold d-block" style="font-size:0.9rem;">${Formatters.escapeHTML(tx.person_name || 'Unknown')}</span>
                            <small class="text-muted">${this.formatDate(tx.transaction_date)}</small>
                        </div>
                    </div>
                    <span class="karobar-overview-list-amount fw-bold" style="font-size:0.95rem;color:${['lent','repaid'].includes(tx.type) ? '#EF4444' : '#10B981'};">
                        ${['lent','repaid'].includes(tx.type) ? '-' : '+'}${this.formatCurrency(tx.amount)}
                    </span>
                </div>
            `;
        }).join('');
    }

    renderOverviewCharts(monthlyData, receivable, payable) {
        if (window.ChartService) {
            const barOptions = {
                series: [
                    { name: 'Lent', data: monthlyData.map(d => parseFloat(d.lent) || 0) },
                    { name: 'Borrowed', data: monthlyData.map(d => parseFloat(d.borrowed) || 0) },
                    { name: 'Returned', data: monthlyData.map(d => parseFloat(d.returned) || 0) },
                    { name: 'Repaid', data: monthlyData.map(d => parseFloat(d.repaid) || 0) }
                ],
                chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
                plotOptions: { bar: { borderRadius: 6, columnWidth: '60%' } },
                colors: ['#EF4444', '#10B981', '#3B82F6', '#F59E0B'],
                dataLabels: { enabled: false },
                xaxis: {
                    categories: monthlyData.map(d => d.month),
                    labels: { style: { colors: '#6B7280', fontSize: '12px' } }
                },
                yaxis: {
                    labels: { style: { colors: '#6B7280', fontSize: '12px' }, formatter: (val) => Formatters.compactCurrency(val) }
                },
                legend: { position: 'top', horizontalAlign: 'right' },
                responsive: [{ breakpoint: 600, options: {
                    chart: { height: 220 },
                    legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '11px' },
                    xaxis: { labels: { rotate: -45, style: { fontSize: '10px' } } },
                    yaxis: { labels: { style: { fontSize: '10px' } } }
                }}],
                tooltip: { y: { formatter: (val) => Formatters.currency(val) } }
            };
            ChartService.create('#karobar-monthly-chart', barOptions);

            const pieOptions = {
                series: [Math.max(0, receivable), Math.max(0, payable)],
                chart: { type: 'donut', height: 280, fontFamily: 'Inter, sans-serif' },
                labels: ['You Will Receive', 'You Need to Pay'],
                colors: ['#10B981', '#EF4444'],
                plotOptions: { pie: { donut: { size: '70%' } } },
                dataLabels: { enabled: false },
                legend: { position: 'bottom' },
                responsive: [{ breakpoint: 600, options: {
                    chart: { height: 220 },
                    legend: { fontSize: '11px' }
                }}],
                tooltip: { y: { formatter: (val) => Formatters.currency(val) } }
            };
            ChartService.create('#karobar-ratio-chart', pieOptions);
        }
    }

    getEmptyState(title, message, targetPage) {
        const btnAction = targetPage === 'karobar-people'
            ? 'window.karobarManager && window.karobarManager.showAddPersonModal()'
            : `window.appRouter && window.appRouter.navigate('${targetPage}')`;
        return `
            <div class="text-center py-5">
                <div style="width:80px;height:80px;border-radius:50%;background:rgba(99,102,241,0.1);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1.5rem;">
                    <i class="fas fa-handshake" style="font-size:2.5rem;color:#6366f1;"></i>
                </div>
                <h4 class="fw-bold mb-2">${title}</h4>
                <p class="text-muted mb-4" style="max-width:400px;margin:0 auto;">${message}</p>
                <button class="btn btn-primary btn-lg" onclick="${btnAction}">
                    <i class="fas fa-plus me-2"></i>Add Person
                </button>
            </div>
        `;
    }

    // ========================
    // PEOPLE
    // ========================
    async loadPeople() {
        AjaxService?.showSkeleton('karobar-people-content');
        try {
            const result = await karobarAPI.getPeople({ status: 'active', search: this.peopleSearch || undefined, page: this.peoplePage, limit: 20 });
            if (result.success) {
                this._peoplePageRows = result.data?.people || [];
                this.peoplePagination = result.data?.pagination || {};
                await this._ensureAllPeople();
                this.renderPeople();
            }
        } catch (error) {
            console.error('Failed to load people:', error);
            this.renderPeople();
        } finally {
            AjaxService?.hideSkeleton('karobar-people-content');
        }
    }

    renderPeople() {
        const container = document.getElementById('karobar-people-content');
        if (!container) return;

        if (!this._peoplePageRows || this._peoplePageRows.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <div style="width:80px;height:80px;border-radius:50%;background:rgba(99,102,241,0.1);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1.5rem;">
                        <i class="fas fa-handshake" style="font-size:2.5rem;color:#6366f1;"></i>
                    </div>
                    <h4 class="fw-bold mb-2">No People Yet</h4>
                    <p class="text-muted mb-4" style="max-width:400px;margin:0 auto;">Add your first person to start tracking money given and taken.</p>
                    <button class="btn btn-primary btn-lg" onclick="window.karobarManager?.showAddPersonModal()">
                        <i class="fas fa-plus me-2"></i>Add First Person
                    </button>
                </div>
            `;
            return;
        }

        let html = `
            <div class="karobar-people-toolbar d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <div class="karobar-people-search-wrap d-flex align-items-center gap-2 flex-wrap">
                    <div class="input-group karobar-people-search">
                        <span class="input-group-text bg-transparent border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="karobar-people-search" class="form-control border-start-0" placeholder="Search people..." value="${Formatters.escapeHTML(this.peopleSearch || '')}">
                    </div>
                </div>
                <button class="btn btn-primary" onclick="window.karobarManager?.showAddPersonModal()">
                    <i class="fas fa-plus me-1"></i> Add Person
                </button>
            </div>
            <div class="karobar-people-grid">
        `;

        this._peoplePageRows.forEach(person => {
            const balance = parseFloat(person.balance) || 0;
            const receivable = parseFloat(person.receivable_outstanding) || 0;
            const payable = parseFloat(person.payable_outstanding) || 0;
            const txCount = parseInt(person.transaction_count) || 0;
            const positionStatus = receivable > 0 || payable > 0
                ? '<span class="karobar-status-badge receivable"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Active</span>'
                : this.getStatusBadge(0);

            html += `
                <div class="karobar-person-card" onclick="window.appRouter?.navigate('karobar-person-profile?id=${person.id}')">
                    <div class="person-status-badge">${positionStatus}</div>
                    <div class="person-card-head d-flex align-items-center gap-3 mb-3">
                        <div class="person-avatar">${this.getPersonInitials(person.name)}</div>
                        <div>
                            <div class="person-name">${Formatters.escapeHTML(person.name)}</div>
                            <div class="person-meta">
                                ${person.type ? `<span class="person-type-badge">${this.getPersonTypeIcon(person.type)} ${person.type}</span>` : ''}
                                ${person.phone ? '<i class="fas fa-phone me-1"></i>' + Formatters.escapeHTML(person.phone) : ''}
                            </div>
                        </div>
                    </div>
                    <div class="person-card-footer d-flex justify-content-between align-items-end">
                        <div>
                            <div class="text-muted small">Receivable</div>
                            <div class="person-balance balance-positive">${this.formatCurrency(receivable)}</div>
                            <div class="text-muted small mt-1">Payable</div>
                            <div class="person-balance balance-negative">${this.formatCurrency(payable)}</div>
                        </div>
                        <div class="person-card-actions text-end">
                            <div class="text-muted small">${txCount} transaction${txCount !== 1 ? 's' : ''}</div>
                            <div class="d-flex gap-1 mt-1">
                                <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); window.karobarManager?.showEditPersonModal(${person.id})" title="Edit"><i class="fas fa-pen"></i></button>
                                <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); window.karobarManager?.deletePerson(${person.id})" title="Delete"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });

        html += `</div>${this._paginationHTML(this.peoplePagination,'goToPeoplePage')}`;
        container.innerHTML = html;

        const searchInput = document.getElementById('karobar-people-search');
        if (searchInput) {
            let timer;searchInput.addEventListener('input',(e)=>{clearTimeout(timer);timer=setTimeout(()=>{this.peopleSearch=e.target.value.trim();this.peoplePage=1;this.loadPeople();},250);});
        }
    }

    goToPeoplePage(page){const total=Number(this.peoplePagination?.total_pages||1);this.peoplePage=Math.max(1,Math.min(total,Number(page)||1));this.loadPeople();}

    async _ensureAllPeople(){
        const first=await karobarAPI.getPeople({status:'active',page:1,limit:200});if(!first.success){this.people=[];return;}
        const rows=[...(first.data?.people||[])],pages=Number(first.data?.pagination?.total_pages||1);
        for(let page=2;page<=pages;page++){const next=await karobarAPI.getPeople({status:'active',page,limit:200});if(next.success)rows.push(...(next.data?.people||[]));}
        this.people=rows;
    }

    _paginationHTML(pagination,handler){
        const total=Number(pagination?.total_pages||0),page=Number(pagination?.page||1);if(total<=1)return'';
        return `<div class="karobar-pagination d-flex justify-content-between align-items-center mt-3"><span class="text-muted small">Page ${page} of ${total} · ${Number(pagination.total_rows||0)} records</span><div class="btn-group"><button class="btn btn-sm btn-outline-secondary" onclick="window.karobarManager?.${handler}(${page-1})" ${page<=1?'disabled':''}>Previous</button><button class="btn btn-sm btn-outline-secondary" onclick="window.karobarManager?.${handler}(${page+1})" ${page>=total?'disabled':''}>Next</button></div></div>`;
    }

    showAddPersonModal() {
        this._editingPersonId = null;
        if (window.modalService) {
            window.modalService.open({
                title: 'Add Person',
                subtitle: 'Add a new person for Money Given & Taken tracking.',
                icon: 'fa-user-plus',
                bodyHTML: this._getPersonFormHTML(),
                showFooter: true,
                onSave: () => this.savePerson()
            });
        }
    }

    async showEditPersonModal(id) {
        try {
            const result = await karobarAPI.getPerson(id);
            if (!result.success || !result.data) {
                NotificationService.error('Person not found');
                return;
            }
            const person = result.data;
            this._editingPersonId = id;

            if (window.modalService) {
                window.modalService.open({
                    title: 'Edit Person',
                    subtitle: 'Update person details.',
                    icon: 'fa-user-edit',
                    bodyHTML: this._getPersonFormHTML(person),
                    showFooter: true,
                    onSave: () => this.savePerson()
                });
            }
        } catch (error) {
            NotificationService.error('Failed to load person');
        }
    }

    _getPersonFormHTML(person = null) {
        const types = ['person','friend','family','shop','vendor','business','other'];
        const typeOptions = types.map(t => {
            const selected = person && person.type === t ? 'selected' : '';
            const label = t.charAt(0).toUpperCase() + t.slice(1);
            return `<option value="${t}" ${selected}>${label}</option>`;
        }).join('');

        return `
            <form id="karobar-person-form">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                            <input type="text" id="kp-name" class="form-control" value="${person ? Formatters.escapeHTML(person.name) : ''}" required placeholder="Enter person name">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Type</label>
                            <select id="kp-type" class="form-select">
                                <option value="person">Person</option>
                                ${typeOptions}
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="tel" id="kp-phone" class="form-control" value="${person ? Formatters.escapeHTML(person.phone || '') : ''}" placeholder="Phone number">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" id="kp-email" class="form-control" value="${person ? Formatters.escapeHTML(person.email || '') : ''}" placeholder="Email address">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Status</label>
                            <select id="kp-status" class="form-select">
                                <option value="active" ${person && person.status === 'active' ? 'selected' : ''}>Active</option>
                                <option value="archived" ${person && person.status === 'archived' ? 'selected' : ''}>Archived</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Address</label>
                            <input type="text" id="kp-address" class="form-control" value="${person ? Formatters.escapeHTML(person.address || '') : ''}" placeholder="Address">
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea id="kp-notes" class="form-control" rows="2" placeholder="Any notes about this person">${person ? Formatters.escapeHTML(person.notes || '') : ''}</textarea>
                        </div>
                    </div>
                </div>
            </form>
        `;
    }

    async savePerson() {
        const form = document.getElementById('karobar-person-form');
        if (!form.checkValidity()) { form.reportValidity(); return; }

        const data = {
            name: document.getElementById('kp-name').value.trim(),
            type: document.getElementById('kp-type')?.value || 'person',
            phone: document.getElementById('kp-phone').value.trim(),
            email: document.getElementById('kp-email').value.trim(),
            address: document.getElementById('kp-address').value.trim(),
            notes: document.getElementById('kp-notes').value.trim(),
            status: document.getElementById('kp-status').value
        };

        const saveBtn = document.querySelector('#modal-footer .btn-primary');
        AjaxService?.showButtonLoading(saveBtn);
        try {
            let result;
            if (this._editingPersonId) {
                result = await karobarAPI.updatePerson(this._editingPersonId, data);
            } else {
                result = await karobarAPI.createPerson(data);
            }

            if (result.success) {
                const isEdit = this._editingPersonId;
                NotificationService.success(isEdit ? 'Person updated successfully' : 'Person added successfully');
                this._editingPersonId = null;
                if (window.modalService) modalService.close();
                window.dispatchEvent(new CustomEvent('app:data-changed'));
                if (window.appRouter?.currentPage === 'transactions') await window.transactionsManager?.loadTransactions();
            } else {
                NotificationService.error(result.message || 'Failed to save person');
            }
        } catch (error) {
            NotificationService.error('Failed to save person');
        } finally {
            AjaxService?.hideButtonLoading(saveBtn);
        }
    }

    async deletePerson(id) {
        const confirmed = await NotificationService.confirm({
            title: 'Remove Person',
            text: 'If financial history exists, the person will be archived and all history and balances will be preserved. People without history are permanently deleted.',
            confirmButtonText: 'Yes, Remove'
        });
        if (!confirmed) return;

        try {
            const result = await karobarAPI.deletePerson(id);
            if (result.success) {
                NotificationService.success(result.message || (result.data?.action === 'archived' ? 'Person archived; financial history preserved' : 'Person deleted'));
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            }
        } catch (error) {
            NotificationService.error(error?.message || 'Failed to remove person');
        }
    }

    // ========================
    // PERSON LEDGER
    // ========================
    async showPersonLedger(personId) {
        window.appRouter?.navigate(`karobar-person-profile?id=${personId}`);
        return;
        try {
            const result = await karobarAPI.getPersonLedger(personId,{});
            if (!result.success || !result.data) {
                NotificationService.error('Person not found');
                return;
            }

            this.currentPerson = result.data.person;
            const ledger = result.data.ledger || [];
            const balance = parseFloat(result.data.balance) || 0;
            const person = this.currentPerson;
            const receivable = parseFloat(person.receivable_outstanding) || 0;
            const payable = parseFloat(person.payable_outstanding) || 0;

            const ledgerRows = ledger.map(tx => {
                const isDebit = ['lent', 'repaid'].includes(tx.type);
                const isCredit = ['borrowed', 'returned'].includes(tx.type);
                const rb = parseFloat(tx.running_balance) || 0;
                const rbClass = rb > 0 ? 'balance-positive' : (rb < 0 ? 'balance-negative' : 'balance-zero');

                return `
                    <tr>
                        <td>${this.formatDate(tx.transaction_date)}</td>
                        <td>${this.getTypeBadge(tx.type)}</td>
                        <td>${Formatters.escapeHTML(tx.description || tx.type)}</td>
                        <td class="debit">${isDebit ? this.formatCurrency(tx.amount) : '-'}</td>
                        <td class="credit">${isCredit ? this.formatCurrency(tx.amount) : '-'}</td>
                        <td class="${rbClass}">${this.formatCurrency(Math.abs(rb))}</td>
                    </tr>
                `;
            }).join('');

            const html = `
                <div class="karobar-profile-header">
                    <div class="karobar-profile-avatar">${this.getPersonInitials(person.name)}</div>
                    <div class="karobar-profile-info flex-grow-1">
                        <h3>${Formatters.escapeHTML(person.name)} ${person.type ? `<span class="person-type-badge">${this.getPersonTypeIcon(person.type)} ${person.type}</span>` : ''}</h3>
                        <div class="karobar-profile-meta">
                            ${person.phone ? `<span><i class="fas fa-phone"></i> ${Formatters.escapeHTML(person.phone)}</span>` : ''}
                            ${person.email ? `<span><i class="fas fa-envelope"></i> ${Formatters.escapeHTML(person.email)}</span>` : ''}
                            ${person.address ? `<span><i class="fas fa-map-marker-alt"></i> ${Formatters.escapeHTML(person.address)}</span>` : ''}
                        </div>
                        <div class="d-flex gap-3 mt-3 flex-wrap">
                            <div>
                                <span class="text-muted small">Receivable</span>
                                <div class="person-balance balance-positive" style="font-size:1.25rem;">${this.formatCurrency(receivable)}</div>
                            </div>
                            <div>
                                <span class="text-muted small">Payable</span>
                                <div class="person-balance balance-negative" style="font-size:1.25rem;">${this.formatCurrency(payable)}</div>
                            </div>
                            <div>
                                <span class="text-muted small">Total Lent</span>
                                <div class="fw-bold" style="color:#EF4444;">${this.formatCurrency(person.total_lent)}</div>
                            </div>
                            <div>
                                <span class="text-muted small">Total Borrowed</span>
                                <div class="fw-bold" style="color:#10B981;">${this.formatCurrency(person.total_borrowed)}</div>
                            </div>
                            <div>
                                <span class="text-muted small">Transactions</span>
                                <div class="fw-bold">${person.transaction_count || 0}</div>
                            </div>
                        </div>
                    </div>
                    <div class="karobar-quick-actions">
                        <button class="btn btn-primary" onclick="window.karobarManager?.showAddTransactionModal(${person.id})">
                            <i class="fas fa-plus"></i> New Transaction
                        </button>
                        <button class="btn btn-outline-secondary" onclick="window.karobarManager?.loadPeople(); window.appRouter?.navigate('karobar-people');">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                    </div>
                </div>

                <div class="karobar-chart-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-book me-2 text-primary"></i>Ledger - ${Formatters.escapeHTML(person.name)}</h5>
                        ${receivable > 0 || payable > 0 ? '<span class="karobar-status-badge receivable">Active</span>' : this.getStatusBadge(0)}
                    </div>
                    <div class="table-responsive">
                        <table class="karobar-ledger-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Debit (Out)</th>
                                    <th>Credit (In)</th>
                                    <th>Running Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${ledgerRows || '<tr><td colspan="6" class="text-center text-muted py-4">No transactions found</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;

            const container = document.getElementById('karobar-overview-content');
            if (container) {
                container.innerHTML = html;
            }
        } catch (error) {
            console.error('Failed to load ledger:', error);
            NotificationService.error('Failed to load ledger');
        }
    }

    // ========================
    // PERSON PROFILE PAGE
    // ========================
    cancelPersonProfileRequest(clearState = true) {
        this._profileRequestSequence++;
        if (this._profileRouteSignal && this._profileRouteAbortHandler) {
            this._profileRouteSignal.removeEventListener?.('abort', this._profileRouteAbortHandler);
        }
        this._profileRequestController?.abort();
        this._profileRequestController = null;
        this._profileRouteAbortHandler = null;
        if (clearState) {
            this.currentPerson = null;
            this._profileLedger = [];
            this._profileBalance = 0;
            this._requestedPersonId = null;
        }
    }

    async loadPersonProfile(personId, routeContext = null) {
        this.cancelPersonProfileRequest(true);
        this.profilePage=1;this._profileSearch='';this._profileFilter='all';
        const requestedPersonId = String(Number(personId) || '');
        const container = document.getElementById('karobar-person-profile-content');
        this._requestedPersonId = requestedPersonId;
        this._profileRouteSignal = routeContext?.signal || this._profileRouteSignal;
        const isRouteCurrent = routeContext?.isCurrent || (() => window.appRouter?.currentPage === 'karobar-person-profile');
        if (!requestedPersonId) {
            if (container) container.innerHTML = '<div class="text-center py-5"><i class="fas fa-user-slash fa-2x text-warning mb-3"></i><p class="text-muted">Person not found.</p></div>';
            return;
        }
        if (container) {
            container.innerHTML = '<div class="text-center py-5" role="status"><div class="spinner-border text-primary" aria-hidden="true"></div><p class="text-muted mt-3">Loading person profile…</p></div>';
        }
        const controller = new AbortController();
        this._profileRequestController = controller;
        const sequence = ++this._profileRequestSequence;
        if (this._profileRouteSignal?.aborted) controller.abort();
        else if (this._profileRouteSignal) {
            this._profileRouteAbortHandler = () => controller.abort();
            this._profileRouteSignal.addEventListener('abort', this._profileRouteAbortHandler, { once: true });
        }
        const isCurrent = () => sequence === this._profileRequestSequence
            && requestedPersonId === this._requestedPersonId
            && !controller.signal.aborted
            && isRouteCurrent();
        try {
            const result = await karobarAPI.getPersonLedger(personId, this._profileQuery(), { signal: controller.signal });
            if (!isCurrent()) return;
            if (!result.success || !result.data) {
                this.currentPerson = null;
                if (container) container.innerHTML = '<div class="text-center py-5"><i class="fas fa-user-slash fa-2x text-warning mb-3"></i><p class="text-muted">Person not found.</p></div>';
                return;
            }
            if (String(result.data?.person?.id) !== requestedPersonId) {
                this.currentPerson = null;
                if (container) container.innerHTML = '<div class="text-center py-5"><i class="fas fa-user-slash fa-2x text-warning mb-3"></i><p class="text-muted">Person not found.</p></div>';
                return;
            }
            this.currentPerson = result.data.person;
            this._profileLedger = result.data.ledger || [];
            this._profileBalance = parseFloat(result.data.balance) || 0;
            this.profilePagination=result.data.pagination||{};
            this._profileHistoryContext=result.data.history_context||{};
            this._profileSearch = this._profileSearch || '';
            this._profileFilter = this._profileFilter || 'all';
            this.renderPersonProfile();
        } catch (error) {
            if (!isCurrent() || error?.category === 'aborted_error' || error?.code === 'ABORTED_ERROR') return;
            this.currentPerson = null;
            if (container) {
                const message = [403, 404].includes(Number(error?.status)) ? 'Person not found.' : 'Failed to load profile. Please try again.';
                container.innerHTML = `<div class="text-center py-5"><i class="fas fa-exclamation-triangle fa-2x text-warning mb-3"></i><p class="text-muted">${message}</p><button class="btn btn-sm btn-outline-primary mt-2" onclick="window.appRouter?.navigate('karobar-people')">Go Back</button></div>`;
            }
        } finally {
            if (this._profileRequestController === controller) {
                if (this._profileRouteSignal && this._profileRouteAbortHandler) {
                    this._profileRouteSignal.removeEventListener?.('abort', this._profileRouteAbortHandler);
                }
                this._profileRequestController = null;
                this._profileRouteAbortHandler = null;
            }
        }
    }

    renderPersonProfile() {
        const container = document.getElementById('karobar-person-profile-content');
        if (!container) { console.error('[Karobar] Profile container not found'); return; }
        const person = this.currentPerson;
        if (!person) { console.error('[Karobar] No currentPerson set'); return; }

        try {

        const balance = this._profileBalance;
        const ledger = this._profileLedger;
        const receivable = parseFloat(person.receivable_outstanding) || 0;
        const payable = parseFloat(person.payable_outstanding) || 0;
        const hasOutstanding = receivable > 0 || payable > 0;

        const totalBorrowed = parseFloat(person.total_borrowed) || 0;
        const totalLent = parseFloat(person.total_lent) || 0;
        const totalReturned = parseFloat(person.total_returned) || 0;
        const totalRepaid = parseFloat(person.total_repaid) || 0;
        const txCount = parseInt(person.transaction_count) || 0;

        const statusHTML = hasOutstanding
            ? '<span class="karobar-status-badge receivable"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Active</span>'
            : '<span class="karobar-status-badge settled"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Settled</span>';

        const balanceMsg = hasOutstanding
            ? `<div class="d-flex gap-4 flex-wrap p-3 rounded-3" style="background:rgba(99,102,241,0.06);border:1px solid rgba(99,102,241,0.15);"><span class="fw-semibold" style="color:#059669;">You will receive ${this.formatCurrency(receivable)}.</span><span class="fw-semibold" style="color:#DC2626;">You need to pay ${this.formatCurrency(payable)}.</span></div>`
            : `<div class="d-flex align-items-center gap-2 p-3 rounded-3" style="background:rgba(156,163,175,0.08);border:1px solid rgba(156,163,175,0.15);"><span style="font-size:1.5rem;">&#x26AA;</span><span class="fw-semibold text-muted">All balances have been settled.</span></div>`;

        const outstandingCard = hasOutstanding
            ? `<div class="profile-outstanding-card receivable">
                    <div class="outstanding-label"><i class="fas fa-arrow-down me-1"></i> You Will Receive</div>
                    <div class="outstanding-amount" style="color:#059669;">${this.formatCurrency(receivable)}</div>
                    ${receivable > 0 ? `<button class="btn btn-success w-100 mt-3" onclick="window.karobarManager?.showReceivingModal(${person.id}, ${receivable})"><i class="fas fa-hand-holding-usd me-2"></i>Receive Payment</button>` : ''}
                    <hr>
                    <div class="outstanding-label"><i class="fas fa-arrow-up me-1"></i> You Need to Pay</div>
                    <div class="outstanding-amount" style="color:#DC2626;">${this.formatCurrency(payable)}</div>
                    ${payable > 0 ? `<button class="btn btn-danger w-100 mt-3" onclick="window.karobarManager?.showRepaymentModal(${person.id}, ${payable})"><i class="fas fa-paper-plane me-2"></i>Pay Now</button>` : ''}
               </div>`
            : `<div class="profile-outstanding-card settled">
                    <div class="outstanding-label"><i class="fas fa-check-circle me-1"></i> Settled</div>
                    <div class="outstanding-amount text-muted">Rs 0</div>
                    <div class="text-muted mt-3 text-center">No outstanding balance</div>
               </div>`;

        const filteredLedger = this._filterProfileLedger(ledger);
        let runningBal = 0;
        const ledgerRows = filteredLedger.map(tx => {
            const isDebit = ['lent', 'repaid'].includes(tx.type);
            const isCredit = ['borrowed', 'returned'].includes(tx.type);
            runningBal = parseFloat(tx.running_balance) || 0;
            const rbClass = runningBal > 0 ? 'text-success fw-bold' : (runningBal < 0 ? 'text-danger fw-bold' : 'text-muted fw-bold');
            const statusLabel = runningBal > 0 ? '<span class="badge bg-success-subtle text-success">Will Receive</span>' : (runningBal < 0 ? '<span class="badge bg-danger-subtle text-danger">Need to Pay</span>' : '<span class="badge bg-secondary-subtle text-secondary">Settled</span>');

            return `
                <tr class="profile-ledger-row">
                    <td class="text-nowrap">${this.formatDate(tx.transaction_date)}</td>
                    <td>${this.getTypeBadge(tx.type)}</td>
                    <td class="text-truncate" style="max-width:200px;" title="${Formatters.escapeHTML(tx.description || tx.type)}">${Formatters.escapeHTML(tx.description || '-')}</td>
                    <td class="text-end ${isDebit ? 'text-danger fw-semibold' : 'text-muted'}">${isDebit ? this.formatCurrency(tx.amount) : '-'}</td>
                    <td class="text-end ${isCredit ? 'text-success fw-semibold' : 'text-muted'}">${isCredit ? this.formatCurrency(tx.amount) : '-'}</td>
                    <td class="text-end ${rbClass}">${this.formatCurrency(Math.abs(runningBal))}</td>
                    <td class="text-center">${statusLabel}</td>
                    <td class="text-center">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="event.preventDefault();window.karobarManager?.editTransaction(${tx.id})"><i class="fas fa-pen me-2"></i>Edit</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="event.preventDefault();window.karobarManager?.deleteTransaction(${tx.id})"><i class="fas fa-trash me-2"></i>Delete</a></li>
                            </ul>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        const monthlyData = this._monthlyContextData(this._profileHistoryContext?.monthly||[]);
        const recentLedger=this._profileHistoryContext?.recent||ledger.slice(-10).reverse();

        let html = `
            <div class="d-flex align-items-center mb-4">
                <button class="btn btn-light btn-sm me-3" onclick="window.appRouter?.navigate('karobar-people')"><i class="fas fa-arrow-left"></i></button>
                <h4 class="mb-0 fw-bold">Person Profile</h4>
            </div>

            <div class="profile-header-card">
                <div class="row align-items-center g-4">
                    <div class="col-auto">
                        <div class="profile-avatar-lg">${this.getPersonInitials(person.name)}</div>
                    </div>
                    <div class="col">
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h3 class="mb-0 fw-bold">${Formatters.escapeHTML(person.name)}</h3>
                            ${person.type ? `<span class="person-type-badge">${this.getPersonTypeIcon(person.type)} ${person.type}</span>` : ''}
                            ${statusHTML}
                        </div>
                        <div class="d-flex gap-3 flex-wrap text-muted small">
                            ${person.phone ? `<span><i class="fas fa-phone me-1"></i>${Formatters.escapeHTML(person.phone)}</span>` : ''}
                            ${person.email ? `<span><i class="fas fa-envelope me-1"></i>${Formatters.escapeHTML(person.email)}</span>` : ''}
                            ${person.address ? `<span><i class="fas fa-map-marker-alt me-1"></i>${Formatters.escapeHTML(person.address)}</span>` : ''}
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-primary" onclick="window.karobarManager?.showAddTransactionModal(${person.id})"><i class="fas fa-plus me-1"></i>New Transaction</button>
                            <button class="btn btn-outline-secondary" onclick="window.karobarManager?.showEditPersonModal(${person.id})"><i class="fas fa-pen me-1"></i>Edit</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                    <div class="profile-stat-card balance-positive">
                        <div class="profile-stat-icon"><i class="fas fa-wallet"></i></div>
                        <div class="profile-stat-value balance-positive">${this.formatCurrency(receivable)}</div>
                        <div class="profile-stat-label">Outstanding Receivable</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="profile-stat-card borrowed">
                        <div class="profile-stat-icon"><i class="fas fa-arrow-down"></i></div>
                        <div class="profile-stat-value">${this.formatCurrency(payable)}</div>
                        <div class="profile-stat-label">Outstanding Payable</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="profile-stat-card lent">
                        <div class="profile-stat-icon"><i class="fas fa-arrow-up"></i></div>
                        <div class="profile-stat-value">${this.formatCurrency(totalLent)}</div>
                        <div class="profile-stat-label">Money Given To Them</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="profile-stat-card txcount">
                        <div class="profile-stat-icon"><i class="fas fa-receipt"></i></div>
                        <div class="profile-stat-value">${txCount}</div>
                        <div class="profile-stat-label">Total Transactions</div>
                    </div>
                </div>
            </div>

            ${balanceMsg}

            <div class="row g-4 mt-3">
                <div class="col-lg-8">
                    <div class="profile-quick-actions mb-4">
                        <button class="btn btn-primary" onclick="window.karobarManager?.showQuickTxModal(${person.id}, 'borrowed')"><i class="fas fa-plus me-1"></i> Money Taken</button>
                        <button class="btn btn-success" onclick="window.karobarManager?.showQuickTxModal(${person.id}, 'lent')"><i class="fas fa-plus me-1"></i> Money Given</button>
                        <button class="btn btn-info text-white" onclick="window.karobarManager?.showReceivingModal(${person.id}, ${receivable})" ${receivable <= 0 ? 'disabled' : ''}><i class="fas fa-hand-holding-usd me-1"></i> Receive Payment</button>
                        <button class="btn btn-warning" onclick="window.karobarManager?.showRepaymentModal(${person.id}, ${payable})" ${payable <= 0 ? 'disabled' : ''}><i class="fas fa-paper-plane me-1"></i> Paid Back</button>
                    </div>

                    <div class="profile-chart-card mb-4">
                        <h6 class="fw-bold mb-3"><i class="fas fa-chart-bar me-2 text-primary"></i>Money Taken vs Paid Back</h6>
                        <div id="profile-chart" style="height:250px;"></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    ${outstandingCard}

                    <div class="profile-timeline-card mt-4">
                        <h6 class="fw-bold mb-3"><i class="fas fa-stream me-2 text-primary"></i>Timeline</h6>
                        <div class="profile-timeline">
                            ${recentLedger.map(tx => {
                                const icon = {lent:'fa-arrow-up text-danger',borrowed:'fa-arrow-down text-success',returned:'fa-undo text-info',repaid:'fa-check-circle text-warning',adjustment:'fa-sliders-h text-secondary'}[tx.type] || 'fa-circle text-muted';
                                return `<div class="timeline-item"><div class="timeline-dot"><i class="fas ${icon}"></i></div><div class="timeline-content"><div class="timeline-date">${this.formatDate(tx.transaction_date)}</div><div class="timeline-label">${tx.type.charAt(0).toUpperCase() + tx.type.slice(1)}</div><div class="timeline-amount">${this.formatCurrency(tx.amount)}</div></div></div>`;
                            }).join('')}
                            ${recentLedger.length === 0 ? '<div class="text-center text-muted py-3">No transactions yet</div>' : ''}
                        </div>
                    </div>
                </div>
            </div>

            <div class="profile-ledger-card mt-4">
                <div class="karobar-transaction-toolbar d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h6 class="fw-bold mb-0"><i class="fas fa-book me-2 text-primary"></i>Ledger</h6>
                    <div class="d-flex gap-2 flex-wrap">
                        <select id="profile-filter" class="form-select form-select-sm" style="width:auto;" onchange="window.karobarManager?._onProfileFilterChange(this.value)">
                            <option value="all" ${this._profileFilter==='all'?'selected':''}>All Time</option>
                            <option value="today" ${this._profileFilter==='today'?'selected':''}>Today</option>
                            <option value="week" ${this._profileFilter==='week'?'selected':''}>This Week</option>
                            <option value="month" ${this._profileFilter==='month'?'selected':''}>This Month</option>
                            <option value="year" ${this._profileFilter==='year'?'selected':''}>This Year</option>
                        </select>
                        <div class="input-group input-group-sm" style="width:220px;">
                            <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="profile-search" class="form-control" placeholder="Search ledger..." value="${Formatters.escapeHTML(this._profileSearch||'')}" oninput="window.karobarManager?._onProfileSearchChange(this.value)">
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" onclick="window.karobarManager?.exportProfileLedgerCSV()"><i class="fas fa-download me-1"></i>Export</button>
                    </div>
                </div>
                <div class="table-responsive karobar-transactions-table-shell">
                    <table class="table table-hover align-middle mb-0 profile-ledger-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th class="text-end">Debit (Out)</th>
                                <th class="text-end">Credit (In)</th>
                                <th class="text-end">Outstanding</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${ledgerRows || '<tr><td colspan="8" class="text-center text-muted py-4">No transactions found</td></tr>'}
                        </tbody>
                    </table>
                </div>
                ${this._paginationHTML(this.profilePagination,'goToProfilePage')}
            </div>
        `;

        container.innerHTML = html;

        this._renderProfileChart(monthlyData);

        } catch (err) {
            console.error('[Karobar] Error rendering profile:', err);
            container.innerHTML = '<div class="text-center py-5"><i class="fas fa-exclamation-triangle fa-2x text-warning mb-3"></i><p class="text-muted">Error rendering profile.</p></div>';
        }
    }

    _filterProfileLedger(ledger) {
        let filtered = [...ledger];
        const term = (this._profileSearch || '').toLowerCase();
        const filter = this._profileFilter || 'all';
        const today = DateUtils.getKathmanduDateString();

        if (filter === 'today') {
            filtered = filtered.filter(tx => tx.transaction_date === today);
        } else if (filter === 'week') {
            const weekAgo = DateUtils.addCalendarDays(today, -7);
            filtered = filtered.filter(tx => tx.transaction_date >= weekAgo);
        } else if (filter === 'month') {
            const monthStart = DateUtils.getKathmanduRange('month').start;
            filtered = filtered.filter(tx => tx.transaction_date >= monthStart);
        } else if (filter === 'year') {
            const yearStart = DateUtils.getKathmanduRange('year').start;
            filtered = filtered.filter(tx => tx.transaction_date >= yearStart);
        }

        if (term) {
            filtered = filtered.filter(tx =>
                (tx.description || '').toLowerCase().includes(term) ||
                (tx.type || '').toLowerCase().includes(term) ||
                (tx.transaction_date || '').includes(term)
            );
        }
        return filtered;
    }

    _onProfileFilterChange(value) {
        this._profileFilter = value;
        this.profilePage=1;this.reloadProfileLedger();
    }

    _onProfileSearchChange(value) {
        this._profileSearch = value;
        clearTimeout(this._profileSearchTimer);this._profileSearchTimer=setTimeout(()=>{this.profilePage=1;this.reloadProfileLedger();},250);
    }

    _profileQuery(){const query={page:this.profilePage,limit:this.historyPageSize,search:this._profileSearch||undefined};const filter=this._profileFilter||'all';if(filter!=='all'){const today=DateUtils.getKathmanduDateString();if(filter==='today'){query.start_date=today;query.end_date=today;}else if(filter==='week')query.start_date=DateUtils.addCalendarDays(today,-7);else if(filter==='month')query.start_date=DateUtils.getKathmanduRange('month').start;else if(filter==='year')query.start_date=DateUtils.getKathmanduRange('year').start;}return query;}
    async reloadProfileLedger(){if(!this.currentPerson?.id)return;const result=await karobarAPI.getPersonLedger(this.currentPerson.id,this._profileQuery());if(result.success){this.currentPerson=result.data.person;this._profileLedger=result.data.ledger||[];this._profileBalance=parseFloat(result.data.balance)||0;this.profilePagination=result.data.pagination||{};this._profileHistoryContext=result.data.history_context||{};this.renderPersonProfile();}}
    goToProfilePage(page){const total=Number(this.profilePagination?.total_pages||1);this.profilePage=Math.max(1,Math.min(total,Number(page)||1));this.reloadProfileLedger();}

    _getMonthlyChartData(ledger) {
        const monthly = {};
        ledger.forEach(tx => {
            const month = (tx.transaction_date || '').substring(0, 7);
            if (!month) return;
            if (!monthly[month]) monthly[month] = { borrowed: 0, lent: 0, returned: 0, repaid: 0 };
            const amt = parseFloat(tx.amount) || 0;
            if (tx.type === 'borrowed') monthly[month].borrowed += amt;
            else if (tx.type === 'lent') monthly[month].lent += amt;
            else if (tx.type === 'returned') monthly[month].returned += amt;
            else if (tx.type === 'repaid') monthly[month].repaid += amt;
        });
        return monthly;
    }

    _monthlyContextData(rows){const monthly={};rows.forEach(row=>{const month=row.month;if(!monthly[month])monthly[month]={borrowed:0,lent:0,returned:0,repaid:0};if(Object.prototype.hasOwnProperty.call(monthly[month],row.type))monthly[month][row.type]=Number(row.amount)||0;});return monthly;}

    _renderProfileChart(monthlyData) {
        const chartEl = document.getElementById('profile-chart');
        if (!chartEl || !window.ApexCharts) return;

        const months = Object.keys(monthlyData).sort();
        const borrowedData = months.map(m => monthlyData[m].borrowed);
        const repaidData = months.map(m => monthlyData[m].repaid);
        const lentData = months.map(m => monthlyData[m].lent);
        const returnedData = months.map(m => monthlyData[m].returned);
        const labels = months.map(m => {
            const [y, mo] = m.split('-');
            return new Date(y, mo - 1).toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
        });

        const chart = new ApexCharts(chartEl, {
            chart: { type: 'bar', height: 250, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
            series: [
                { name: 'Borrowed', data: borrowedData },
                { name: 'Repaid', data: repaidData },
                { name: 'Lent', data: lentData },
                { name: 'Returned', data: returnedData }
            ],
            colors: ['#10B981', '#F59E0B', '#EF4444', '#3B82F6'],
            xaxis: { categories: labels.length ? labels : ['No Data'] },
            yaxis: { labels: { formatter: v => 'Rs ' + (v || 0).toLocaleString() } },
            plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
            legend: { position: 'top', fontSize: '12px' },
            grid: { borderColor: '#f1f5f9' }
        });
        chart.render();
        this._profileChart = chart;
    }

    async exportProfileLedgerCSV() {
        const ledger=[];const first=await karobarAPI.getPersonLedger(this.currentPerson.id,{...this._profileQuery(),page:1,limit:200});if(first.success)ledger.push(...(first.data.ledger||[]));const pages=Number(first.data?.pagination?.total_pages||1);for(let page=2;page<=pages;page++){const next=await karobarAPI.getPersonLedger(this.currentPerson.id,{...this._profileQuery(),page,limit:200});if(next.success)ledger.push(...(next.data.ledger||[]));}
        if (!ledger.length) { NotificationService.info('No data to export'); return; }
        let csv = 'Date,Type,Description,Debit,Credit,Running Outstanding\n';
        ledger.forEach(tx => {
            const isDebit = ['lent', 'repaid'].includes(tx.type);
            const isCredit = ['borrowed', 'returned'].includes(tx.type);
            csv += `"${tx.transaction_date}","${tx.type}","${(tx.description || '').replace(/"/g, '""')}",${isDebit ? tx.amount : ''},${isCredit ? tx.amount : ''},${tx.running_balance}\n`;
        });
        const blob = new Blob([csv], { type: 'text/csv' });
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = `ledger_${(this.currentPerson?.name || 'export').replace(/\s+/g, '_')}.csv`;
        a.click();
    }

    showQuickTxModal(personId, type) {
        if (!this.people || this.people.length === 0) {
            this._ensureAllPeople();
        }
        if (window.modalService) {
            window.modalService.open({
                title: type === 'borrowed' ? 'Money Taken' : 'Money Given',
                subtitle: type === 'borrowed' ? 'Record money taken from this person.' : 'Record money given to this person.',
                icon: type === 'borrowed' ? 'fa-arrow-down' : 'fa-arrow-up',
                bodyHTML: this._getTransactionFormHTML(personId),
                showFooter: true,
                onSave: () => this.saveTransaction()
            });
            setTimeout(() => {
                const typeEl = document.getElementById('kt-type');
                if (typeEl) typeEl.value = type;
            }, 50);
            if (window.DatePickerManager) { DatePickerManager.bind('#kt-date'); DatePickerManager.bind('#kt-due-date'); }
            this.loadAccountsForKarobarForm();
        }
    }

    showRepaymentModal(personId, outstandingAmount) {
        outstandingAmount = Number(outstandingAmount);
        if (!Number.isFinite(outstandingAmount) || outstandingAmount <= 0) {
            NotificationService.error('There is no outstanding debt to repay.');
            return;
        }
        const clientRequestId = this.createClientRequestId();
        let submitting = false;
        if (!this.people || this.people.length === 0) {
            this._ensureAllPeople();
        }
        const bodyHTML = `
            <form id="profile-repay-form">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
                            <div class="input-group"><span class="input-group-text">Rs</span><input type="number" id="profile-repay-amount" class="form-control" step="0.01" min="0.01" max="${outstandingAmount}" value="${outstandingAmount}" required></div>
                            <small class="text-muted">Outstanding: ${this.formatCurrency(outstandingAmount)}</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Payment Account</label>
                            <select id="profile-repay-account" class="form-select"></select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Date</label>
                            <input type="date" id="profile-repay-date" class="form-control" value="${this.getLocalDateValue()}" required>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" id="profile-repay-desc" class="form-control" placeholder="Payment note" value="Paid Back">
                        </div>
                    </div>
                </div>
            </form>
        `;
        if (window.modalService) {
            window.modalService.open({
                title: 'Paid Back',
                subtitle: `Outstanding: ${this.formatCurrency(outstandingAmount)}`,
                icon: 'fa-paper-plane',
                bodyHTML,
                showFooter: true,
                saveText: '<i class="fas fa-check me-1"></i> Confirm Payment',
                onSave: async () => {
                    if (submitting) return;
                    const form = document.getElementById('profile-repay-form');
                    if (!form?.checkValidity()) { form?.reportValidity(); return; }
                    const amount = parseFloat(document.getElementById('profile-repay-amount')?.value);
                    if (!Number.isFinite(amount) || amount <= 0 || amount > outstandingAmount) {
                        NotificationService.error(amount > outstandingAmount ? 'Payment exceeds the outstanding balance.' : 'Enter a valid positive amount.');
                        return;
                    }
                    const data = {
                        person_id: personId,
                        amount,
                        account_id: document.getElementById('profile-repay-account')?.value ? parseInt(document.getElementById('profile-repay-account').value) : null,
                        transaction_date: document.getElementById('profile-repay-date')?.value || this.getLocalDateValue(),
                        description: document.getElementById('profile-repay-desc')?.value || 'Paid Back',
                        client_request_id: clientRequestId,
                    };
                    if (!data.account_id) { NotificationService.error('Select a payment account.'); return; }
                    const saveBtn = document.getElementById('modal-save-btn');
                    submitting = true;
                    AjaxService?.showButtonLoading(saveBtn);
                    try {
                        const result = await karobarAPI.createRepayment(data);
                        if (result.success) {
                            NotificationService.success('Payment recorded');
                            modalService.close();
                            window.dispatchEvent(new CustomEvent('app:data-changed'));
                            this.loadPersonProfile(personId);
                        } else {
                            NotificationService.error(result.message || 'Payment failed');
                        }
                    } catch (e) {
                        NotificationService.error(e?.message || 'Payment failed');
                    } finally {
                        submitting = false;
                        AjaxService?.hideButtonLoading(saveBtn);
                    }
                }
            });
            this.loadAccountsForKarobarForm('profile-repay-account');
        }
    }

    showReceivingModal(personId, outstandingAmount) {
        outstandingAmount = Number(outstandingAmount);
        if (!Number.isFinite(outstandingAmount) || outstandingAmount <= 0) {
            NotificationService.error('There is no outstanding receivable to collect.');
            return;
        }
        const clientRequestId = this.createClientRequestId();
        let submitting = false;
        if (!this.people || this.people.length === 0) {
            this._ensureAllPeople();
        }
        const bodyHTML = `
            <form id="profile-receive-form">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
                            <div class="input-group"><span class="input-group-text">Rs</span><input type="number" id="profile-receive-amount" class="form-control" step="0.01" min="0.01" max="${outstandingAmount}" value="${outstandingAmount}" required></div>
                            <small class="text-muted">Outstanding: ${this.formatCurrency(outstandingAmount)}</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Deposit Account</label>
                            <select id="profile-receive-account" class="form-select"></select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Date</label>
                            <input type="date" id="profile-receive-date" class="form-control" value="${this.getLocalDateValue()}" required>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" id="profile-receive-desc" class="form-control" placeholder="Note" value="Payment received">
                        </div>
                    </div>
                </div>
            </form>
        `;
        if (window.modalService) {
            window.modalService.open({
                title: 'Receive Payment',
                subtitle: `Outstanding: ${this.formatCurrency(outstandingAmount)}`,
                icon: 'fa-hand-holding-usd',
                bodyHTML,
                showFooter: true,
                saveText: '<i class="fas fa-check me-1"></i> Confirm Receipt',
                onSave: async () => {
                    if (submitting) return;
                    const form = document.getElementById('profile-receive-form');
                    if (!form?.checkValidity()) { form?.reportValidity(); return; }
                    const amount = parseFloat(document.getElementById('profile-receive-amount')?.value);
                    if (!Number.isFinite(amount) || amount <= 0 || amount > outstandingAmount) {
                        NotificationService.error(amount > outstandingAmount ? 'Receiving amount exceeds the outstanding balance.' : 'Enter a valid positive amount.');
                        return;
                    }
                    const data = {
                        person_id: personId,
                        amount,
                        account_id: document.getElementById('profile-receive-account')?.value ? parseInt(document.getElementById('profile-receive-account').value) : null,
                        transaction_date: document.getElementById('profile-receive-date')?.value || this.getLocalDateValue(),
                        description: document.getElementById('profile-receive-desc')?.value || 'Payment received',
                        client_request_id: clientRequestId,
                    };
                    if (!data.account_id) { NotificationService.error('Select a deposit account.'); return; }
                    const saveBtn = document.getElementById('modal-save-btn');
                    submitting = true;
                    AjaxService?.showButtonLoading(saveBtn);
                    try {
                        const result = await karobarAPI.createReceiving(data);
                        if (result.success) {
                            NotificationService.success('Payment received');
                            modalService.close();
                            window.dispatchEvent(new CustomEvent('app:data-changed'));
                            this.loadPersonProfile(personId);
                        } else {
                            NotificationService.error(result.message || 'Failed');
                        }
                    } catch (e) {
                        NotificationService.error(e?.message || 'Receiving failed');
                    } finally {
                        submitting = false;
                        AjaxService?.hideButtonLoading(saveBtn);
                    }
                }
            });
            this.loadAccountsForKarobarForm('profile-receive-account');
        }
    }

    // ========================
    // TRANSACTIONS
    // ========================
    async loadTransactions() {
        AjaxService?.showSkeleton('karobar-transactions-content');
        try {
            if (!this.people || this.people.length === 0) {
                try {
                    await this._ensureAllPeople();
                } catch (e) {}
            }
            const result = await karobarAPI.getTransactions({...this.filters,page:this.transactionsPage,limit:this.historyPageSize});
            if (result.success) {
                this.transactions = result.data?.transactions || [];
                this.transactionsPagination=result.data?.pagination||{};
                this.transactionsSummary=result.data?.summary||{};
                this.renderTransactionsPage();
            }
        } catch (error) {
            console.error('Failed to load transactions:', error);
            this.renderTransactionsPage();
        } finally {
            AjaxService?.hideSkeleton('karobar-transactions-content');
        }
    }

    renderTransactionsPage() {
        const container = document.getElementById('karobar-transactions-content');
        if (!container) return;

        const totalLent=Number(this.transactionsSummary.total_lent||0),totalBorrowed=Number(this.transactionsSummary.total_borrowed||0),totalReturned=Number(this.transactionsSummary.total_returned||0),totalRepaid=Number(this.transactionsSummary.total_repaid||0);

        let html = `
            <div class="karobar-report-summary mb-4">
                <div class="karobar-report-card">
                    <div class="report-value" style="color:#EF4444;">${this.formatCurrency(totalLent)}</div>
                    <div class="report-label">Total Lent</div>
                </div>
                <div class="karobar-report-card">
                    <div class="report-value" style="color:#10B981;">${this.formatCurrency(totalBorrowed)}</div>
                    <div class="report-label">Total Borrowed</div>
                </div>
                <div class="karobar-report-card">
                    <div class="report-value" style="color:#3B82F6;">${this.formatCurrency(totalReturned)}</div>
                    <div class="report-label">Total Returned</div>
                </div>
                <div class="karobar-report-card">
                    <div class="report-value" style="color:#F59E0B;">${this.formatCurrency(totalRepaid)}</div>
                    <div class="report-label">Total Repaid</div>
                </div>
                ${this._paginationHTML(this.transactionsPagination,'goToTransactionsPage')}
            </div>

            <div class="karobar-chart-container">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="mb-0"><i class="fas fa-exchange-alt me-2 text-info"></i>All Transactions</h5>
                    <div class="karobar-transaction-filters d-flex gap-2 flex-wrap">
                        <select id="karobar-tx-filter-type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="lent">Money Lent</option>
                            <option value="borrowed">Money Borrowed</option>
                            <option value="returned">Money Returned</option>
                            <option value="repaid">Money Repaid</option>
                            <option value="adjustment">Adjustment</option>
                        </select>
                        <select id="karobar-tx-filter-person" class="form-select form-select-sm">
                            <option value="">All People</option>
                            ${(this.people || []).map(p => `<option value="${p.id}">${Formatters.escapeHTML(p.name)}</option>`).join('')}
                        </select>
                        <button class="btn btn-sm btn-primary" onclick="window.karobarManager?.applyTxFilters()"><i class="fas fa-filter me-1"></i>Apply</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="window.karobarManager?.clearTxFilters()">Clear</button>
                        <button class="btn btn-sm btn-primary" onclick="window.karobarManager?.showAddTransactionModal()">
                            <i class="fas fa-plus me-1"></i>New Transaction
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="karobar-transactions-table" class="table table-hover mb-0" style="width:100%">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Person</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${(this.transactions || []).map(tx => `
                                <tr>
                                    <td>${this.formatDate(tx.transaction_date)}</td>
                                    <td><span class="fw-semibold">${Formatters.escapeHTML(tx.person_name || 'Unknown')}</span></td>
                                    <td>${this.getTypeBadge(tx.type)}</td>
                                    <td>${Formatters.escapeHTML(tx.description || '-')}</td>
                                    <td class="fw-bold" style="color:${['lent','repaid'].includes(tx.type) ? '#EF4444' : '#10B981'};">
                                        ${['lent','repaid'].includes(tx.type) ? '-' : '+'}${this.formatCurrency(tx.amount)}
                                    </td>
                                    <td>${tx.due_date ? this.formatDate(tx.due_date) : '-'}</td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-center">
                                            <button class="btn btn-sm btn-outline-primary" onclick="window.karobarManager?.editTransaction(${tx.id})" title="Edit"><i class="fas fa-pen"></i></button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="window.karobarManager?.deleteTransaction(${tx.id})" title="Delete"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                <div class="karobar-transaction-mobile-list">
                    ${(this.transactions || []).map(tx => `
                        <article class="karobar-transaction-mobile-card">
                            <div class="karobar-transaction-mobile-heading">
                                <div>
                                    <strong>${Formatters.escapeHTML(tx.person_name || 'Unknown')}</strong>
                                    <span>${this.formatDate(tx.transaction_date)}</span>
                                </div>
                                <strong class="karobar-transaction-mobile-amount" style="color:${['lent','repaid'].includes(tx.type) ? '#EF4444' : '#10B981'};">${['lent','repaid'].includes(tx.type) ? '-' : '+'}${this.formatCurrency(tx.amount)}</strong>
                            </div>
                            <div class="karobar-transaction-mobile-meta">
                                ${this.getTypeBadge(tx.type)}
                                <span>${tx.due_date ? `Due ${this.formatDate(tx.due_date)}` : 'No due date'}</span>
                            </div>
                            ${tx.description ? `<p>${Formatters.escapeHTML(tx.description)}</p>` : ''}
                            <div class="karobar-transaction-mobile-actions">
                                <button class="btn btn-sm btn-outline-primary" onclick="window.karobarManager?.editTransaction(${tx.id})"><i class="fas fa-pen me-1"></i>Edit</button>
                                <button class="btn btn-sm btn-outline-danger" onclick="window.karobarManager?.deleteTransaction(${tx.id})"><i class="fas fa-trash me-1"></i>Delete</button>
                            </div>
                        </article>
                    `).join('') || '<p class="text-muted text-center py-3">No transactions found</p>'}
                </div>
            </div>
        `;

        container.innerHTML = html;


    }

    goToTransactionsPage(page){const total=Number(this.transactionsPagination?.total_pages||1);this.transactionsPage=Math.max(1,Math.min(total,Number(page)||1));this.loadTransactions();}

    applyTxFilters() {
        this.filters = {
            type: document.getElementById('karobar-tx-filter-type')?.value || undefined,
            person_id: document.getElementById('karobar-tx-filter-person')?.value || undefined
        };
        Object.keys(this.filters).forEach(k => { if (!this.filters[k]) delete this.filters[k]; });
        this.transactionsPage=1;
        this.loadTransactions();
    }

    clearTxFilters() {
        this.filters = {};
        this.transactionsPage=1;
        this.loadTransactions();
    }

    async showAddTransactionModal(personId = null) {
        this._editingTxId = null;
        this._paymentRequestId = this.createClientRequestId();

        if (!this.people || this.people.length === 0) {
            try {
                await this._ensureAllPeople();
            } catch (e) {}
        }

        if (window.modalService) {
            window.modalService.open({
                title: 'New Karobar Transaction',
                subtitle: 'Record money lent, borrowed, returned, or repaid.',
                icon: 'fa-handshake',
                bodyHTML: this._getTransactionFormHTML(personId),
                showFooter: true,
                onSave: () => this.saveTransaction()
            });
        }

        if (window.DatePickerManager) {
            DatePickerManager.bind('#kt-date');
            DatePickerManager.bind('#kt-due-date');
        }

        this.loadAccountsForKarobarForm();
    }

    async editTransaction(id) {
        try {
            const result = await karobarAPI.getTransaction(id);
            if (!result.success || !result.data) {
                NotificationService.error('Transaction not found');
                return;
            }
            const tx = result.data;
            this._editingTxId = id;
            this._editingTxVersion = Number(tx.version) || null;
            this._editingCreditPurchase = Boolean(tx.payment_method === 'credit' && tx.expense_transaction_id);

            if (!this.people || this.people.length === 0) {
                try {
                    await this._ensureAllPeople();
                } catch (e) {}
            }

            if (window.modalService) {
                window.modalService.open({
                    title: 'Edit Transaction',
                    subtitle: 'Update transaction details.',
                    icon: 'fa-pen',
                    bodyHTML: this._getTransactionFormHTML(null, tx),
                    showFooter: true,
                    onSave: () => this.saveTransaction()
                });
            }

            if (window.DatePickerManager) {
                DatePickerManager.bind('#kt-date');
                DatePickerManager.bind('#kt-due-date');
            }

            this.loadAccountsForKarobarForm();
        } catch (error) {
            NotificationService.error('Failed to load transaction');
        }
    }

    _getTransactionFormHTML(personId = null, tx = null) {
        let personOptions = (this.people || []).map(p => {
            const selected = (tx && tx.person_id == p.id) || (personId && personId == p.id) ? 'selected' : '';
            return `<option value="${p.id}" ${selected}>${Formatters.escapeHTML(p.name)}</option>`;
        }).join('');
        if(tx&&!(this.people||[]).some(p=>p.id==tx.person_id)){
            personOptions+=`<option value="${tx.person_id}" selected>${Formatters.escapeHTML(tx.person_name||'Archived person')} (Archived)</option>`;
        }

        return `
            <form id="karobar-tx-form">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Person <span class="text-danger">*</span></label>
                            <select id="kt-person" class="form-select" required>
                                <option value="">Select person</option>
                                ${personOptions}
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            <select id="kt-type" class="form-select" required ${tx && tx.payment_method === 'credit' && tx.expense_transaction_id ? 'disabled' : ''}>
                                <option value="lent" ${tx && tx.type === 'lent' ? 'selected' : ''}>Money Lent (I gave)</option>
                                <option value="borrowed" ${tx && tx.type === 'borrowed' ? 'selected' : ''}>Money Borrowed (I received)</option>
                                <option value="returned" ${tx && tx.type === 'returned' ? 'selected' : ''}>Money Returned (They gave back)</option>
                                <option value="repaid" ${tx && tx.type === 'repaid' ? 'selected' : ''}>Money Repaid (I paid back)</option>
                                <option value="adjustment" ${tx && tx.type === 'adjustment' ? 'selected' : ''}>Adjustment</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">Rs</span>
                                <input type="number" id="kt-amount" class="form-control" step="0.01" min="0.01" value="${tx ? tx.amount : ''}" required placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Account ${tx && tx.payment_method === 'credit' && tx.expense_transaction_id ? '' : '<span class="text-danger">*</span>'}</label>
                            <select id="kt-account" class="form-select" ${tx && tx.payment_method === 'credit' && tx.expense_transaction_id ? 'disabled' : 'required'}>
                                <option value="">Select account</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Transaction Date <span class="text-danger">*</span></label>
                            <input type="date" id="kt-date" class="form-control" value="${tx ? tx.transaction_date : this.getLocalDateValue()}" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Due Date</label>
                            <input type="date" id="kt-due-date" class="form-control" value="${tx && tx.due_date ? tx.due_date : ''}">
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-group">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea id="kt-description" class="form-control" rows="2" placeholder="Add a note">${tx ? Formatters.escapeHTML(tx.description || '') : ''}</textarea>
                        </div>
                    </div>
                </div>
            </form>
        `;
    }

    async loadAccountsForKarobarForm(selectId) {
        const targetIds = selectId ? [selectId] : ['kt-account'];
        try {
            const result = await accountsAPI.getAll();
            if (result.success) {
                targetIds.forEach(id => {
                    const select = document.getElementById(id);
                    if (select && select.options.length <= 1) {
                        result.data.forEach(acc => {
                            const option = document.createElement('option');
                            option.value = acc.id;
                            option.textContent = acc.name;
                            select.appendChild(option);
                        });
                    }
                });
            }
        } catch (e) {
            console.error('Failed to load accounts:', e);
        }
    }

    async saveTransaction() {
        if (this._transactionSubmitting) return;
        const form = document.getElementById('karobar-tx-form');
        if (!form.checkValidity()) { form.reportValidity(); return; }

        const data = {
            person_id: parseInt(document.getElementById('kt-person').value),
            type: document.getElementById('kt-type').value,
            amount: parseFloat(document.getElementById('kt-amount').value),
            account_id: document.getElementById('kt-account')?.value ? parseInt(document.getElementById('kt-account').value) : null,
            transaction_date: document.getElementById('kt-date').value,
            due_date: document.getElementById('kt-due-date')?.value || null,
            description: document.getElementById('kt-description').value.trim()
        };
        if (!this._editingTxId) {
            data.client_request_id = this._paymentRequestId || (this._paymentRequestId = this.createClientRequestId());
        }
        if (this._editingTxId && this._editingCreditPurchase) {
            data.base_version = this._editingTxVersion;
            data.payment_method = 'credit';
            data.account_id = null;
        }

        const saveBtn = document.querySelector('#modal-footer .btn-primary');
        this._transactionSubmitting = true;
        AjaxService?.showButtonLoading(saveBtn);
        try {
            let result;
            if (this._editingTxId) {
                result = await karobarAPI.updateTransaction(this._editingTxId, data);
            } else {
                result = await karobarAPI.createTransaction(data);
            }

            if (result.success) {
                NotificationService.success(this._editingTxId ? 'Transaction updated' : 'Transaction recorded');
                if (!this._editingTxId) this._paymentRequestId = null;
                this._editingTxId = null;
                this._editingTxVersion = null;
                this._editingCreditPurchase = false;
                if (window.modalService) modalService.close();
                window.dispatchEvent(new CustomEvent('app:data-changed'));
            } else {
                NotificationService.error(result.message || 'Failed to save transaction');
            }
        } catch (error) {
            NotificationService.error(error?.message || 'Failed to save transaction');
        } finally {
            this._transactionSubmitting = false;
            AjaxService?.hideButtonLoading(saveBtn);
        }
    }

    async deleteTransaction(id) {
        let version = null;
        try {
            const current = await karobarAPI.getTransaction(id);
            if (current.success && current.data?.payment_method === 'credit' && current.data?.expense_transaction_id) {
                version = Number(current.data.version) || null;
            }
        } catch (error) {
            NotificationService.error('Failed to load the latest transaction');
            return;
        }
        const confirmed = await NotificationService.confirm({
            title: 'Delete Transaction',
            text: 'Are you sure you want to delete this transaction?',
            confirmButtonText: 'Yes, Delete'
        });
        if (!confirmed) return;

        try {
            const result = await karobarAPI.deleteTransaction(id, version);
            if (result.success) {
                NotificationService.success('Transaction deleted');
                window.dispatchEvent(new CustomEvent('app:data-changed'));
                if (window.appRouter?.currentPage === 'transactions') await window.transactionsManager?.loadTransactions();
            }
        } catch (error) {
            NotificationService.error(error?.message || 'Failed to delete transaction');
        }
    }

    // ========================
    // CREDIT REPORTS
    // ========================
    async loadCreditReports() {
        try {
            const result = await karobarAPI.getCreditReports({ report_type: 'all' });
            if (result.success) {
                this.renderCreditReports(result.data);
            } else {
                this.renderCreditReports(null);
            }
        } catch (error) {
            console.error('Failed to load credit reports:', error);
            this.renderCreditReports(null);
        }
    }

    renderCreditReports(data) {
        const container = document.getElementById('karobar-credit-reports-content');
        if (!container) return;

        if (!data) {
            container.innerHTML = this.getEmptyState('No Credit Data', 'Start recording credit transactions to see detailed reports.', 'karobar-overview');
            return;
        }

        const receivable = data.receivable_report || [];
        const payable = data.payable_report || [];
        const shopWise = data.shop_wise_report || [];
        const personWise = data.person_wise_report || [];
        const outstanding = data.outstanding_report || [];
        const monthly = data.monthly_credit_report || [];

        const totalRecAmt = receivable.reduce((s, r) => s + parseFloat(r.outstanding_amount || 0), 0);
        const totalPayAmt = payable.reduce((s, r) => s + parseFloat(r.outstanding_amount || 0), 0);

        let html = `
            <div class="karobar-report-summary mb-4">
                <div class="karobar-report-card">
                    <div class="report-value" style="color:#EF4444;">${this.formatCurrency(totalRecAmt)}</div>
                    <div class="report-label">Outstanding Receivable</div>
                </div>
                <div class="karobar-report-card">
                    <div class="report-value" style="color:#10B981;">${this.formatCurrency(totalPayAmt)}</div>
                    <div class="report-label">Outstanding Payable</div>
                </div>
                <div class="karobar-report-card">
                    <div class="report-value">${receivable.length + payable.length}</div>
                    <div class="report-label">Total Transactions</div>
                </div>
                <div class="karobar-report-card">
                    <div class="report-value">${outstanding.length}</div>
                    <div class="report-label">Outstanding People</div>
                </div>
            </div>

            <div class="row g-4 mb-4 karobar-credit-detail-grid">
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-hand-holding-usd me-2 text-danger"></i>Receivable Detail</h5>
                        <div class="table-responsive" style="max-height:350px;overflow-y:auto;">
                            <table class="table table-hover table-sm">
                                <thead><tr><th>Date</th><th>Person</th><th>Type</th><th>Amount</th><th>Status</th></tr></thead>
                                <tbody>
                                    ${receivable.length === 0 ? '<tr><td colspan="5" class="text-center text-muted">No receivable records</td></tr>' :
                                    receivable.map(tx => `
                                        <tr>
                                            <td>${this.formatDate(tx.transaction_date)}</td>
                                            <td class="fw-semibold">${Formatters.escapeHTML(tx.person_name || 'Unknown')}</td>
                                            <td><span class="badge bg-secondary">${tx.person_type || 'person'}</span></td>
                                            <td class="fw-bold" style="color:#EF4444;" title="Original: ${this.formatCurrency(tx.original_amount)}">${this.formatCurrency(tx.outstanding_amount)}</td>
                                            <td>${tx.is_overdue ? '<span class="badge bg-danger">Overdue</span>' : '<span class="badge bg-success">Active</span>'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-hand-holding me-2 text-success"></i>Payable Detail</h5>
                        <div class="table-responsive" style="max-height:350px;overflow-y:auto;">
                            <table class="table table-hover table-sm">
                                <thead><tr><th>Date</th><th>Person</th><th>Type</th><th>Amount</th><th>Status</th></tr></thead>
                                <tbody>
                                    ${payable.length === 0 ? '<tr><td colspan="5" class="text-center text-muted">No payable records</td></tr>' :
                                    payable.map(tx => `
                                        <tr>
                                            <td>${this.formatDate(tx.transaction_date)}</td>
                                            <td class="fw-semibold">${Formatters.escapeHTML(tx.person_name || 'Unknown')}</td>
                                            <td><span class="badge bg-secondary">${tx.person_type || 'person'}</span></td>
                                            <td class="fw-bold" style="color:#10B981;" title="Original: ${this.formatCurrency(tx.original_amount)}">${this.formatCurrency(tx.outstanding_amount)}</td>
                                            <td>${tx.is_overdue ? '<span class="badge bg-danger">Overdue</span>' : '<span class="badge bg-warning text-dark">Pending</span>'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4 karobar-credit-detail-grid">
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-store me-2 text-warning"></i>Shop-wise Summary</h5>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead><tr><th>Shop</th><th>Type</th><th>Lent</th><th>Borrowed</th></tr></thead>
                                <tbody>
                                    ${shopWise.length === 0 ? '<tr><td colspan="4" class="text-center text-muted">No shop data</td></tr>' :
                                    shopWise.map(s => `
                                        <tr>
                                            <td class="fw-semibold">${Formatters.escapeHTML(s.name)}</td>
                                            <td><span class="badge bg-secondary">${s.type || 'shop'}</span></td>
                                            <td style="color:#EF4444;">${this.formatCurrency(s.total_lent)}</td>
                                            <td style="color:#10B981;">${this.formatCurrency(s.total_borrowed)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-users me-2 text-info"></i>Outstanding Balances</h5>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead><tr><th>Person</th><th>Type</th><th>Receivable</th><th>Payable</th><th>Next Due</th></tr></thead>
                                <tbody>
                                    ${outstanding.length === 0 ? '<tr><td colspan="5" class="text-center text-muted">No outstanding</td></tr>' :
                                    outstanding.map(o => `
                                        <tr>
                                            <td class="fw-semibold">${Formatters.escapeHTML(o.name)}</td>
                                            <td><span class="badge bg-secondary">${o.type || 'person'}</span></td>
                                            <td style="color:#EF4444;">${parseFloat(o.receivable_balance) > 0 ? this.formatCurrency(o.receivable_balance) : '-'}</td>
                                            <td style="color:#10B981;">${parseFloat(o.payable_balance) > 0 ? this.formatCurrency(o.payable_balance) : '-'}</td>
                                            <td>${o.next_due_date ? this.formatDate(o.next_due_date) : '-'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        `;

        container.innerHTML = html;

        if (window.ChartService && monthly.length > 0) {
            ChartService.create('#karobar-credit-monthly-chart', {
                series: [
                    { name: 'Lent', data: monthly.map(d => parseFloat(d.total_lent) || 0) },
                    { name: 'Borrowed', data: monthly.map(d => parseFloat(d.total_borrowed) || 0) },
                    { name: 'Repaid', data: monthly.map(d => parseFloat(d.total_repaid) || 0) }
                ],
                chart: { type: 'bar', height: 300, toolbar: { show: false } },
                colors: ['#EF4444', '#10B981', '#3B82F6'],
                plotOptions: { bar: { borderRadius: 6, columnWidth: '60%' } },
                xaxis: { categories: monthly.map(d => d.month_label) },
                yaxis: { labels: { formatter: (v) => Formatters.compactCurrency(v) } },
                legend: { position: 'top' }
            });
        }
    }

    // ========================
    // AI ANALYSIS
    // ========================
    async loadAIAnalysis() {
        const container = document.getElementById('karobar-ai-analysis-content');
        if (container) {
            container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-3">Analyzing your karobar data...</p></div>';
        }
        try {
            const result = await karobarAPI.getAIAnalysis();
            if (result.success) {
                this.renderAIAnalysis(result.data);
            } else {
                this.renderAIAnalysis(null);
            }
        } catch (error) {
            console.error('Failed to load AI analysis:', error);
            this.renderAIAnalysis(null);
        }
    }

    renderAIAnalysis(data) {
        const container = document.getElementById('karobar-ai-analysis-content');
        if (!container) return;

        if (!data) {
            container.innerHTML = this.getEmptyState('No AI Data', 'Add karobar transactions to get AI-powered insights.', 'karobar-overview');
            return;
        }

        const scoreAvailable = data.health_score !== null && data.health_score !== undefined && Number.isFinite(Number(data.health_score));
        const score = scoreAvailable ? Number(data.health_score) : null;
        const status = data.health_status || 'Not enough data';
        const scoreColor = !scoreAvailable ? '#6B7280' : (score >= 80 ? '#10B981' : (score >= 65 ? '#34D399' : (score >= 45 ? '#F59E0B' : (score >= 25 ? '#F97316' : '#EF4444'))));

        const insights = data.insights || [];
        const recommendations = data.recommendations || [];
        const monthlyTrend = data.monthly_trend || [];
        const trendHasActivity = Boolean(data.monthly_trend_has_activity);
        const netPosition = Number(data.net_position ?? data.net_karobar ?? 0);
        const creditDependency = data.credit_dependency === null || data.credit_dependency === undefined ? 'N/A' : `${Number(data.credit_dependency).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
        const avgRepayment = data.avg_repayment_days === null || data.avg_repayment_days === undefined ? 'N/A' : `${Number(data.avg_repayment_days)} days`;
        const mostBorrowed = data.most_borrowed_person || data.most_borrowed_shop;
        const signedNet = `${netPosition > 0 ? '+' : (netPosition < 0 ? '-' : '')}${this.formatCurrency(netPosition)}`;

        let html = `
            <div class="row g-4 mb-4 karobar-ai-summary-grid">
                <div class="col-md-4">
                    <div class="karobar-chart-container karobar-health-card text-center py-5">
                        <div class="karobar-health-score" style="--karobar-health-color:${scoreColor};">
                            <span>${scoreAvailable ? score : '—'}</span>
                        </div>
                        <h4 class="fw-bold">${status}</h4>
                        <p class="text-muted mb-0">Karobar Health Score${scoreAvailable ? ' / 100' : ''}</p>
                        ${!scoreAvailable ? '<small class="text-muted d-block mt-2">Add Karobar activity to calculate a meaningful score.</small>' : ''}
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-chart-pie me-2 text-primary"></i>Karobar Summary</h5>
                        <div class="row g-3 mt-2">
                            <div class="col-sm-4">
                                <div class="text-center p-3 rounded bg-success bg-opacity-10">
                                    <p class="text-muted small mb-1">Receivable</p>
                                    <p class="fw-bold karobar-ai-metric-value" style="color:#10B981;">${this.formatCurrency(data.total_receivable)}</p>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="text-center p-3 rounded bg-danger bg-opacity-10">
                                    <p class="text-muted small mb-1">Payable</p>
                                    <p class="fw-bold karobar-ai-metric-value" style="color:#EF4444;">${this.formatCurrency(data.total_payable)}</p>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="text-center p-3 rounded bg-primary bg-opacity-10">
                                    <p class="text-muted small mb-1">Net Position</p>
                                    <p class="fw-bold karobar-ai-metric-value" style="color:${netPosition >= 0 ? '#10B981' : '#EF4444'};">${signedNet}</p>
                                </div>
                            </div>
                            <div class="col-sm-3">
                                <div class="text-center p-3 rounded bg-secondary bg-opacity-10">
                                    <p class="text-muted small mb-1">Credit Dependency <i class="fas fa-circle-info ms-1" tabindex="0" role="img" aria-label="Credit Dependency is the share of recorded expenses made on credit." title="The share of recorded expenses made on credit. N/A means there is not enough expense data to calculate it."></i></p>
                                    <p class="fw-bold">${creditDependency}</p>
                                </div>
                            </div>
                            <div class="col-sm-3">
                                <div class="text-center p-3 rounded bg-secondary bg-opacity-10">
                                    <p class="text-muted small mb-1">Avg Repayment</p>
                                    <p class="fw-bold">${avgRepayment}</p>
                                </div>
                            </div>
                            <div class="col-sm-3">
                                <div class="text-center p-3 rounded bg-secondary bg-opacity-10">
                                    <p class="text-muted small mb-1">Active People</p>
                                    <p class="fw-bold mb-1">${Number(data.active_people_count ?? data.people_count ?? 0)}</p>
                                    <small class="text-muted d-block">You owe ${Number(data.people_you_owe || 0)} · Owe you ${Number(data.people_who_owe_you || 0)}</small>
                                </div>
                            </div>
                            <div class="col-sm-3">
                                <div class="text-center p-3 rounded bg-secondary bg-opacity-10">
                                    <p class="text-muted small mb-1">Most Borrowed</p>
                                    <p class="fw-bold" style="font-size:0.9rem;">${mostBorrowed ? Formatters.escapeHTML(mostBorrowed.name) : 'N/A'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4 karobar-ai-insight-grid">
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-lightbulb me-2 text-warning"></i>AI Insights</h5>
                        ${insights.length === 0 ? '<p class="text-muted text-center py-3">No insights available yet</p>' :
                        insights.map(i => `<div class="d-flex align-items-start gap-2 mb-3"><i class="fas fa-info-circle text-primary mt-1"></i><p class="mb-0">${Formatters.escapeHTML(i)}</p></div>`).join('')}
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-rocket me-2 text-primary"></i>Recommendations</h5>
                        ${recommendations.length === 0 ? '<p class="text-muted text-center py-3">No recommendations yet</p>' :
                        recommendations.map(r => `<div class="d-flex align-items-start gap-2 mb-3"><i class="fas fa-check-circle text-success mt-1"></i><p class="mb-0">${Formatters.escapeHTML(r)}</p></div>`).join('')}
                    </div>
                </div>
            </div>

            <div class="karobar-chart-container mb-4">
                <h5><i class="fas fa-chart-line me-2 text-info"></i>Monthly Debt Trend</h5>
                ${trendHasActivity ? '<div id="karobar-ai-monthly-trend" class="karobar-responsive-chart" aria-label="Monthly borrowed, repaid, and outstanding debt chart"></div>' : '<div class="karobar-ai-chart-empty"><i class="fas fa-chart-line" aria-hidden="true"></i><p class="mb-0">No Karobar activity available for this period.</p></div>'}
            </div>
        `;

        container.innerHTML = html;

        if (window.ChartService && trendHasActivity && monthlyTrend.length > 0) {
            ChartService.create('#karobar-ai-monthly-trend', {
                series: [
                    { name: 'Borrowed', data: monthlyTrend.map(d => parseFloat(d.borrowed) || 0) },
                    { name: 'Repaid', data: monthlyTrend.map(d => parseFloat(d.repaid) || 0) },
                    { name: 'Outstanding Balance', data: monthlyTrend.map(d => parseFloat(d.outstanding_balance) || 0) }
                ],
                chart: { type: 'line', height: 300, toolbar: { show: false }, redrawOnParentResize: true, redrawOnWindowResize: true },
                colors: ['#EF4444', '#10B981', '#3B82F6'],
                stroke: { curve: 'smooth', width: 2 },
                markers: { size: 3 },
                xaxis: { categories: monthlyTrend.map(d => d.month) },
                yaxis: { min: 0, labels: { formatter: (v) => Formatters.compactCurrency(v) } },
                tooltip: { shared: true, y: { formatter: (v) => this.formatCurrency(v) } },
                legend: { position: 'top' },
                responsive: [{ breakpoint: 600, options: { chart: { height: 220 }, legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '11px' } }}]
            });
        }
    }

    // ========================
    // REPORTS
    // ========================
    async loadReports() {
        try {
            const [dashboardResult, reportResult] = await Promise.all([
                karobarAPI.getDashboard(),
                karobarAPI.getCreditReports({ report_type: 'all' })
            ]);

            const data = dashboardResult.success ? dashboardResult.data : null;
            const transactions = reportResult.success ? reportResult.data : null;

            this.renderReportsPage(data, transactions);
        } catch (error) {
            console.error('Failed to load reports:', error);
            this.renderReportsPage(null, []);
        }
    }

    renderReportsPage(dashData, transactions) {
        const container = document.getElementById('karobar-reports-content');
        if (!container) return;

        const totalReceivable = dashData ? parseFloat(dashData.total_receivable) || 0 : 0;
        const totalPayable = dashData ? parseFloat(dashData.total_payable) || 0 : 0;
        const net = dashData ? parseFloat(dashData.net_karobar) || 0 : 0;
        const peopleCount = dashData ? parseInt(dashData.people_count) || 0 : 0;

        const receivableTx = transactions?.receivable_report || [];
        const payableTx = transactions?.payable_report || [];
        const monthlyData = dashData?.monthly_data || [];
        const peopleBalances = dashData?.people_balances || [];

        let html = `
            <div class="karobar-report-summary mb-4">
                <div class="karobar-report-card">
                    <div class="report-value" style="color:#10B981;">${this.formatCurrency(totalReceivable)}</div>
                    <div class="report-label">Total Receivable</div>
                </div>
                <div class="karobar-report-card">
                    <div class="report-value" style="color:#EF4444;">${this.formatCurrency(totalPayable)}</div>
                    <div class="report-label">Total Payable</div>
                </div>
                <div class="karobar-report-card">
                    <div class="report-value" style="color:${net >= 0 ? '#10B981' : '#EF4444'};">${this.formatCurrency(Math.abs(net))}</div>
                    <div class="report-label">Net Karobar</div>
                </div>
                <div class="karobar-report-card">
                    <div class="report-value">${peopleCount}</div>
                    <div class="report-label">People</div>
                </div>
            </div>

            <div class="row g-4 mb-4 karobar-report-detail-grid">
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-file-alt me-2 text-danger"></i>Receivable Report</h5>
                        <p class="text-muted small mb-3">Money others owe you</p>
                        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                            <table class="table table-hover table-sm">
                                <thead><tr><th>Date</th><th>Person</th><th>Amount</th><th>Status</th></tr></thead>
                                <tbody>
                                    ${receivableTx.length === 0 ? '<tr><td colspan="4" class="text-center text-muted">No receivable records</td></tr>' : 
                                    receivableTx.map(tx => `
                                        <tr>
                                            <td>${this.formatDate(tx.transaction_date)}</td>
                                            <td class="fw-semibold">${Formatters.escapeHTML(tx.person_name || 'Unknown')}</td>
                                            <td class="fw-bold" style="color:#EF4444;" title="Original: ${this.formatCurrency(tx.original_amount)}">${this.formatCurrency(tx.outstanding_amount)}</td>
                                            <td>${tx.is_overdue ? '<span class="badge bg-danger">Overdue</span>' : '<span class="badge bg-success">Active</span>'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-file-alt me-2 text-success"></i>Payable Report</h5>
                        <p class="text-muted small mb-3">Money you owe others</p>
                        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                            <table class="table table-hover table-sm">
                                <thead><tr><th>Date</th><th>Person</th><th>Amount</th><th>Status</th></tr></thead>
                                <tbody>
                                    ${payableTx.length === 0 ? '<tr><td colspan="4" class="text-center text-muted">No payable records</td></tr>' :
                                    payableTx.map(tx => `
                                        <tr>
                                            <td>${this.formatDate(tx.transaction_date)}</td>
                                            <td class="fw-semibold">${Formatters.escapeHTML(tx.person_name || 'Unknown')}</td>
                                            <td class="fw-bold" style="color:#10B981;" title="Original: ${this.formatCurrency(tx.original_amount)}">${this.formatCurrency(tx.outstanding_amount)}</td>
                                            <td>${tx.is_overdue ? '<span class="badge bg-danger">Overdue</span>' : '<span class="badge bg-warning text-dark">Pending</span>'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4 karobar-report-charts">
                <div class="col-lg-8">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-chart-line me-2 text-primary"></i>Monthly Trend</h5>
                        <div id="karobar-report-monthly-chart" class="karobar-responsive-chart"></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="karobar-chart-container">
                        <h5><i class="fas fa-chart-pie me-2 text-warning"></i>Balance Distribution</h5>
                        <div id="karobar-report-dist-chart" class="karobar-responsive-chart"></div>
                    </div>
                </div>
            </div>

            <div class="karobar-chart-container">
                <h5 class="mb-3"><i class="fas fa-download me-2 text-info"></i>Export</h5>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-outline-success" onclick="window.karobarManager?.exportCSV()">
                        <i class="fas fa-file-csv me-1"></i> Export CSV
                    </button>
                    <button class="btn btn-outline-primary" onclick="window.karobarManager?.exportExcel()">
                        <i class="fas fa-file-excel me-1"></i> Export Excel
                    </button>
                    <button class="btn btn-outline-danger" onclick="window.karobarManager?.exportPDF()">
                        <i class="fas fa-file-pdf me-1"></i> Export PDF
                    </button>
                </div>
            </div>
        `;

        container.innerHTML = html;

        if (window.ChartService) {
            if (monthlyData.length > 0) {
                ChartService.create('#karobar-report-monthly-chart', {
                    series: [
                        { name: 'Lent', data: monthlyData.map(d => parseFloat(d.lent) || 0) },
                        { name: 'Borrowed', data: monthlyData.map(d => parseFloat(d.borrowed) || 0) }
                    ],
                    chart: { type: 'area', height: 300, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
                    colors: ['#EF4444', '#10B981'],
                    dataLabels: { enabled: false },
                    stroke: { curve: 'smooth', width: 2 },
                    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05 } },
                    xaxis: { categories: monthlyData.map(d => d.month), labels: { style: { colors: '#6B7280' } } },
                    yaxis: { labels: { style: { colors: '#6B7280' }, formatter: (v) => Formatters.compactCurrency(v) } },
                    tooltip: { y: { formatter: (v) => Formatters.currency(v) } },
                    legend: { position: 'top', horizontalAlign: 'right' },
                    responsive: [{ breakpoint: 600, options: { chart: { height: 220 }, legend: { position: 'bottom', horizontalAlign: 'center', fontSize: '11px' } }}]
                });
            }

            const positiveCount = peopleBalances.filter(p => parseFloat(p.receivable_outstanding) > 0).length;
            const negativeCount = peopleBalances.filter(p => parseFloat(p.payable_outstanding) > 0).length;
            const settledCount = peopleBalances.filter(p => parseFloat(p.receivable_outstanding) === 0 && parseFloat(p.payable_outstanding) === 0).length;

            ChartService.create('#karobar-report-dist-chart', {
                series: [positiveCount, negativeCount, settledCount],
                chart: { type: 'donut', height: 300, fontFamily: 'Inter, sans-serif' },
                labels: ['Receivable', 'Payable', 'Settled'],
                colors: ['#10B981', '#EF4444', '#6B7280'],
                plotOptions: { pie: { donut: { size: '65%' } } },
                dataLabels: { enabled: false },
                legend: { position: 'bottom' },
                responsive: [{ breakpoint: 600, options: { chart: { height: 220 }, legend: { fontSize: '11px' } }}]
            });
        }
    }

    exportCSV() {
        this._exportData('csv');
    }

    exportExcel() {
        this._exportData('excel');
    }

    async exportPDF() {
        if (!this.transactions || this.transactions.length === 0) {
            NotificationService.warning('No data to export');
            return;
        }

        const rows = this.transactions.map(tx => [
            tx.transaction_date,
            tx.person_name || '',
            tx.type,
            tx.description || '',
            tx.amount,
            tx.due_date || ''
        ]);

        const docDefinition = {
            content: [
                [{ text: 'Karobar Report', style: 'header' }],
                [{ text: `Generated: ${new Date().toLocaleDateString()}`, style: 'subheader' }],
                {
                    table: {
                        headerRows: 1,
                        widths: ['auto', '*', 'auto', '*', 'auto', 'auto'],
                        body: [
                            ['Date', 'Person', 'Type', 'Description', 'Amount', 'Due Date'],
                            ...rows
                        ]
                    },
                    layout: 'lightHorizontalLines'
                }
            ],
            styles: { header: { fontSize: 18, bold: true, margin: [0, 0, 0, 10] }, subheader: { fontSize: 10, color: 'gray', margin: [0, 0, 0, 10] } }
        };

        const pdfReady = await this._ensurePdfExportLibrary();
        if (!pdfReady) return;
        window.pdfMake.createPdf(docDefinition).download('karobar-report.pdf');
    }

    async _ensurePdfExportLibrary() {
        if (window.pdfMake) return true;
        if (!this._pdfLibraryPromise) {
            this._pdfLibraryPromise = this._loadExternalScript('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js')
                .then(() => this._loadExternalScript('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'))
                .then(() => Boolean(window.pdfMake))
                .catch(() => false);
        }
        const loaded = await this._pdfLibraryPromise;
        if (!loaded) {
            this._pdfLibraryPromise = null;
            NotificationService.warning('PDF export could not be loaded. Check your connection and try again.');
        }
        return loaded;
    }

    _loadExternalScript(src) {
        const existing = document.querySelector(`script[src="${src}"]`);
        if (existing?.dataset.loaded === 'true') return Promise.resolve();
        return new Promise((resolve, reject) => {
            const script = existing || document.createElement('script');
            const onLoad = () => {
                script.dataset.loaded = 'true';
                resolve();
            };
            script.addEventListener('load', onLoad, { once: true });
            script.addEventListener('error', reject, { once: true });
            if (!existing) {
                script.src = src;
                script.defer = true;
                document.head.appendChild(script);
            }
        });
    }

    _exportData(format) {
        if (!this.transactions || this.transactions.length === 0) {
            NotificationService.warning('No data to export');
            return;
        }

        const headers = ['Date', 'Person', 'Type', 'Description', 'Amount', 'Due Date'];
        const rows = this.transactions.map(tx => [
            tx.transaction_date,
            tx.person_name || '',
            tx.type,
            tx.description || '',
            tx.amount,
            tx.due_date || ''
        ]);

        let content, mimeType, extension;

        if (format === 'csv') {
            content = [headers, ...rows].map(r => r.map(c => `"${c}"`).join(',')).join('\n');
            mimeType = 'text/csv';
            extension = 'csv';
        } else {
            content = [headers, ...rows].map(r => r.join('\t')).join('\n');
            mimeType = 'application/vnd.ms-excel';
            extension = 'xls';
        }

        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `karobar-report.${extension}`;
        a.click();
        URL.revokeObjectURL(url);
        NotificationService.success(`Report exported as ${extension.toUpperCase()}`);
    }
}

window.KarobarManager = KarobarManager;
