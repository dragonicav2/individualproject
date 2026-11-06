<script setup>
import { computed, onMounted, ref } from 'vue';
import { Activity, RefreshCw, ShieldAlert, Trophy, Users } from '@lucide/vue';
import { api } from '../../api';

const props = defineProps({ adminMode: { type: Boolean, default: false } });
const data = ref(null);
const period = ref('week');
const busy = ref(false);
const error = ref('');
const colors = { cs2: '#c28a34', valorant: '#d95d6d', sf6: '#469d8e', mlbb: '#705dce' };
const gameCodes = { cs2: 'CS2', valorant: 'VAL', sf6: 'SF6', mlbb: 'MLBB' };
const cards = computed(() => [
  { label: 'Player accounts', value: data.value?.totals.players || 0, detail: (data.value?.totals.new_players_in_period || 0) + ' new in this period', icon: Users, tone: 'blue' },
  { label: 'Tournaments', value: data.value?.totals.tournaments || 0, detail: (data.value?.totals.new_tournaments_in_period || 0) + ' created in this period', icon: Trophy, tone: 'orange' },
  { label: 'Verified matches', value: data.value?.totals.verified_matches || 0, detail: (data.value?.totals.active_tournaments || 0) + ' active tournaments', icon: Activity, tone: 'green' },
  { label: 'Needs attention', value: (data.value?.totals.pending_results || 0) + (data.value?.totals.open_disputes || 0), detail: (data.value?.totals.pending_results || 0) + ' pending · ' + (data.value?.totals.open_disputes || 0) + ' disputed', icon: ShieldAlert, tone: 'violet' },
]);
const chartCards = [
  { key: 'player_interest_growth', title: 'Player interest growth by game', subtitle: 'New player accounts counted in every game they selected.' },
  { key: 'tournament_creation', title: 'Tournaments created by game', subtitle: 'New tournaments created in each time bucket.' },
];
const maxFor = key => Math.max(1, ...(data.value?.charts?.[key] || []).flatMap(series => series.values || []));
const chartHeight = 210;
const chartWidth = 760;
const plot = { left: 48, right: 18, top: 16, bottom: 31 };
function chartPoints(series, max) {
  const values = series.values || [];
  const width = chartWidth - plot.left - plot.right;
  const height = chartHeight - plot.top - plot.bottom;
  return values.map((value, index) => {
    const x = plot.left + (values.length > 1 ? index * width / (values.length - 1) : width / 2);
    const y = plot.top + height - Number(value) * height / max;
    return { x, y, value, label: data.value?.buckets?.[index]?.label || '' };
  });
}
function pointString(series, max) {
  return chartPoints(series, max).map(point => point.x + ',' + point.y).join(' ');
}
const labelIndexes = computed(() => {
  const count = data.value?.buckets?.length || 0;
  if (count <= 5) return Array.from({ length: count }, (_, index) => index);
  return [...new Set([0, Math.round((count - 1) / 3), Math.round((count - 1) * 2 / 3), count - 1])];
});
const periodDescription = computed(() => ({ day: 'Last 14 days', week: 'Last 12 weeks', month: 'Last 12 months' }[period.value]));

async function load() {
  busy.value = true;
  error.value = '';
  try {
    data.value = (await api('/analytics/admin?period=' + period.value)).data;
  } catch (reason) {
    error.value = reason.message;
  } finally {
    busy.value = false;
  }
}
async function setPeriod(value) {
  if (period.value === value) return;
  period.value = value;
  await load();
}
function format(value) {
  return Number(value || 0).toLocaleString();
}
onMounted(load);
</script>

