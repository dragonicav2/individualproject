<script setup>
import { computed, ref } from 'vue';
import { CalendarDays, ChevronRight, Plus, Search, Trophy, Users } from '@lucide/vue';
const props = defineProps({ tournaments: { type: Array, default: () => [] }, canCreate: Boolean });
const emit = defineEmits(['create', 'open', 'refresh']);
const search = ref('');
const visible = computed(() => props.tournaments.filter(t => `${t.name} ${t.game} ${t.status}`.toLowerCase().includes(search.value.toLowerCase())));
</script>

<template>
  <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> COMPETITION DIRECTORY</div><h1>Tournaments</h1><p>Find events to join or manage tournaments you organize.</p></div><button v-if="canCreate" class="primary-button" @click="emit('create')"><Plus :size="17"/> Create tournament</button></section>
  <section class="directory-toolbar"><div class="directory-search"><Search :size="17"/><input v-model="search" placeholder="Search tournaments or games"/></div><button class="secondary-button" @click="emit('refresh')">Refresh list</button></section>
  <section class="directory-grid"><article v-for="t in visible" :key="t.id" class="event-card"><div class="event-card-top"><div class="game-mark large" :style="{ '--game-color': t.accent }">{{ t.gameCode === 'valorant' ? 'V' : t.gameCode === 'sf6' ? '拳' : '◉' }}</div><span class="status-badge" :class="t.status === 'In progress' ? 'in-progress' : t.status === 'Draft' ? 'draft' : 'open'"><i></i>{{ t.status }}</span></div><h2>{{ t.name }}</h2><p>{{ t.game }} <span>·</span> {{ t.format }}</p><div class="event-card-meta"><span><Users :size="14"/> {{ t.teams }}</span><span><CalendarDays :size="14"/> {{ t.date }}</span></div><button class="secondary-button event-open" @click="emit('open',t)">View tournament <ChevronRight :size="15"/></button><small v-if="t.demo" class="sample-caption">Illustrative sample event</small></article><div v-if="!visible.length" class="empty-directory"><Trophy :size="25"/><b>No tournaments found</b><span>Create an event or try another search.</span></div></section>
</template>
