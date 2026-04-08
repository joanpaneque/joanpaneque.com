/** Opciones compartidas para tema oscuro (Chart.js 4). */
export const darkChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
        mode: 'index',
        intersect: false,
    },
    plugins: {
        legend: {
            labels: {
                color: '#94a3b8',
                font: { family: "'DM Sans', system-ui, sans-serif", size: 11 },
                boxWidth: 12,
                padding: 16,
            },
        },
        tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.95)',
            titleColor: '#f1f5f9',
            bodyColor: '#cbd5e1',
            borderColor: 'rgba(148, 163, 184, 0.3)',
            borderWidth: 1,
            padding: 12,
            cornerRadius: 8,
        },
    },
    scales: {
        x: {
            ticks: { color: '#64748b' },
            grid: { color: 'rgba(148, 163, 184, 0.12)' },
            border: { display: false },
        },
        y: {
            ticks: { color: '#64748b' },
            grid: { color: 'rgba(148, 163, 184, 0.12)' },
            border: { display: false },
        },
    },
};

export const doughnutOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '62%',
    plugins: {
        legend: {
            position: 'bottom',
            labels: {
                color: '#94a3b8',
                font: { family: "'DM Sans', system-ui, sans-serif", size: 11 },
                padding: 12,
            },
        },
        tooltip: darkChartOptions.plugins.tooltip,
    },
};

export const radarOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: darkChartOptions.plugins.tooltip,
    },
    scales: {
        r: {
            angleLines: { color: 'rgba(148, 163, 184, 0.2)' },
            grid: { color: 'rgba(148, 163, 184, 0.15)' },
            pointLabels: {
                color: '#94a3b8',
                font: { family: "'DM Sans', system-ui, sans-serif", size: 10 },
            },
            ticks: {
                display: false,
                backdropColor: 'transparent',
            },
            suggestedMin: 0,
            suggestedMax: 100,
        },
    },
};

export const polarOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'right',
            labels: {
                color: '#94a3b8',
                font: { family: "'DM Sans', system-ui, sans-serif", size: 11 },
            },
        },
        tooltip: darkChartOptions.plugins.tooltip,
    },
    scales: {
        r: {
            ticks: {
                display: false,
                backdropColor: 'transparent',
            },
            grid: { color: 'rgba(148, 163, 184, 0.12)' },
            angleLines: { display: false },
        },
    },
};
