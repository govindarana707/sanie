// AI Analysis Module
class AnalysisManager {
    constructor() {
        this.currentPeriod = 'month';
        this.spendingTrendChart = null;
        this.categoryDistributionChart = null;
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.initCharts();
        this.loadAnalysisData();
    }

    setupEventListeners() {
        // Period selector
        document.getElementById('analysis-period').addEventListener('change', (e) => {
            this.currentPeriod = e.target.value;
            this.loadAnalysisData();
        });
    }

    initCharts() {
        // Initialize Spending Trend Chart
        const spendingTrendOptions = {
            series: [{
                name: 'Spending',
                data: []
            }],
            chart: {
                type: 'area',
                height: 300,
                toolbar: {
                    show: false
                },
                fontFamily: 'Inter, sans-serif'
            },
            colors: ['#10B981'],
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.7,
                    opacityTo: 0.2,
                    stops: [0, 90, 100]
                }
            },
            dataLabels: {
                enabled: false
            },
            stroke: {
                curve: 'smooth',
                width: 2
            },
            xaxis: {
                categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                labels: {
                    style: {
                        colors: '#6B7280',
                        fontSize: '12px'
                    }
                }
            },
            yaxis: {
                labels: {
                    style: {
                        colors: '#6B7280',
                        fontSize: '12px'
                    },
                    formatter: (value) => {
                        return 'Rs ' + value.toLocaleString();
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: (value) => {
                        return 'Rs ' + value.toLocaleString();
                    }
                }
            },
            responsive: [{
                breakpoint: 768,
                options: {
                    chart: {
                        height: 250
                    }
                }
            }]
        };

        this.spendingTrendChart = new ApexCharts(document.querySelector('#spendingTrendChart'), spendingTrendOptions);
        this.spendingTrendChart.render();

        // Initialize Category Distribution Chart
        const categoryDistributionOptions = {
            series: [],
            chart: {
                type: 'donut',
                height: 300,
                fontFamily: 'Inter, sans-serif'
            },
            labels: [],
            colors: ['#10B981', '#EF4444', '#F59E0B', '#3B82F6', '#8B5CF6', '#EC4899', '#14B8A6', '#F97316'],
            plotOptions: {
                pie: {
                    donut: {
                        size: '70%'
                    }
                }
            },
            dataLabels: {
                enabled: false
            },
            legend: {
                position: 'bottom',
                horizontalAlign: 'center'
            },
            tooltip: {
                y: {
                    formatter: (value) => {
                        return 'Rs ' + value.toLocaleString();
                    }
                }
            },
            responsive: [{
                breakpoint: 768,
                options: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }]
        };

