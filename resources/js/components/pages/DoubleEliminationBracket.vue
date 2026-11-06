<script setup>
import { computed } from 'vue';

const props = defineProps({ matches: { type: Array, default: () => [] } });
const name = (entry) => entry?.team?.name || entry?.user?.name || 'TBD';
const lanes = computed(() => [
  { key: 'winners', title: 'Winners bracket', hint: 'No losses' },
  { key: 'losers', title: 'Losers bracket', hint: 'One loss' },
  { key: 'grand_final', title: 'Grand final', hint: 'Championship' },
].map((lane) => ({
  ...lane,
  matches: props.matches.filter((match) => (match.bracket_side || 'winners') === lane.key)
    .sort((a, b) => (Number(a.round) - Number(b.round)) || (Number(a.match_number) - Number(b.match_number))),
})));
</script>

<template>
  <section class="bracket-board double-bracket-board">
    <div class="bracket-board-head"><div><b>Double-elimination bracket</b><small>Entrants leave after two losses. New matchups appear as results are confirmed.</small></div><span>{{ matches.filter(m => ['completed','bye'].includes(m.status)).length }} / {{ matches.length }} resolved</span></div>
    <div class="double-bracket-lanes">
      <section v-for="lane in lanes" :key="lane.key" class="double-bracket-lane">
        <header><b>{{ lane.title }}</b><small>{{ lane.hint }}</small></header>
        <article v-for="match in lane.matches" :key="match.id" class="bracket-card" :class="match.status">
          <div class="bracket-card-head"><span>Round {{ match.round }} · Match {{ match.match_number }}</span><span>{{ match.status.replace('_',' ') }}</span></div>
          <div class="bracket-side" :class="{ winner: Number(match.winner_entrant_id) === Number(match.entrant_a_id) }"><b>{{ name(match.entrant_a) }}</b><strong v-if="match.winner_entrant_id && Number(match.winner_entrant_id) === Number(match.entrant_a_id)">WON</strong></div>
          <div class="bracket-side" :class="{ winner: Number(match.winner_entrant_id) === Number(match.entrant_b_id) }"><b>{{ name(match.entrant_b) }}</b><strong v-if="match.winner_entrant_id && Number(match.winner_entrant_id) === Number(match.entrant_b_id)">WON</strong></div>
          <div class="bracket-card-foot">{{ match.status === 'bye' ? 'Bye · advances without a loss' : match.score_a !== null ? `Score ${match.score_a} : ${match.score_b}` : match.status === 'scheduled' ? `Ready to play · BO${match.best_of}` : match.status.replace('_',' ') }}</div>
        </article>
        <div v-if="!lane.matches.length" class="double-bracket-empty">Matches appear here as earlier rounds finish.</div>
      </section>
    </div>
  </section>
</template>
