// Centralized ApexCharts Service for safe chart creation, update, and lifecycle cleanup
class ChartService {
    static instances = new Map();

    static create(selector, options) {
        if (!window.ApexCharts) return null;

        const container = document.querySelector(selector);
        if (!container) return null;

        this.destroy(selector);

        try {
            const chart = new ApexCharts(container, options);
            chart.render();
            this.instances.set(selector, chart);
            return chart;
        } catch (error) {
            console.error(`[ChartService] Failed to create chart for ${selector}:`, error);
            return null;
        }
    }

    static updateOptions(selector, newOptions, redrawPaths = true, animate = true) {
        const chart = this.instances.get(selector);
        if (chart) {
            try {
                chart.updateOptions(newOptions, redrawPaths, animate);
            } catch (e) {
                console.error(`[ChartService] Failed to update options for ${selector}:`, e);
            }
        }
    }

    static updateSeries(selector, newSeries, animate = true) {
        const chart = this.instances.get(selector);
        if (chart) {
            try {
                chart.updateSeries(newSeries, animate);
            } catch (e) {
                console.error(`[ChartService] Failed to update series for ${selector}:`, e);
            }
        }
    }

    static resizeAll() {
        this.instances.forEach((chart) => {
            if (chart && typeof chart.render === 'function') {
                try {
                    // Trigger window resize event for ApexCharts to recalculate container dimensions
                    window.dispatchEvent(new Event('resize'));
                } catch (e) {}
            }
        });
    }

    static destroy(selector) {
        const chart = this.instances.get(selector);
        if (chart) {
            try {
                chart.destroy();
            } catch (e) {}
            this.instances.delete(selector);
        }
    }

    static destroyAll() {
        this.instances.forEach((chart) => {
            try {
                chart.destroy();
            } catch (e) {}
        });
        this.instances.clear();
    }
}

window.ChartService = ChartService;