<template>
  <section class="admin-analytics panel">
    <header class="admin-analytics-header">
      <div>
        <div class="eyebrow"><span class="eyebrow-line"/> {{ props.adminMode ? 'ADMIN MONITORING' : 'ORGANIZER OVERVIEW' }}</div>
        <h2>Platform pulse</h2>
        <p>Growth, tournament activity, and result moderation across all supported games.</p>
      </div>
      <div class="admin-analytics-controls">
        <div class="admin-period-tabs" aria-label="Analytics time range">
          <button v-for="option in [{value:'day',label:'Day'},{value:'week',label:'Week'},{value:'month',label:'Month'}]" :key="option.value" :class="{active:period===option.value}" @click="setPeriod(option.value)">{{ option.label }}</button>
        </div>
        <button class="secondary-button admin-refresh" :disabled="busy" aria-label="Refresh admin analytics" @click="load"><RefreshCw :size="14" :class="{ 'demo-spin': busy }"/></button>
      </div>
    </header>

    <div v-if="error" class="form-error">{{ error }}</div>
    <div v-if="data" class="admin-analytics-content">
      <div class="admin-kpi-grid">
        <article v-for="card in cards" :key="card.label" class="admin-kpi-card">
          <div class="admin-kpi-top"><span>{{ card.label }}</span><i :class="card.tone"><component :is="card.icon" :size="16"/></i></div>
          <b>{{ format(card.value) }}</b>
          <small>{{ card.detail }}</small>
        </article>
      </div>

      <div class="admin-chart-grid">
        <article v-for="chart in chartCards" :key="chart.key" class="admin-chart-card">
          <header><div><h3>{{ chart.title }}</h3><p>{{ chart.subtitle }}</p></div><span>{{ periodDescription }}</span></header>
          <div class="admin-chart-wrap">
            <svg viewBox="0 0 760 210" role="img" :aria-label="chart.title">
              <g class="admin-chart-gridlines">
                <template v-for="tick in 5" :key="tick">
                  <line :x1="plot.left" :x2="chartWidth-plot.right" :y1="plot.top+(tick-1)*(chartHeight-plot.top-plot.bottom)/4" :y2="plot.top+(tick-1)*(chartHeight-plot.top-plot.bottom)/4"/>
                  <text x="2" :y="plot.top+(tick-1)*(chartHeight-plot.top-plot.bottom)/4+3">{{ Math.round(maxFor(chart.key)*(1-(tick-1)/4)) }}</text>
                </template>
              </g>
              <g v-for="series in data.charts[chart.key]" :key="series.code">
                <polyline v-if="series.values.some(value=>value>0)" :points="pointString(series,maxFor(chart.key))" fill="none" :stroke="colors[series.code]" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                <template v-for="(point,index) in chartPoints(series,maxFor(chart.key))" :key="series.code+'-'+index"><circle v-if="point.value>0" :cx="point.x" :cy="point.y" r="3.5" :fill="colors[series.code]"><title>{{ series.name }} · {{ point.label }}: {{ point.value }}</title></circle></template>
              </g>
              <g class="admin-chart-labels">
                <text v-for="index in labelIndexes" :key="index" :x="plot.left+(data.buckets.length>1?index*(chartWidth-plot.left-plot.right)/(data.buckets.length-1):0)" y="202" text-anchor="middle">{{ data.buckets[index].label }}</text>
              </g>
            </svg>
          </div>
          <div class="admin-chart-legend"><span v-for="series in data.charts[chart.key]" :key="series.code"><i :style="{background:colors[series.code]}"/>{{ series.name }}</span></div>
          <div v-if="!data.charts[chart.key].some(series=>series.values.some(value=>value>0))" class="admin-chart-empty">No activity in this range yet.</div>
        </article>
      </div>

      <section class="admin-game-breakdown">
        <header><div><h3>Game breakdown</h3><p>Players interested counts a player once under each selected game. Player slots count solo entrants as one and team entrants by roster size.</p></div><span>{{ data.totals.organizers }} organizers</span></header>
        <div class="admin-game-table-wrap">
          <table class="admin-game-table">
            <thead><tr><th>Game</th><th>Players interested</th><th>Entrant registrations</th><th>Player slots</th><th>Tournaments</th><th>Active</th><th>Verified matches</th></tr></thead>
            <tbody><tr v-for="game in data.games" :key="game.code"><td><i :style="{background:colors[game.code]}"/><b>{{ game.name }}</b></td><td>{{ format(game.players_interested) }}</td><td>{{ format(game.entrant_registrations) }}</td><td>{{ format(game.registered_player_slots) }}</td><td>{{ format(game.tournaments) }}</td><td>{{ format(game.active_tournaments) }}</td><td>{{ format(game.verified_matches) }}</td></tr></tbody>
          </table>
        </div>
      </section>
      <small class="admin-analytics-updated">Updated {{ new Date(data.updated_at).toLocaleString() }}</small>
    </div>
    <div v-else-if="busy" class="analytics-loading">Loading platform analytics…</div>
  </section>
</template>


