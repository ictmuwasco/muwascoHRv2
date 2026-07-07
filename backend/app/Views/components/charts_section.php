<?php

?>
<div class="w-full grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 overflow-hidden">
    <!-- Employee Distribution (Pie Chart) -->
    <div class="bg-white dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-2xl shadow-2xl p-4 lg:p-6 w-full min-w-0">
        <h3 class="text-base lg:text-lg font-semibold text-gray-900 dark:text-white mb-3 lg:mb-4">
            <i class="fas fa-chart-pie mr-2 text-primary-400"></i>Employee Distribution
        </h3>
        <div class="w-full h-48 lg:h-64 flex items-center justify-center">
            <canvas id="employeeDistributionChart"></canvas>
        </div>
    </div>

    <!-- Sections per Department (Bar Chart) -->
    <div class="bg-white dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-2xl shadow-2xl p-4 lg:p-6 w-full min-w-0">
        <h3 class="text-base lg:text-lg font-semibold text-gray-900 dark:text-white mb-3 lg:mb-4">
            <i class="fas fa-chart-bar mr-2 text-secondary-400"></i>Sections per Department
        </h3>
        <div class="w-full h-48 lg:h-64 flex items-center justify-center">
            <canvas id="sectionsPerDeptChart"></canvas>
        </div>
    </div>

    <!-- Leave Statistics -->
    <div class="bg-white dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-2xl shadow-2xl p-4 lg:p-6 w-full min-w-0">
        <h3 class="text-base lg:text-lg font-semibold text-gray-900 dark:text-white mb-3 lg:mb-4">
            <i class="fas fa-umbrella-beach mr-2 text-warning"></i>Leave Statistics (This Month)
        </h3>
        <div class="w-full h-48 lg:h-64 flex items-center justify-center">
            <canvas id="leaveStatsChart"></canvas>
        </div>
    </div>

    <!-- Appraisal Completion -->
    <div class="bg-white dark:bg-white/10 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-2xl shadow-2xl p-4 lg:p-6 w-full min-w-0">
        <h3 class="text-base lg:text-lg font-semibold text-gray-900 dark:text-white mb-3 lg:mb-4">
            <i class="fas fa-check-double mr-2 text-success"></i>Appraisal Completion
        </h3>
        <div class="w-full h-48 lg:h-64 flex items-center justify-center">
            <canvas id="appraisalChart"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const chartDefaults = {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                labels: { color: '#9ca3af', font: { size: 11 } }
            }
        }
    };

    const apiBase = window.location.pathname.includes('/hrdemo') ? '/hrdemo/api' : '/api';

    const fetchChartData = async (endpoint) => {
        const response = await fetch(`${apiBase}${endpoint}`, { credentials: 'same-origin' });
        if (!response.ok) {
            const text = await response.text();
            console.error(`Chart request failed for ${endpoint}:`, response.status, text);
            return null;
        }

        return response.json();
    };

    // ── Employee Distribution Pie ──────────────────────────────────────────
    fetchChartData('/charts/employee-distribution').then(data => {
        if (!data) return;

        new Chart(document.getElementById('employeeDistributionChart'), {
            type: 'pie',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: data.colors,
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 2,
                }]
            },
            options: chartDefaults
        });
    });

    // ── Sections per Department Bar ────────────────────────────────────────
    fetchChartData('/charts/sections-per-dept').then(data => {
        if (!data) return;

        new Chart(document.getElementById('sectionsPerDeptChart'), {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Sections',
                    data: data.values,
                    backgroundColor: 'rgba(108, 92, 231, 0.6)',
                    borderColor: '#6c5ce7',
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                ...chartDefaults,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#9ca3af' },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    },
                    x: {
                        ticks: { color: '#9ca3af' },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    }
                }
            }
        });
    });

    // ── Leave Stats ───────────────────────────────────────────────────────
    fetchChartData('/charts/leave-stats').then(data => {
        if (!data) return;

        new Chart(document.getElementById('leaveStatsChart'), {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: ['#00b894', '#fdcb6e', '#e17055', '#6c5ce7', '#00d4ff'],
                    borderWidth: 2,
                    borderColor: 'rgba(255,255,255,0.1)',
                }]
            },
            options: chartDefaults
        });
    });

    // ── Appraisal Completion ──────────────────────────────────────────────
    fetchChartData('/charts/appraisal-completion').then(data => {
        if (!data) return;

        new Chart(document.getElementById('appraisalChart'), {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Completed',
                        data: data.completed,
                        backgroundColor: 'rgba(0, 184, 148, 0.6)',
                        borderColor: '#00b894',
                        borderWidth: 1,
                        borderRadius: 4,
                    },
                    {
                        label: 'Pending',
                        data: data.pending,
                        backgroundColor: 'rgba(253, 203, 110, 0.6)',
                        borderColor: '#fdcb6e',
                        borderWidth: 1,
                        borderRadius: 4,
                    }
                ]
            },
            options: {
                ...chartDefaults,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#9ca3af' },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    },
                    x: {
                        ticks: { color: '#9ca3af' },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    }
                }
            }
        });
    });
});
</script>