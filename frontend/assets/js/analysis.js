class AnalysisManager {
    constructor() {
        this._mounted = false;
        this._data = null;
    }

    onMount() {
        if (this._mounted) return;
        this._mounted = true;
        this._bindEvents();
        this._initCharts();
        this.loadAnalysisData();
        this._dataChangeHandler = () => this.loadAnalysisData();
        document.addEventListener('app:data-changed', this._dataChangeHandler);
    }

    onUnmount() {
        this._mounted = false;
        if (this._dataChangeHandler) {
            document.removeEventListener('app:data-changed', this._dataChangeHandler);
            this._dataChangeHandler = null;
        }
        if (window.ChartService) {
            ChartService.destroy('#spendingTrendChart');
            ChartService.destroy('#categoryDistributionChart');
        }
    }

    _bindEvents() {
        const sel = document.getElementById('analysis-period');
        if (sel) {
            sel.onchange = () => {
                if (sel.value === 'custom') {
                    this._showCustomDateModal();
                } else {
                    this.loadAnalysisData(sel.value);
                }
            };
        }
    }

    _showCustomDateModal() {
        if (window.Swal) {
            Swal.fire({
                title: 'Select Date Range',
                html: `
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small">Start Date</label>
                            <input type="date" id="custom-start" class="form-control" value="${new Date().toISOString().slice(0, 10)}">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">End Date</label>
                            <input type="date" id="custom-end" class="form-control" value="${new Date().toISOString().slice(0, 10)}">
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Analyze',
                confirmButtonColor: '#10B981',
                preConfirm: () => {
                    const s = document.getElementById('custom-start')?.value;
                    const e = document.getElementById('custom-end')?.value;
                    if (!s || !e) { Swal.showValidationMessage('Both dates required'); return; }
                    if (s > e) { Swal.showValidationMessage('Start must be before end'); return; }
                    return { start_date: s, end_date: e };
                }
            }).then(r => {
                if (r.isConfirmed && r.value) {
                    this.loadAnalysisData('custom', r.value.start_date, r.value.end_date);
                } else if (r.dismiss) {
                    document.getElementById('analysis-period').value = 'month';
                }
            });
        }
    }

    async loadAnalysisData(period, startDate, endDate) {
        const container = document.querySelector('#analysis-page');
        if (!container) return;

        this._showLoading();

        try {
            let url = '/analysis';
            if (period && period !== 'custom') {
                url += `?period=${period}`;
            } else if (startDate && endDate) {
                url += `?start_date=${startDate}&end_date=${endDate}`;
            } else {
                url += '?period=month';
            }

            const res = await api.get(url);
            if (res.success && res.data) {
                this._data = res.data;
                this._updateUI(res.data);
            } else {
                this._showError(res.message || 'Failed to load analysis');
            }
        } catch (err) {
            console.error('Analysis load error:', err);
            this._showError(err.message || 'Failed to load analysis');
        }
    }

    _showLoading() {
        const id = s => document.getElementById(s);
        if (id('analysis-score')) id('analysis-score').textContent = '--';
        if (id('analysis-status')) id('analysis-status').textContent = 'Analyzing...';
        if (id('analysis-summary')) id('analysis-summary').textContent = 'AI is analyzing your financial data...';
        ['savings-rate','monthly-growth','goal-progress','budget-health'].forEach(el => {
            const e = document.getElementById(el);
            if (e) e.textContent = '--';
        });
    }

    _showError(msg) {
        const id = s => document.getElementById(s);
        if (id('analysis-status')) id('analysis-status').textContent = 'Error';
        if (id('analysis-summary')) id('analysis-summary').textContent = msg || 'Could not load analysis data.';
        if (id('ai-insights-list')) id('ai-insights-list').innerHTML = `<div class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle mb-2" style="font-size:2rem;"></i><p>${this._esc(msg)}</p></div>`;
        if (id('ai-recommendations-list')) id('ai-recommendations-list').innerHTML = '';
    }

    _updateUI(data) {
        this._updateScore(data.score, data.health_status);
        this._updateSummary(data.summary);
        this._updateCharts(data);
        this._updateCategoryCards(data.category_distribution);
        this._updateInsights(data.insights);
        this._updateRecommendations(data.recommendations);
        this._updateMetrics(data.metrics);
    }

    _updateScore(score, status) {
        const el = document.getElementById('analysis-score');
        if (el) {
            const CountUpCtor = window.CountUp || window.countUp?.CountUp || window.countUp;
            if (CountUpCtor) {
                try {
                    const cu = new CountUpCtor(el, score, { duration: 2, decimalPlaces: 0 });
                    if (!cu.error) cu.start();
                    else el.textContent = score;
                } catch(e) { el.textContent = score; }
            } else {
                el.textContent = score;
            }
        }
        const st = document.getElementById('analysis-status');
        if (st) {
            st.textContent = status;
            const colors = { Excellent: '#10B981', Good: '#3B82F6', Average: '#F59E0B', 'Needs Improvement': '#EF4444' };
            st.style.color = colors[status] || '#6B7280';
        }
    }

    _updateSummary(summary) {
        const el = document.getElementById('analysis-summary');
        if (el && summary) el.textContent = summary;
    }

    _updateCharts(data) {
        const ChartSvc = window.ChartService;
        if (!ChartSvc) return;

        // Spending Trends
        if (data.monthly_data && data.monthly_data.length > 0) {
            const months = data.monthly_data.map(d => d.month);
            const income = data.monthly_data.map(d => d.income);
            const expense = data.monthly_data.map(d => d.expense);
            ChartSvc.updateOptions('#spendingTrendChart', {
                xaxis: { categories: months }
            });
            ChartSvc.updateSeries('#spendingTrendChart', [
                { name: 'Income', data: income },
                { name: 'Expense', data: expense }
            ]);
        } else {
            ChartSvc.updateSeries('#spendingTrendChart', []);
        }

        // Category Distribution
        if (data.category_distribution && data.category_distribution.length > 0) {
            const cats = data.category_distribution.map(c => c.category);
            const vals = data.category_distribution.map(c => c.amount);
            ChartSvc.updateOptions('#categoryDistributionChart', { labels: cats });
            ChartSvc.updateSeries('#categoryDistributionChart', vals);
        } else {
            ChartSvc.updateSeries('#categoryDistributionChart', []);
        }
    }

    _updateCategoryCards(categories) {
        const container = document.getElementById('category-distribution-cards');
        if (!container) return;

        if (!categories || categories.length === 0) {
            container.innerHTML = `<div class="text-center py-3 text-muted small">No expense categories this period</div>`;
            return;
        }

        const colors = ['#10B981','#EF4444','#F59E0B','#3B82F6','#8B5CF6','#EC4899','#14B8A6','#F97316'];
        let html = '';
        categories.forEach((c, i) => {
            const color = c.color || colors[i % colors.length];
            html += `
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom border-light">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge" style="background:${color}20;color:${color};width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:14px;">
                            <i class="${c.icon || 'fas fa-tag'}"></i>
                        </span>
                        <div>
                            <span class="fw-medium small">${this._esc(c.category)}</span>
                            <small class="d-block text-muted" style="font-size:11px;">Rs ${this._fmt(c.amount)}</small>
                        </div>
                    </div>
                    <div class="text-end" style="min-width:50px;">
                        <span class="fw-bold" style="color:${color};font-size:14px;">${c.percentage}%</span>
                    </div>
                </div>
                <div class="progress mb-2" style="height:4px;">
                    <div class="progress-bar" style="width:${c.percentage}%;background:${color};" role="progressbar"></div>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    _updateInsights(insights) {
        const container = document.getElementById('ai-insights-list');
        if (!container) return;
        if (!insights || insights.length === 0) {
            container.innerHTML = `<div class="text-center py-4 text-muted"><i class="fas fa-brain mb-2" style="font-size:2rem;opacity:0.3;"></i><p class="small">Add more transactions to get AI-powered insights.</p></div>`;
            return;
        }
        container.innerHTML = '';
        insights.forEach(ins => {
            const iconMap = { success: 'fa-check-circle text-success', warning: 'fa-exclamation-triangle text-warning', error: 'fa-times-circle text-danger', info: 'fa-info-circle text-info' };
            const icon = iconMap[ins.type] || 'fa-info-circle text-info';
            const item = document.createElement('div');
            item.className = 'insight-item d-flex align-items-start gap-2 py-2 border-bottom border-light';
            item.innerHTML = `<i class="fas ${icon} mt-1" style="font-size:14px;"></i><span class="small">${this._esc(ins.message)}</span>`;
            container.appendChild(item);
        });
    }

    _updateRecommendations(recs) {
        const container = document.getElementById('ai-recommendations-list');
        if (!container) return;
        if (!recs || recs.length === 0) {
            container.innerHTML = `<div class="text-center py-4 text-muted"><i class="fas fa-clipboard-list mb-2" style="font-size:2rem;opacity:0.3;"></i><p class="small">Add more transactions to get recommendations.</p></div>`;
            return;
        }
        container.innerHTML = '';
        recs.forEach(r => {
            const badgeClass = r.priority === 'high' ? 'bg-danger' : r.priority === 'medium' ? 'bg-warning text-dark' : 'bg-info';
            const item = document.createElement('div');
            item.className = 'recommendation-item d-flex justify-content-between align-items-start gap-2 py-2 border-bottom border-light';
            item.innerHTML = `<span class="small flex-grow-1">${this._esc(r.message)}</span><span class="badge ${badgeClass} flex-shrink-0" style="font-size:10px;">${r.priority}</span>`;
            container.appendChild(item);
        });
    }

    _updateMetrics(metrics) {
        if (!metrics) return;
        const anim = (id, val, suffix) => {
            const el = document.getElementById(id);
            if (!el) return;
            const CountUpCtor = window.CountUp || window.countUp?.CountUp || window.countUp;
            const display = `${val}${suffix || ''}`;
            if (CountUpCtor) {
                try {
                    const cu = new CountUpCtor(el, val, { duration: 1.5, suffix: suffix || '', decimalPlaces: 1 });
                    if (!cu.error) cu.start();
                    else el.textContent = display;
                } catch(e) { el.textContent = display; }
            } else {
                el.textContent = display;
            }
        };
        anim('savings-rate', metrics.savings_rate, '%');
        anim('monthly-growth', metrics.monthly_growth, '%');
        anim('goal-progress', metrics.goal_progress, '%');
        anim('budget-health', metrics.budget_health, '%');

        // Update income/expense display if those elements exist
        const ie = document.getElementById('analysis-income');
        if (ie && metrics.total_income !== undefined) ie.textContent = 'Rs ' + this._fmt(metrics.total_income);
        const ee = document.getElementById('analysis-expense');
        if (ee && metrics.total_expense !== undefined) ee.textContent = 'Rs ' + this._fmt(metrics.total_expense);
        const ne = document.getElementById('analysis-net');
        if (ne && metrics.net_savings !== undefined) {
            const net = metrics.net_savings;
            ne.textContent = 'Rs ' + this._fmt(Math.abs(net));
            ne.style.color = net >= 0 ? '#10B981' : '#EF4444';
        }
    }

    _initCharts() {
        const ChartSvc = window.ChartService;
        if (!ChartSvc) return;

        ChartSvc.create('#spendingTrendChart', {
            series: [{ name: 'Income', data: [] }, { name: 'Expense', data: [] }],
            chart: { type: 'area', height: 300, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
            colors: ['#10B981', '#EF4444'],
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.7, opacityTo: 0.2, stops: [0, 90, 100] } },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            xaxis: { categories: [], labels: { style: { colors: '#6B7280', fontSize: '12px' } } },
            yaxis: { labels: { style: { colors: '#6B7280', fontSize: '12px' }, formatter: v => this._fmt(v) } },
            tooltip: { y: { formatter: v => 'Rs ' + this._fmt(v) } },
            responsive: [{ breakpoint: 768, options: { chart: { height: 250 } } }]
        });

        ChartSvc.create('#categoryDistributionChart', {
            series: [],
            chart: { type: 'donut', height: 300, fontFamily: 'Inter, sans-serif' },
            labels: [],
            colors: ['#10B981','#EF4444','#F59E0B','#3B82F6','#8B5CF6','#EC4899','#14B8A6','#F97316'],
            plotOptions: { pie: { donut: { size: '70%' } } },
            dataLabels: { enabled: false },
            legend: { position: 'bottom', horizontalAlign: 'center' },
            tooltip: { y: { formatter: v => 'Rs ' + this._fmt(v) } }
        });
    }

    _fmt(n) { return (parseFloat(n) || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 }); }

    _esc(s) {
        if (!s) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    refresh() { this.loadAnalysisData(); }
}

window.AnalysisManager = AnalysisManager;

function exportAnalysis() {
    const d = window.analysisManager?._data;
    if (!d) { NotificationService?.warning('No analysis data to export'); return; }
    const rows = [['Metric', 'Value']];
    if (d.metrics) Object.entries(d.metrics).forEach(([k, v]) => rows.push([k.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()), v]));
    rows.push(['', ''], ['Insights', '']);
    if (d.insights) d.insights.forEach(i => rows.push([i.type, i.message]));
    rows.push(['', ''], ['Recommendations', '']);
    if (d.recommendations) d.recommendations.forEach(r => rows.push([r.priority, r.message]));
    const csv = '\uFEFF' + rows.map(r => r.map(c => '"' + String(c || '').replace(/"/g, '""') + '"').join(',')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'financial_analysis.csv'; a.click();
    URL.revokeObjectURL(a.href);
}

function printAnalysis() {
    const d = window.analysisManager?._data;
    if (!d) { NotificationService?.warning('No analysis data to print'); return; }
    const w = window.open('', '_blank');
    w.document.write(`<html><head><title>AI Financial Analysis</title><style>
        body{font-family:Arial,sans-serif;padding:40px;color:#333;max-width:900px;margin:auto}
        h1{color:#10B981;font-size:24px;border-bottom:2px solid #10B981;padding-bottom:10px;margin-bottom:30px}
        h2{font-size:18px;color:#374151;margin:24px 0 12px}
        .score{text-align:center;font-size:48px;font-weight:700;color:#10B981}
        .status{text-align:center;font-size:16px;color:#6B7280;margin-bottom:24px}
        .summary{background:#f0fdf4;padding:16px;border-radius:8px;margin-bottom:24px;font-size:14px;line-height:1.6}
        table{width:100%;border-collapse:collapse;margin:12px 0 24px}
        th{background:#f8fafc;border:1px solid #e2e8f0;padding:8px 10px;text-align:left;font-size:12px}
        td{border:1px solid #e2e8f0;padding:8px 10px;font-size:13px}
        .badge{padding:2px 8px;border-radius:4px;font-size:11px}
        .footer{text-align:center;color:#9CA3AF;font-size:11px;margin-top:40px;border-top:1px solid #e5e7eb;padding-top:20px}
    </style></head><body>
    <h1>AI Financial Analysis</h1>
    <div class="score">${d.score}/100</div>
    <div class="status">${d.health_status}</div>
    <div class="summary">${d.summary}</div>`);

    if (d.metrics) {
        w.document.write('<h2>Key Metrics</h2><table><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>');
        Object.entries(d.metrics).forEach(([k, v]) => {
            w.document.write(`<tr><td>${k.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</td><td>${v}</td></tr>`);
        });
        w.document.write('</tbody></table>');
    }

    if (d.category_distribution?.length) {
        w.document.write('<h2>Category Distribution</h2><table><thead><tr><th>Category</th><th>Amount</th><th>%</th></tr></thead><tbody>');
        d.category_distribution.forEach(c => w.document.write(`<tr><td>${c.category}</td><td>Rs ${parseFloat(c.amount).toLocaleString('en-IN')}</td><td>${c.percentage}%</td></tr>`));
        w.document.write('</tbody></table>');
    }

    if (d.insights?.length) {
        w.document.write('<h2>AI Insights</h2><table><thead><tr><th>Type</th><th>Insight</th></tr></thead><tbody>');
        d.insights.forEach(i => w.document.write(`<tr><td><span class="badge" style="background:${i.type === 'success' ? '#d1fae5' : i.type === 'warning' ? '#fef3c7' : i.type === 'error' ? '#fee2e2' : '#dbeafe'};color:${i.type === 'success' ? '#065f46' : i.type === 'warning' ? '#92400e' : i.type === 'error' ? '#991b1b' : '#1e40af'}">${i.type}</span></td><td>${i.message}</td></tr>`));
        w.document.write('</tbody></table>');
    }

    if (d.recommendations?.length) {
        w.document.write('<h2>Recommendations</h2><table><thead><tr><th>Priority</th><th>Recommendation</th></tr></thead><tbody>');
        d.recommendations.forEach(r => w.document.write(`<tr><td><span class="badge" style="background:${r.priority === 'high' ? '#fee2e2' : r.priority === 'medium' ? '#fef3c7' : '#dbeafe'};color:${r.priority === 'high' ? '#991b1b' : r.priority === 'medium' ? '#92400e' : '#1e40af'}">${r.priority}</span></td><td>${r.message}</td></tr>`));
        w.document.write('</tbody></table>');
    }

    w.document.write(`<div class="footer">Generated on ${new Date().toLocaleDateString('en-IN', {year:'numeric',month:'long',day:'numeric'})} | Sani Financial Manager</div>`);
    w.document.write('</body></html>');
    w.document.close();
    setTimeout(() => { w.focus(); w.print(); }, 500);
}

function shareAnalysis() {
    const d = window.analysisManager?._data;
    if (!d) { NotificationService?.warning('No analysis data to share'); return; }
    const summary = `My Financial Health Score: ${d.score}/100 (${d.health_status})\nSavings Rate: ${d.metrics?.savings_rate || 0}% | Income: Rs ${(d.metrics?.total_income || 0).toLocaleString('en-IN')} | Expense: Rs ${(d.metrics?.total_expense || 0).toLocaleString('en-IN')}\n\n${d.insights?.[0]?.message || ''}\n\nShared via Sani Financial Manager`;
    if (navigator.share) {
        navigator.share({ title: 'My Financial Health Score', text: summary }).catch(() => {});
    } else {
        navigator.clipboard.writeText(summary).then(() => NotificationService?.success('Summary copied to clipboard!')).catch(() => {});
    }
}
