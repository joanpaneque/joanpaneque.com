<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Crown, Flame } from 'lucide-vue-next';
import { Chart as ChartJS, registerables } from 'chart.js';
import { Bar, Doughnut, Line, PolarArea, Radar } from 'vue-chartjs';
import { darkChartOptions, doughnutOptions, polarOptions, radarOptions } from '../../Components/Personal/chartDefaults.js';

ChartJS.register(...registerables);

const props = defineProps({
    monthlyRows: {
        type: Array,
        required: true,
    },
    /** Lavados consecutivos a tiempo (desde el último registro hacia atrás). */
    teethPunctualityStreak: {
        type: Number,
        default: 0,
    },
    /** Mayor racha histórica de lavados a tiempo seguidos. */
    teethPunctualityStreakMax: {
        type: Number,
        default: 0,
    },
    phonePunctualityStreak: {
        type: Number,
        default: 0,
    },
    phonePunctualityStreakMax: {
        type: Number,
        default: 0,
    },
});

/** Ritmo esperado: 3 lavados/día vs 1 móvil/día → misma escala = lavados÷3. */
const TEETH_PER_DAY = 3;
const PHONE_PER_DAY = 1;
/** Referencia burda “días con hábito” por mes para el radar (ritmo). */
const APPROX_DAYS_PER_MONTH = 30;

function formatMonthKey(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');

    return `${y}-${m}`;
}

function parseMonthKey(key) {
    const [ys, ms] = key.split('-');

    return new Date(Number(ys), Number(ms) - 1, 1);
}

function addMonthsKey(key, delta) {
    const d = parseMonthKey(key);
    d.setMonth(d.getMonth() + delta);

    return formatMonthKey(d);
}

function emptyRowFor(monthKey) {
    const d = parseMonthKey(monthKey);
    const label = d.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });

    return {
        month_key: monthKey,
        label: label.charAt(0).toUpperCase() + label.slice(1),
        teeth_total: 0,
        teeth_on_time: 0,
        teeth_delayed: 0,
        phone_total: 0,
        phone_on_time: 0,
        phone_delayed: 0,
    };
}

/** Mes visible: por defecto el mes actual (p. ej. abril). */
const selectedMonthKey = ref(formatMonthKey(new Date()));

const maxNavKey = computed(() => formatMonthKey(new Date()));

const minNavKey = computed(() => {
    const d = new Date();
    d.setFullYear(d.getFullYear() - 3);

    return formatMonthKey(d);
});

const canGoPrev = computed(() => selectedMonthKey.value > minNavKey.value);

const canGoNext = computed(() => selectedMonthKey.value < maxNavKey.value);

function goMonth(delta) {
    const next = addMonthsKey(selectedMonthKey.value, delta);
    if (next < minNavKey.value || next > maxNavKey.value) {
        return;
    }
    selectedMonthKey.value = next;
}

const monthDisplay = computed(() => {
    const d = parseMonthKey(selectedMonthKey.value);
    const monthLong = d.toLocaleDateString('es-ES', { month: 'long' });
    const year = d.getFullYear();

    return {
        month: monthLong.charAt(0).toUpperCase() + monthLong.slice(1),
        year: String(year),
    };
});

const selectedRow = computed(() => {
    const found = props.monthlyRows.find((r) => r.month_key === selectedMonthKey.value);

    return found ?? emptyRowFor(selectedMonthKey.value);
});

const chronology = computed(() => [selectedRow.value]);

const shortMonth = (monthKey) => {
    const short = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    const [y, m] = monthKey.split('-');
    return `${short[parseInt(m, 10) - 1]} ’${y.slice(2)}`;
};

const totals = computed(() => {
    const r = selectedRow.value;
    const teethOn = r.teeth_on_time;
    const teethDel = r.teeth_delayed;
    const phoneOn = r.phone_on_time;
    const phoneDel = r.phone_delayed;
    const teeth = teethOn + teethDel;
    const phone = phoneOn + phoneDel;
    const teethEquiv = teeth / TEETH_PER_DAY;
    const phoneEquiv = phone / PHONE_PER_DAY;

    return {
        teethOn,
        teethDel,
        phoneOn,
        phoneDel,
        teeth,
        phone,
        teethEquiv,
        phoneEquiv,
        pctTeeth: teeth ? Math.round((teethOn / teeth) * 1000) / 10 : null,
        pctPhone: phone ? Math.round((phoneOn / phone) * 1000) / 10 : null,
    };
});

