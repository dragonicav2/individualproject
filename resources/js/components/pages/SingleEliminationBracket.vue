<script setup>
import { computed } from 'vue';

const props = defineProps({ matches: { type: Array, default: () => [] }, seedingStrategy: { type: String, default: 'fixed' } });
const strategyNames = { random_each_round: 'random draw', high_vs_low: 'high-vs-low ELO', similar_elo: 'closest ELO' };
const entrantName = (entry) => entry?.team?.name || entry?.user?.name || null;
const firstRoundCount = computed(() => props.matches.filter((match) => Number(match.round) === 1).length);
const roundCount = computed(() => Math.max(1, Math.ceil(Math.log2(Math.max(2, firstRoundCount.value * 2)))));
const roundNames = ['Final', 'Semifinals', 'Quarterfinals', 'Round of 16', 'Round of 32', 'Round of 64', 'Round of 128'];
const rounds = computed(() => Array.from({ length: roundCount.value }, (_, index) => {
  const round = index + 1;
  const matchCount = Math.max(1, firstRoundCount.value >> index);
  const actual = new Map(props.matches.filter((match) => Number(match.round) === round).map((match) => [Number(match.match_number), match]));
  return {
    number: round,
    title: roundNames[roundCount.value - round] || `Round ${round}`,
    matches: Array.from({ length: matchCount }, (_, matchIndex) => {
      const number = matchIndex + 1;
      const match = actual.get(number);
      const leftSourceNumber = number * 2 - 1;
      const rightSourceNumber = number * 2;
      const previousRound = props.matches.filter((item) => Number(item.round) === round - 1);
      const sourceName = (sourceNumber) => {
        const source = previousRound.find((item) => Number(item.match_number) === sourceNumber);
        return source && ['completed', 'bye'].includes(source.status) ? entrantName(source.winner_entrant) || 'Winner advances' : `Winner · R${round - 1} M${sourceNumber}`;
      };
      const dynamicPairing = !match && round > 1 && props.seedingStrategy !== 'fixed';
      return {
        key: match?.id || `r${round}-m${number}`,
        number,
        match,
        projected: !match,
        a: match ? entrantName(match.entrant_a) || (round === 1 ? 'TBD' : sourceName(leftSourceNumber)) : dynamicPairing ? 'Advancing winner pool' : sourceName(leftSourceNumber),
        b: match ? entrantName(match.entrant_b) || (round === 1 ? 'TBD' : sourceName(rightSourceNumber)) : dynamicPairing ? `Paired by ${strategyNames[props.seedingStrategy]}` : sourceName(rightSourceNumber),
        winner: match ? entrantName(match.winner_entrant) : null,
        status: match?.status || 'waiting',
        score: match?.score_a != null ? `${match.score_a} : ${match.score_b}` : null,
      };
    }),
  };
}));
</script>

<template>
  <section class="bracket-board" aria-label="Single elimination bracket">
    <div class="bracket-board-head"><div><b>Bracket path</b><small>{{ seedingStrategy==='fixed'?'Round-by-round progression and projected matchups':`Winners are re-paired after each round by ${strategyNames[seedingStrategy]||'the selected policy'}.` }}</small></div><span>{{ matches.filter(m => ['completed','bye'].includes(m.status)).length }} / {{ matches.length }} matches resolved</span></div>
    <div class="bracket-rounds">
      <section v-for="round in rounds" :key="round.number" class="bracket-round">
        <h4>{{ round.title }} <small>R{{ round.number }}</small></h4>
        <article v-for="card in round.matches" :key="card.key" class="bracket-card" :class="[card.status, { projected: card.projected }]">
          <div class="bracket-card-head"><span>Match {{ card.number }}</span><span>{{ card.match?.status?.replace('_',' ') || 'upcoming' }}</span></div>
          <div class="bracket-side" :class="{ winner: card.winner && card.a === card.winner }"><b>{{ card.a }}</b><strong v-if="card.winner && card.a === card.winner">WINNER</strong></div>
          <div class="bracket-side" :class="{ winner: card.winner && card.b === card.winner }"><b>{{ card.b }}</b><strong v-if="card.winner && card.b === card.winner">WINNER</strong></div>
          <div class="bracket-card-foot"><span v-if="card.score">Score {{ card.score }}</span><span v-else-if="card.projected">Waiting for previous-round winners</span><span v-else-if="card.status === 'scheduled'">Ready to play · BO{{ card.match?.best_of }}</span><span v-else-if="card.status === 'result_pending'">Result needs confirmation</span><span v-else-if="card.status === 'bye'">Bye · advances automatically</span><span v-else>{{ card.status.replace('_',' ') }}</span></div>
        </article>
      </section>
    </div>
  </section>
</template>
