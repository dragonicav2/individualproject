<script setup>
import { computed, reactive, watch } from 'vue';
import { Radio, ChevronRight, Clock3, CalendarDays, CheckCircle2, RefreshCw } from '@lucide/vue';

const props = defineProps({ matches: { type: Array, default: () => [] }, loading: Boolean, error: { type: String, default: '' } });
const emit = defineEmits(['browse', 'refresh', 'open-tournament', 'schedule', 'ready']);
const schedules = reactive({});
const active = computed(() => props.matches.filter(match => match.status === 'scheduled'));
const needsAttention = computed(() => props.matches.filter(match => ['result_pending', 'disputed'].includes(match.status)));
const gameNames = { cs2: 'Counter-Strike 2', valorant: 'VALORANT', sf6: 'Street Fighter 6', mlbb: 'Mobile Legends' };
function entrantName(entry) { return entry?.team?.name || entry?.user?.name || 'TBD'; }
function localDate(value) { if (!value) return ''; const d = new Date(value); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16); }
watch(() => props.matches, rows => rows.forEach(match => { schedules[match.id] ??= localDate(match.scheduled_at); }), { immediate: true });
</script>

<template>
  <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> LIVE COMPETITION</div><h1>Live matches</h1><p>Schedule fixtures, confirm player readiness, and follow up on results needing attention.</p></div><button class="secondary-button" :disabled="loading" @click="emit('refresh')"><RefreshCw :size="14"/> Refresh</button></section>
  <div v-if="error" class="form-error">{{ error }}</div>
  <div class="match-ops-summary"><article><small>UPCOMING</small><b>{{ active.length }}</b></article><article><small>NEEDS ATTENTION</small><b>{{ needsAttention.length }}</b></article><article><small>MY MATCHES</small><b>{{ matches.length }}</b></article></div>
  <section v-if="loading" class="live-placeholder panel"><RefreshCw :size="20" class="demo-spin"/><p>Loading your matches…</p></section>
  <section v-else-if="!matches.length" class="live-placeholder panel"><div class="live-placeholder-icon"><Radio :size="24"/></div><h2>No active matches yet</h2><p>Your scheduled games, pending score confirmations, and disputes will appear here when you join or organize a live tournament.</p><button class="secondary-button" @click="emit('browse')">Browse tournaments <ChevronRight :size="15"/></button></section>
  <section v-else class="live-match-list"><article v-for="match in matches" :key="match.id" class="live-match-card panel">
    <header><div><span>{{ gameNames[match.tournament?.game] || match.tournament?.game }} · {{ match.tournament?.name }}</span><h2>Round {{ match.round }} · Match {{ match.match_number }}</h2></div><span class="match-status" :class="match.status">{{ match.status.replace('_',' ') }}</span></header>
    <div class="live-match-score"><b>{{ entrantName(match.entrant_a) }}</b><strong>{{ match.score_a ?? '—' }} : {{ match.score_b ?? '—' }}</strong><b>{{ entrantName(match.entrant_b) }}</b></div>
    <div class="live-match-schedule"><CalendarDays :size="14"/><span>{{ match.scheduled_at ? new Date(match.scheduled_at).toLocaleString() : 'Time not set' }}</span><span>Best of {{ match.best_of }}</span></div>
    <div v-if="match.status==='scheduled'" class="ready-status"><span><CheckCircle2 :size="14"/> {{ match.entrant_a?.team?.name || match.entrant_a?.user?.name || 'Side A' }} {{ match.entrant_a_ready_at ? 'ready' : 'not ready' }}</span><span><CheckCircle2 :size="14"/> {{ match.entrant_b?.team?.name || match.entrant_b?.user?.name || 'Side B' }} {{ match.entrant_b_ready_at ? 'ready' : 'not ready' }}</span></div>
    <div v-if="match.game_server?.status==='ready' && (match.game_server.host || match.game_server.room_code)" class="live-room-access"><small>{{ match.tournament?.game==='mlbb' ? 'PLAYER ROOM CODE' : 'SERVER CONNECTION' }}</small><code v-if="match.tournament?.game==='mlbb' && match.game_server.room_code">{{ match.game_server.room_code }}</code><code v-else>{{ match.game_server.host }}{{ match.game_server.port ? `:${match.game_server.port}` : '' }}</code><span v-if="match.game_server.provider==='demo' || match.game_server.provider==='demo-room'">Demo details only · this does not connect to a live game.</span></div>
    <form v-if="match.is_organizer && match.status==='scheduled'" class="match-schedule-form" @submit.prevent="emit('schedule', match.id, schedules[match.id])"><label><Clock3 :size="14"/> Set match time<input v-model="schedules[match.id]" type="datetime-local" required/></label><button class="secondary-button">Save time</button></form>
    <div class="live-match-actions"><button v-if="match.my_side && match.status==='scheduled'" class="primary-button" @click="emit('ready', match.id, !match.my_side_ready)">{{ match.my_side_ready ? 'Undo ready' : 'Mark my side ready' }}</button><button class="text-button" @click="emit('open-tournament', match.tournament_id)">Open tournament <ChevronRight :size="13"/></button></div>
  </article></section>
</template>