/** Un solo mes en vista: el radar usa m = 1. */
const monthsWithActivity = computed(() => 1);

const radarProfile = computed(() => {
    const t = totals.value;
    const m = monthsWithActivity.value;
    const punctTeeth = t.teeth ? (t.teethOn / t.teeth) * 100 : 0;
    const punctPhone = t.phone ? (t.phoneOn / t.phone) * 100 : 0;
    const teethEquiv = t.teeth / TEETH_PER_DAY;
    const phoneEquiv = t.phone / PHONE_PER_DAY;
    const rhythmTeeth = m > 0
        ? Math.min(100, (teethEquiv / m / APPROX_DAYS_PER_MONTH) * 100)
        : 0;
    const rhythmPhone = m > 0
        ? Math.min(100, (phoneEquiv / m / APPROX_DAYS_PER_MONTH) * 100)
        : 0;
    const sumEq = teethEquiv + phoneEquiv;
    const balance = sumEq > 0
        ? (100 - (Math.abs(teethEquiv - phoneEquiv) / sumEq) * 100)
        : 0;
    return {
        labels: ['Punt. lavados', 'Punt. móvil', 'Ritmo lavados', 'Ritmo móvil', 'Equilibrio'],
        data: [
            Math.round(punctTeeth),
            Math.round(punctPhone),
            Math.round(rhythmTeeth),
            Math.round(rhythmPhone),
            Math.round(balance),
        ],
    };
});

const lineVolume = computed(() => ({
    labels: chronology.value.map((r) => shortMonth(r.month_key)),
    datasets: [
        {
            label: 'Lavados (÷3, equiv. diaria)',
            data: chronology.value.map((r) => r.teeth_total / TEETH_PER_DAY),
            borderColor: '#2dd4bf',
            backgroundColor: 'rgba(45, 212, 191, 0.15)',
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointBackgroundColor: '#0f172a',
            pointBorderColor: '#2dd4bf',
            pointBorderWidth: 2,
        },
        {
            label: 'Móvil lejos (equiv. diaria)',
            data: chronology.value.map((r) => r.phone_total / PHONE_PER_DAY),
            borderColor: '#a78bfa',
            backgroundColor: 'rgba(167, 139, 250, 0.12)',
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointBackgroundColor: '#0f172a',
            pointBorderColor: '#a78bfa',
            pointBorderWidth: 2,
        },
    ],
}));

const linePunctuality = computed(() => ({
    labels: chronology.value.map((r) => shortMonth(r.month_key)),
    datasets: [
        {
            label: '% a tiempo (dientes)',
            data: chronology.value.map((r) => {
                const n = r.teeth_on_time + r.teeth_delayed;
                return n ? Math.round((r.teeth_on_time / n) * 100) : null;
            }),
            borderColor: '#34d399',
            backgroundColor: 'transparent',
            tension: 0.3,
            spanGaps: true,
            pointRadius: 3,
        },
        {
            label: '% a tiempo (móvil)',
            data: chronology.value.map((r) => {
                const n = r.phone_on_time + r.phone_delayed;
                return n ? Math.round((r.phone_on_time / n) * 100) : null;
            }),
            borderColor: '#e879f9',
            backgroundColor: 'transparent',
            tension: 0.3,
            spanGaps: true,
            pointRadius: 3,
        },
    ],
}));

const stackedTeeth = computed(() => ({
    labels: chronology.value.map((r) => shortMonth(r.month_key)),
    datasets: [
        {
            label: 'A tiempo',
            data: chronology.value.map((r) => r.teeth_on_time),
            backgroundColor: 'rgba(52, 211, 153, 0.85)',
            borderRadius: 4,
        },
        {
            label: 'Con retraso',
            data: chronology.value.map((r) => r.teeth_delayed),
            backgroundColor: 'rgba(251, 146, 60, 0.85)',
            borderRadius: 4,
        },
    ],
}));