        this.categoryDistributionChart = new ApexCharts(document.querySelector('#categoryDistributionChart'), categoryDistributionOptions);
        this.categoryDistributionChart.render();
    }

    async loadAnalysisData() {
        try {
            // Simulate API call for now - in production, this would call the backend
            const mockData = {
                score: 75,
                status: 'Good',
                summary: 'Your financial health is good. You have a positive savings rate and are making progress towards your goals. Consider reducing discretionary spending to improve your score further.',
                monthly_data: [
                    {month: 'Jan', income: 50000, expense: 35000},
                    {month: 'Feb', income: 52000, expense: 38000},
                    {month: 'Mar', income: 48000, expense: 32000},
                    {month: 'Apr', income: 55000, expense: 40000},
                    {month: 'May', income: 51000, expense: 36000},
                    {month: 'Jun', income: 53000, expense: 37000}
                ],
                category_breakdown: [
                    {category: 'Food', amount: 15000},
                    {category: 'Transport', amount: 8000},
                    {category: 'Entertainment', amount: 5000},
                    {category: 'Shopping', amount: 7000},
                    {category: 'Bills', amount: 12000}
                ],
                insights: [
                    {text: 'Your food spending has increased by 15% compared to last month.', type: 'warning'},
                    {text: 'Great job! Your savings rate improved by 5% this month.', type: 'success'},
                    {text: 'Consider setting aside more for emergency fund.', type: 'info'}
                ],
                recommendations: [
                    {text: 'Reduce dining out expenses by cooking at home more often.', priority: 'high'},
                    {text: 'Review your subscriptions and cancel unused ones.', priority: 'medium'},
                    {text: 'Increase your monthly savings goal by 10%.', priority: 'low'}
                ],
                metrics: {
                    savings_rate: 25,
                    monthly_growth: 8,
                    goal_progress: 60,
                    budget_health: 75
                }
            };

            this.updateAnalysisUI(mockData);
        } catch (error) {
            console.error('Failed to load analysis data:', error);
            showToast('Failed to load analysis data', 'error');
        }
    }

    updateAnalysisUI(data) {
        // Update score with animation
        const scoreElement = document.getElementById('analysis-score');
        const CountUpCtor = window.CountUp || window.countUp?.CountUp || window.countUp;

        if (scoreElement && CountUpCtor) {
            const countUp = new CountUpCtor(scoreElement, data.score, {
                duration: 2,
                decimalPlaces: 0
            });
            if (!countUp.error) {
                countUp.start();
            }
        } else if (scoreElement) {
            scoreElement.textContent = data.score;
        }

        document.getElementById('analysis-status').textContent = data.status;
        document.getElementById('analysis-summary').textContent = data.summary;

        // Update charts
        this.updateCharts(data);

        // Update insights
        this.updateInsights(data.insights);

        // Update recommendations
        this.updateRecommendations(data.recommendations);

        // Update metrics
        this.updateMetrics(data.metrics);
    }

    updateCharts(data) {
        // Update Spending Trend Chart
        if (this.spendingTrendChart && data.monthly_data) {
            const months = data.monthly_data.map(d => d.month);
            const spending = data.monthly_data.map(d => d.expense);

            this.spendingTrendChart.updateOptions({
                xaxis: { categories: months }
            });
            this.spendingTrendChart.updateSeries([{
                name: 'Spending',
                data: spending
            }]);
        }

        // Update Category Distribution Chart
        if (this.categoryDistributionChart && data.category_breakdown) {
            const categories = data.category_breakdown.map(d => d.category);
            const amounts = data.category_breakdown.map(d => d.amount);

            this.categoryDistributionChart.updateOptions({
                labels: categories
            });
            this.categoryDistributionChart.updateSeries(amounts);
        }
    }

    updateInsights(insights) {
        const container = document.getElementById('ai-insights-list');
        container.innerHTML = '';

        insights.forEach(insight => {
            const icon = insight.type === 'success' ? 'fa-check-circle text-success' :
                         insight.type === 'warning' ? 'fa-exclamation-triangle text-warning' :
                         insight.type === 'error' ? 'fa-times-circle text-danger' :
                         'fa-info-circle text-info';

            const item = document.createElement('div');
            item.className = 'insight-item';
            item.innerHTML = `
                <i class="fas ${icon} me-2"></i>
                <span>${insight.text}</span>
            `;
            container.appendChild(item);
        });
    }

    updateRecommendations(recommendations) {
        const container = document.getElementById('ai-recommendations-list');
        container.innerHTML = '';

        recommendations.forEach(rec => {
            const badgeClass = rec.priority === 'high' ? 'bg-danger' :
                              rec.priority === 'medium' ? 'bg-warning' :
                              'bg-info';

            const item = document.createElement('div');
            item.className = 'recommendation-item';
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-start">
                    <span>${rec.text}</span>
                    <span class="badge ${badgeClass}">${rec.priority}</span>
                </div>
            `;
            container.appendChild(item);
        });
    }

    updateMetrics(metrics) {
        const animateMetric = (elementId, value) => {
            const element = document.getElementById(elementId);
            const CountUpCtor = window.CountUp || window.countUp?.CountUp || window.countUp;

            if (!element) {
                return;
            }

            if (CountUpCtor) {
                const countUp = new CountUpCtor(element, value, {
                    duration: 1.5,
                    suffix: '%',
                    decimalPlaces: 0
                });
                if (!countUp.error) {
                    countUp.start();
                }
            } else {
                element.textContent = `${value}%`;
            }
        };

        animateMetric('savings-rate', metrics.savings_rate);
        animateMetric('monthly-growth', metrics.monthly_growth);
        animateMetric('goal-progress', metrics.goal_progress);
        animateMetric('budget-health', metrics.budget_health);
    }
}

// Export, Print, Share functions
function exportAnalysis() {
    Swal.fire({
        title: 'Export Analysis',
        text: 'Choose export format:',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'PDF',
        cancelButtonText: 'Cancel',
        showDenyButton: true,
        denyButtonText: 'Excel',
        confirmButtonColor: '#10B981',
        cancelButtonColor: '#6B7280',
        denyButtonColor: '#3B82F6',
        background: 'var(--glass-bg)',
        backdrop: 'rgba(0, 0, 0, 0.7)',
        customClass: {
            popup: 'glass-modal',
            confirmButton: 'btn-premium',
            cancelButton: 'btn-premium',
            denyButton: 'btn-premium'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            showToast('Exporting to PDF...', 'info');
            // Implement PDF export
        } else if (result.isDenied) {
            showToast('Exporting to Excel...', 'info');
            // Implement Excel export
        }
    });
}

function printAnalysis() {
    window.print();
}

function shareAnalysis() {
    Swal.fire({
        title: 'Share Analysis',
        text: 'Share your financial analysis with:',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Email',
        cancelButtonText: 'Cancel',
        showDenyButton: true,
        denyButtonText: 'Link',
        confirmButtonColor: '#10B981',
        cancelButtonColor: '#6B7280',
        denyButtonColor: '#3B82F6',
        background: 'var(--glass-bg)',
        backdrop: 'rgba(0, 0, 0, 0.7)',
        customClass: {
            popup: 'glass-modal',
            confirmButton: 'btn-premium',
            cancelButton: 'btn-premium',
            denyButton: 'btn-premium'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            showToast('Opening email client...', 'info');
            // Implement email sharing
        } else if (result.isDenied) {
            showToast('Link copied to clipboard!', 'success');
            // Implement link sharing
        }
    });
}

// Initialize analysis manager when app loads
let analysisManager;
document.addEventListener('DOMContentLoaded', () => {
    if (window.authManager?.initPromise) {
        window.authManager.initPromise.then(() => {
            analysisManager = new AnalysisManager();
        });
    } else {
        analysisManager = new AnalysisManager();
    }
});
