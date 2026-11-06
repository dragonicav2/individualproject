<script setup>
import { Trophy } from '@lucide/vue';
defineProps({ rows: { type: Array, default: () => [] } });
</script>
<template><section class="detail-section"><h3><Trophy :size="15"/> Round-robin standings</h3><div v-if="!rows.length" class="empty-state">Standings appear after match results are confirmed.</div><div v-else class="table-wrap"><table class="standings-table"><thead><tr><th>PLACE</th><th>ENTRANT</th><th>W-L</th><th>POINTS</th><th>ROUND DIFF</th></tr></thead><tbody><tr v-for="(row,index) in rows" :key="row.entrant_id"><td>{{index+1}}</td><td>{{row.name}}</td><td>{{row.wins}}-{{row.losses}}</td><td>{{row.points}}</td><td>{{row.round_difference>0?'+':''}}{{row.round_difference}}</td></tr></tbody></table></div></section></template>