const stackedPhone = computed(() => ({
    labels: chronology.value.map((r) => shortMonth(r.month_key)),
    datasets: [
        {
            label: 'A tiempo',
            data: chronology.value.map((r) => r.phone_on_time),
            backgroundColor: 'rgba(167, 139, 250, 0.9)',
            borderRadius: 4,
        },
        {
            label: 'Con retraso',
            data: chronology.value.map((r) => r.phone_delayed),
            backgroundColor: 'rgba(244, 114, 182, 0.85)',
            borderRadius: 4,
        },
    ],
}));

const doughnutTeeth = computed(() => ({
    labels: ['A tiempo', 'Con retraso'],
    datasets: [
        {
            data: [totals.value.teethOn, totals.value.teethDel],
            backgroundColor: ['#34d399', '#fb923c'],
            borderWidth: 0,
            hoverOffset: 8,
        },
    ],
}));

const doughnutPhone = computed(() => ({
    labels: ['A tiempo', 'Con retraso'],
    datasets: [
        {
            data: [totals.value.phoneOn, totals.value.phoneDel],
            backgroundColor: ['#a78bfa', '#f472b6'],
            borderWidth: 0,
            hoverOffset: 8,
        },
    ],
}));

const polarMix = computed(() => ({
    labels: ['Dientes · a tiempo (÷3)', 'Dientes · retraso (÷3)', 'Móvil · a tiempo', 'Móvil · retraso'],
    datasets: [
        {
            data: [
                totals.value.teethOn / TEETH_PER_DAY,
                totals.value.teethDel / TEETH_PER_DAY,
                totals.value.phoneOn / PHONE_PER_DAY,
                totals.value.phoneDel / PHONE_PER_DAY,
            ],
            backgroundColor: [
                'rgba(52, 211, 153, 0.75)',
                'rgba(251, 146, 60, 0.8)',
                'rgba(167, 139, 250, 0.85)',
                'rgba(244, 114, 182, 0.8)',
            ],
            borderWidth: 2,
            borderColor: '#0f172a',
        },
    ],
}));

const radarData = computed(() => ({
    labels: radarProfile.value.labels,
    datasets: [
        {
            label: 'Tu perfil',
            data: radarProfile.value.data,
            backgroundColor: 'rgba(45, 212, 191, 0.25)',
            borderColor: '#2dd4bf',
            borderWidth: 2,
            pointBackgroundColor: '#f8fafc',
            pointBorderColor: '#2dd4bf',
            pointHoverBackgroundColor: '#2dd4bf',
        },
    ],
}));

const barComparison = computed(() => ({
    labels: chronology.value.map((r) => shortMonth(r.month_key)),
    datasets: [
        {
            label: 'Dientes (total ÷3)',
            data: chronology.value.map((r) => r.teeth_total / TEETH_PER_DAY),
            backgroundColor: 'rgba(45, 212, 191, 0.75)',
            borderRadius: 6,
        },
        {
            label: 'Móvil (total)',
            data: chronology.value.map((r) => r.phone_total / PHONE_PER_DAY),
            backgroundColor: 'rgba(167, 139, 250, 0.75)',
            borderRadius: 6,
        },
    ],
}));

const stackedBarOptions = computed(() => ({
    ...darkChartOptions,
    plugins: {
        ...darkChartOptions.plugins,
        tooltip: darkChartOptions.plugins.tooltip,
    },
    scales: {
        x: {
            ...darkChartOptions.scales.x,
            stacked: true,
        },
        y: {
            ...darkChartOptions.scales.y,
            stacked: true,
        },
    },
}));

const lineOptions = computed(() => ({
    ...darkChartOptions,
    scales: {
        x: darkChartOptions.scales.x,
        y: {
            ...darkChartOptions.scales.y,
            beginAtZero: true,
            title: {
                display: true,
                text: 'Equiv. diaria (3 lavados ≡ 1 móvil)',
                color: '#64748b',
                font: { size: 11 },
            },
        },
    },
}));

const linePctOptions = computed(() => ({
    ...darkChartOptions,
    scales: {
        x: darkChartOptions.scales.x,
        y: {
            ...darkChartOptions.scales.y,
            min: 0,
            max: 100,
            ticks: {
                ...darkChartOptions.scales.y.ticks,
                callback: (v) => `${v}%`,
            },
            title: {
                display: true,
                text: 'Porcentaje',
                color: '#64748b',
                font: { size: 11 },
            },
        },
    },
}));

