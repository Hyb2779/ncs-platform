import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(
    BarController,
    LineController,
    DoughnutController,
    BarElement,
    LineElement,
    PointElement,
    ArcElement,
    CategoryScale,
    LinearScale,
    Legend,
    Tooltip,
);

function cssColor(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value || fallback;
}

function western(value) {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(Number(value));
}

export function mountPanelCharts() {
    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        if (canvas.dataset.mounted === '1') {
            return;
        }

        canvas.dataset.mounted = '1';

        const payload = JSON.parse(canvas.dataset.chart);
        const type = payload.type || 'line';
        const rtl = document.documentElement.dir === 'rtl';
        const mobile = window.matchMedia('(max-width: 767px)').matches;
        const text = cssColor('--panel-chart-text', '#64748b');
        const grid = cssColor('--panel-chart-grid', '#E3E6EB');
        const palette = [
            cssColor('--chart-1', '#F5B83D'),
            cssColor('--chart-2', '#2563EB'),
            cssColor('--chart-3', '#0F766E'),
        ];
        const datasets = (payload.datasets || []).map((dataset, index) => {
            const color = palette[index % palette.length];

            return {
                borderColor: color,
                backgroundColor: type === 'line' ? color : color,
                ...dataset,
            };
        });
        const cartesian = type !== 'doughnut';
        const values = datasets
            .flatMap((dataset) => dataset.data || [])
            .map((value) => Number(value))
            .filter((value) => Number.isFinite(value));
        const negative = values.some((value) => value < 0);

        new Chart(canvas, {
            type,
            data: {
                labels: payload.labels || [],
                datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                locale: 'en-US',
                plugins: {
                    legend: {
                        display: datasets.length > 1 || type === 'doughnut',
                        position: mobile ? 'bottom' : 'top',
                        rtl,
                        labels: { color: text },
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const raw = context.parsed && typeof context.parsed === 'object'
                                    ? context.parsed.y
                                    : context.parsed;

                                return `${context.dataset.label || ''}: ${western(raw)}`;
                            },
                        },
                    },
                },
                scales: cartesian
                    ? {
                        x: {
                            reverse: rtl,
                            ticks: { color: text },
                            grid: { color: grid },
                        },
                        y: {
                            position: rtl ? 'right' : 'left',
                            beginAtZero: !negative,
                            min: negative ? Math.min(0, ...values) : undefined,
                            ticks: {
                                color: text,
                                callback: (value) => western(value),
                            },
                            grid: { color: grid },
                        },
                    }
                    : undefined,
            },
        });
    });
}