const barGroupedOptions = computed(() => ({
    ...darkChartOptions,
    scales: {
        x: darkChartOptions.scales.x,
        y: {
            ...darkChartOptions.scales.y,
            beginAtZero: true,
            title: {
                display: true,
                text: 'Equiv. mensual (lavados÷3, móvil×1)',
                color: '#64748b',
                font: { size: 11 },
            },
        },
    },
}));

function logout() {
    router.post('/personal/logout');
}
</script>

<template>
    <div>
        <Head title="Panel · hábitos">
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link
                rel="stylesheet"
                href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz@0,9..40,1,9..40&family=Instrument+Serif:ital@0;1&display=swap"
            >
        </Head>

        <div class="personal-panel min-h-screen text-slate-200">
            <!-- Fondo con malla + ruido -->
            <div class="pointer-events-none fixed inset-0 z-0 bg-[#070a0f]" />
            <div
                class="pointer-events-none fixed inset-0 z-0 opacity-[0.4]"
                style="background-image: radial-gradient(circle at 20% 20%, rgba(45,212,191,0.12), transparent 45%), radial-gradient(circle at 80% 10%, rgba(167,139,250,0.15), transparent 40%), radial-gradient(circle at 50% 90%, rgba(251,146,60,0.08), transparent 50%);"
            />
            <div
                class="pointer-events-none fixed inset-0 z-0 opacity-[0.035]"
                style="background-image: url('data:image/svg+xml,%3Csvg viewBox=%220 0 256 256%22 xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cfilter id=%22n%22%3E%3CfeTurbulence type=%22fractalNoise%22 baseFrequency=%220.9%22 numOctaves=%224%22 stitchTiles=%22stitch%22/%3E%3C/filter%3E%3Crect width=%22100%25%22 height=%22100%25%22 filter=%22url(%23n)%22/%3E%3C/svg%3E');"
            />

            <div class="relative z-10">
                <header class="border-b border-white/10 bg-[#0c1017]/80 backdrop-blur-md">
                    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-5 sm:px-8">
                        <div>
                            <p class="font-display text-2xl tracking-tight text-white sm:text-3xl">
                                Ritmos
                            </p>
                            <p class="mt-1 max-w-xl text-sm text-slate-400">
                                Europe/Madrid
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span v-if="$page.props.auth?.user" class="hidden text-sm text-slate-500 sm:inline">
                                {{ $page.props.auth.user.email }}
                            </span>
                            <button
                                type="button"
                                class="rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-slate-200 transition hover:bg-white/10"
                                @click="logout"
                            >
                                Salir
                            </button>
                        </div>
                    </div>
                </header>

                <main class="mx-auto max-w-7xl px-4 py-10 sm:px-8">
                    <div class="mb-10 flex flex-col items-center justify-center gap-2">
                        <div class="flex w-full max-w-2xl items-center justify-between gap-4 sm:gap-8">
                            <button
                                type="button"
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-white/15 bg-white/5 text-slate-200 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-25"
                                :disabled="!canGoPrev"
                                aria-label="Mes anterior"
                                @click="goMonth(-1)"
                            >
                                <ChevronLeft class="h-6 w-6" :stroke-width="2" aria-hidden="true" />
                            </button>
                            <div class="min-w-0 flex-1 text-center">
                                <p class="font-display text-5xl leading-none tracking-tight text-white sm:text-7xl md:text-8xl">
                                    {{ monthDisplay.month }}
                                </p>
                                <p class="mt-3 text-lg text-slate-500 sm:text-xl">
                                    {{ monthDisplay.year }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-white/15 bg-white/5 text-slate-200 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-25"
                                :disabled="!canGoNext"
                                aria-label="Mes siguiente"
                                @click="goMonth(1)"
                            >
                                <ChevronRight class="h-6 w-6" :stroke-width="2" aria-hidden="true" />
                            </button>
                        </div>
                        <p v-if="monthlyRows.length === 0" class="mt-4 text-center text-sm text-slate-500">
                            Aún no hay registros en el histórico; los gráficos muestran ceros hasta que lleguen datos.
                        </p>
                    </div>

                    <!-- KPIs -->
                        <section class="mb-10 grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-5 shadow-inner shadow-teal-500/5">
                                <p class="text-xs font-medium uppercase tracking-wider text-slate-500">
                                    Lavados total
                                </p>
                                <p class="mt-2 font-display text-4xl text-teal-300">
                                    {{ totals.teeth }}
                                </p>
                                <p class="mt-2 text-xs text-slate-500">
                                    Equiv. comparación: <span class="tabular-nums text-slate-400">{{ totals.teethEquiv.toFixed(1) }}</span> (÷{{ TEETH_PER_DAY }})
                                </p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-5">
                                <p class="text-xs font-medium uppercase tracking-wider text-slate-500">
                                    Móvil lejos total
                                </p>
                                <p class="mt-2 font-display text-4xl text-violet-300">
                                    {{ totals.phone }}
                                </p>
                                <p class="mt-2 text-xs text-slate-500">
                                    Equiv. <span class="tabular-nums text-slate-400">{{ totals.phoneEquiv }}</span> (= registros, ×{{ PHONE_PER_DAY }}/día)
                                </p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-5">
                                <p class="text-xs font-medium uppercase tracking-wider text-slate-500">
                                    Puntualidad dientes
                                </p>
                                <p class="mt-2 font-display text-4xl text-emerald-300">
                                    {{ totals.pctTeeth != null ? totals.pctTeeth + '%' : '—' }}
                                </p>
                                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs leading-snug text-slate-500">
                                    <p class="flex items-center gap-2">
                                        <Flame
                                            class="h-4 w-4 shrink-0 text-orange-400"
                                            :stroke-width="2"
                                            aria-hidden="true"
                                        />
                                        <span class="font-medium tabular-nums text-slate-300">{{ teethPunctualityStreak }}</span>
                                    </p>
                                    <p class="flex items-center gap-2" title="Mejor racha de la historia">
                                        <Crown
                                            class="h-4 w-4 shrink-0 text-amber-400"
                                            :stroke-width="2"
                                            aria-hidden="true"
                                        />
                                        <span class="font-medium tabular-nums text-slate-300">{{ teethPunctualityStreakMax }}</span>
                                    </p>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-5">
                                <p class="text-xs font-medium uppercase tracking-wider text-slate-500">
                                    Puntualidad móvil
                                </p>
                                <p class="mt-2 font-display text-4xl text-fuchsia-300">
                                    {{ totals.pctPhone != null ? totals.pctPhone + '%' : '—' }}
                                </p>
                                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs leading-snug text-slate-500">
                                    <p class="flex items-center gap-2">
                                        <Flame
                                            class="h-4 w-4 shrink-0 text-orange-400"
                                            :stroke-width="2"
                                            aria-hidden="true"
                                        />
                                        <span class="font-medium tabular-nums text-slate-300">{{ phonePunctualityStreak }}</span>
                                    </p>
                                    <p class="flex items-center gap-2" title="Mejor racha de la historia (móvil lejos)">
                                        <Crown
                                            class="h-4 w-4 shrink-0 text-amber-400"
                                            :stroke-width="2"
                                            aria-hidden="true"
                                        />
                                        <span class="font-medium tabular-nums text-slate-300">{{ phonePunctualityStreakMax }}</span>
                                    </p>
                                </div>
                            </div>
                        </section>

                        <!-- Bento -->
                        <div class="grid gap-6 lg:grid-cols-12">
                            <!-- Línea: volumen -->
                            <div class="lg:col-span-8">
                                <article class="h-full rounded-2xl border border-white/10 bg-[#0e141c]/90 p-6 backdrop-blur-sm">
                                    <h2 class="font-display text-xl text-white">
                                        Pulso mensual
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Este mes: lavados÷3 y móvil×1 (equiv. diaria).
                                    </p>
                                    <div class="mt-6 h-72 w-full">
                                        <Line :data="lineVolume" :options="lineOptions" />
                                    </div>
                                </article>
                            </div>

                            <!-- Polar -->
                            <div class="lg:col-span-4">
                                <article class="h-full rounded-2xl border border-white/10 bg-[#0e141c]/90 p-6 backdrop-blur-sm">
                                    <h2 class="font-display text-xl text-white">
                                        Mezcla global
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Pesos comparables: partes de dientes divididas entre 3; móvil en bruto (1/día).
                                    </p>
                                    <div class="mt-4 h-72 w-full">
                                        <PolarArea :data="polarMix" :options="polarOptions" />
                                    </div>
                                </article>
                            </div>

                            <!-- Apilado dientes -->
                            <div class="lg:col-span-6">
                                <article class="rounded-2xl border border-white/10 bg-[#0e141c]/90 p-6 backdrop-blur-sm">
                                    <h2 class="font-display text-xl text-white">
                                        Dientes · capas
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Este mes, recuentos reales (sin ÷3).
                                    </p>
                                    <div class="mt-6 h-64 w-full">
                                        <Bar :data="stackedTeeth" :options="stackedBarOptions" />
                                    </div>
                                </article>
                            </div>

                            <!-- Apilado móvil -->
                            <div class="lg:col-span-6">
                                <article class="rounded-2xl border border-white/10 bg-[#0e141c]/90 p-6 backdrop-blur-sm">
                                    <h2 class="font-display text-xl text-white">
                                        Móvil · capas
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Recuentos reales (1/día esperado): solo móvil lejos.
                                    </p>
                                    <div class="mt-6 h-64 w-full">
                                        <Bar :data="stackedPhone" :options="stackedBarOptions" />
                                    </div>
                                </article>
                            </div>

                            <!-- Línea % puntualidad -->
                            <div class="lg:col-span-7">
                                <article class="rounded-2xl border border-white/10 bg-[#0e141c]/90 p-6 backdrop-blur-sm">
                                    <h2 class="font-display text-xl text-white">
                                        Disciplina relativa
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        % a tiempo este mes (por hábito).
                                    </p>
                                    <div class="mt-6 h-64 w-full">
                                        <Line :data="linePunctuality" :options="linePctOptions" />
                                    </div>
                                </article>
                            </div>

                            <!-- Radar -->
                            <div class="lg:col-span-5">
                                <article class="h-full rounded-2xl border border-white/10 bg-[#0e141c]/90 p-6 backdrop-blur-sm">
                                    <h2 class="font-display text-xl text-white">
                                        Perfil
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Ritmo y equilibrio usan la escala 3:1; “equilibrio” mide cercanía entre volumen equiv. de ambos hábitos.
                                    </p>
                                    <div class="mt-4 h-72 w-full">
                                        <Radar :data="radarData" :options="radarOptions" />
                                    </div>
                                </article>
                            </div>

                            <!-- Barras agrupadas -->
                            <div class="lg:col-span-12">
                                <article class="rounded-2xl border border-white/10 bg-[#0e141c]/90 p-6 backdrop-blur-sm">
                                    <h2 class="font-display text-xl text-white">
                                        Comparativa directa
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Este mes: dientes en ÷3 frente a móvil ×1.
                                    </p>
                                    <div class="mt-6 h-72 w-full max-w-5xl mx-auto">
                                        <Bar :data="barComparison" :options="barGroupedOptions" />
                                    </div>
                                </article>
                            </div>

                            <!-- Donas -->
                            <div class="lg:col-span-6">
                                <article class="rounded-2xl border border-white/10 bg-[#0e141c]/90 p-6 backdrop-blur-sm">
                                    <h2 class="font-display text-lg text-white">
                                        Dientes · proporción
                                    </h2>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Dentro del hábito: a tiempo vs retraso (no compara con móvil).
                                    </p>
                                    <div class="mx-auto mt-4 h-56 w-full max-w-xs">
                                        <Doughnut :data="doughnutTeeth" :options="doughnutOptions" />
                                    </div>
                                </article>
                            </div>
                            <div class="lg:col-span-6">
                                <article class="rounded-2xl border border-white/10 bg-[#0e141c]/90 p-6 backdrop-blur-sm">
                                    <h2 class="font-display text-lg text-white">
                                        Móvil · proporción
                                    </h2>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Dentro del hábito: a tiempo vs retraso.
                                    </p>
                                    <div class="mx-auto mt-4 h-56 w-full max-w-xs">
                                        <Doughnut :data="doughnutPhone" :options="doughnutOptions" />
                                    </div>
                                </article>
                            </div>
                        </div>

                        <p class="mt-12 text-center text-sm text-slate-600">
                            <Link href="/" class="text-teal-500/90 hover:text-teal-400 hover:underline">
                                ← Inicio
                            </Link>
                        </p>
                </main>
            </div>
        </div>
    </div>
</template>

<style scoped>
.personal-panel {
    font-family: 'DM Sans', system-ui, sans-serif;
}
.font-display {
    font-family: 'Instrument Serif', ui-serif, Georgia, serif;
}
</style>
