<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { BarChart3, RefreshCw, Swords, Trophy, Users } from '@lucide/vue';
import { api } from '../../api';
import AdminAnalyticsPanel from './AdminAnalyticsPanel.vue';
import ProtectedGameImage from './ProtectedGameImage.vue';

const props = defineProps({ showOrganizerAnalytics: Boolean });
const overview = ref(null), assetRows = ref([]), activeGame = ref('cs2'), busy = ref(false), error = ref('');
const selectedPlayerId = ref(''), selectedPickName = ref(''), activeLeaderboard = ref('kd'), selectedMapName = ref(''), selectedTeamId = ref('');
const selectedGame = computed(() => overview.value?.games?.find(game => game.code === activeGame.value) || overview.value?.games?.[0] || null);
const selectedPlayer = computed(() => {
  const rows = selectedGame.value?.players || [];
  return rows.find(player => String(player.player_id) === String(selectedPlayerId.value)) || rows[0] || null;
});
const playerPickOptions = computed(() => {
  const picks = Object.entries(selectedPlayer.value?.pick_stats || {}).map(([name, stats]) => ({ name, stats }));
  const hasMulti = picks.some(pick => pick.stats.matches >= 2);
  return picks.sort((a, b) => {
    if (hasMulti && (a.stats.matches >= 2) !== (b.stats.matches >= 2)) return a.stats.matches >= 2 ? -1 : 1;
    return (b.stats.win_rate - a.stats.win_rate) || (b.stats.matches - a.stats.matches) || a.name.localeCompare(b.name);
  });
});
const bestPlayerPick = computed(() => playerPickOptions.value[0] || null);
const selectedPlayerPick = computed(() => playerPickOptions.value.find(pick => pick.name === selectedPickName.value) || bestPlayerPick.value);
const gameColors = { cs2: '#c28a34', valorant: '#d95d6d', sf6: '#469d8e', mlbb: '#705dce' };
const pickLabels = { cs2: 'Recorded weapon', valorant: 'Agent', sf6: 'Character', mlbb: 'Hero' };
const leaderboardFilters = [{ key: 'kd', label: 'Top K/D' }, { key: 'kda', label: 'Top K/D/A' }, { key: 'elo_rating', label: 'Player ELO' }, { key: 'wins', label: 'Most wins' }, { key: 'matches', label: 'Most played' }];
const currentLeaderboard = computed(() => selectedGame.value?.player_leaderboards?.[activeLeaderboard.value]?.players || []);
const currentMap = computed(() => selectedGame.value?.map_agent_usage?.find(map => map.name === selectedMapName.value) || selectedGame.value?.map_agent_usage?.[0] || null);
const currentTeam = computed(() => selectedGame.value?.teams?.find(team => String(team.team_id) === String(selectedTeamId.value)) || selectedGame.value?.teams?.[0] || null);

watch(selectedGame, game => {
  const rows = game?.players || [];
  if (!rows.some(player => String(player.player_id) === String(selectedPlayerId.value))) selectedPlayerId.value = rows[0]?.player_id ?? '';
  selectedPickName.value = '';
  if (!game?.player_leaderboards?.[activeLeaderboard.value]) activeLeaderboard.value = 'kd';
  selectedMapName.value = game?.map_agent_usage?.[0]?.name || '';
  selectedTeamId.value = game?.teams?.[0]?.team_id || '';
}, { immediate: true });
watch(selectedPlayerId, () => { selectedPickName.value = ''; });

async function load() {
  busy.value = true; error.value = '';
  try { const [analytics, assets] = await Promise.all([api('/analytics/overview'), api('/game-assets').catch(() => ({ data: [] }))]); overview.value = analytics.data; assetRows.value = assets.data || []; }
  catch (reason) { error.value = reason.message; }
  finally { busy.value = false; }
}
function mainPlayerMetrics(player, game) {
  const rows = [
    { label: 'Matches', value: player.matches }, { label: 'W–L', value: player.wins + '–' + player.losses },
    { label: 'Win rate', value: player.win_rate + '%' },
  ];
  if (game === 'cs2') rows.push({ label: 'K / D / A', value: player.kills + ' / ' + player.deaths + ' / ' + player.assists }, { label: 'K/D', value: player.kd }, { label: 'ADR', value: player.adr });
  else if (game === 'valorant') rows.push({ label: 'ELO', value: player.elo_rating }, { label: 'ACS', value: player.acs }, { label: 'K / D / A', value: player.kills + ' / ' + player.deaths + ' / ' + player.assists }, { label: 'K/D', value: player.kd }, { label: 'First bloods', value: player.first_bloods }, { label: 'Plants', value: player.plants });
  else if (game === 'mlbb') rows.push({ label: 'K / D / A', value: player.kills + ' / ' + player.deaths + ' / ' + player.assists }, { label: 'KDA', value: player.kda }, { label: 'Towers', value: player.towers });
  else rows.push({ label: 'Sets won', value: player.sets_won }, { label: 'Perfect rounds', value: player.perfect_rounds }, { label: 'Drive impacts', value: player.drive_impacts });
  return rows;
}
function pickMetrics(pick, game) {
  const stats = pick?.stats;
  if (!stats) return [];
  const rows = [{ label: 'Matches', value: stats.matches }, { label: 'W–L', value: stats.wins + '–' + stats.losses }, { label: 'Win rate', value: stats.win_rate + '%' }];
  if (game === 'cs2') rows.push({ label: 'K / D / A', value: stats.kills + ' / ' + stats.deaths + ' / ' + stats.assists }, { label: 'K/D', value: stats.kd }, { label: 'ADR', value: stats.adr });
  else if (game === 'valorant') rows.push({ label: 'ACS', value: stats.acs }, { label: 'K / D / A', value: stats.kills + ' / ' + stats.deaths + ' / ' + stats.assists }, { label: 'K/D', value: stats.kd }, { label: 'First bloods', value: stats.first_bloods }, { label: 'Plants', value: stats.plants });
  else if (game === 'mlbb') rows.push({ label: 'K / D / A', value: stats.kills + ' / ' + stats.deaths + ' / ' + stats.assists }, { label: 'KDA', value: stats.kda }, { label: 'Towers', value: stats.towers });
  else rows.push({ label: 'Sets won', value: stats.sets_won }, { label: 'Perfect rounds', value: stats.perfect_rounds }, { label: 'Drive impacts', value: stats.drive_impacts });
  return rows;
}
function leaderboardScore(player) {
  if (activeLeaderboard.value === 'elo_rating') return player.elo_rating + ' ELO';
  if (activeLeaderboard.value === 'wins') return player.wins + ' wins';
  if (activeLeaderboard.value === 'matches') return player.matches + ' matches';
  if (activeLeaderboard.value === 'kda') return player.kda + ' KDA';
  return player.kd + ' K/D';
}
function playerInitials(name) { return String(name || 'P').split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase(); }
function assetSlug(name) { return String(name || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
function assetUrl(game, category, name) { const slug = assetSlug(name); return assetRows.value.find(asset => asset.game === game && asset.category === category && asset.slug === slug)?.image_url || ''; }
function gameAsset(kind, name) { const categories = { agents: 'agent', weapons: 'weapon', maps: 'map' }; return assetUrl(selectedGame.value?.code, categories[kind] || kind, name); }
function distributionCategory(game) { return { cs2: 'map', valorant: 'agent', sf6: 'character', mlbb: 'hero' }[game] || 'map'; }
function mapCategory(game) { return game === 'sf6' ? 'stage' : 'map'; }
onMounted(load);
</script>

<template>
<section class="page-heading analytics-heading"><div><div class="eyebrow"><span class="eyebrow-line"/> VERIFIED PERFORMANCE</div><h1>Analytics</h1><p>Game-by-game ELO and performance summaries from confirmed match results.</p></div><button class="secondary-button analytics-refresh" :disabled="busy" @click="load"><RefreshCw :size="14" :class="{ 'demo-spin': busy }"/> Refresh</button></section>
<AdminAnalyticsPanel v-if="props.showOrganizerAnalytics" />
<div v-if="error" class="form-error analytics-error">{{ error }}</div>
<section v-if="overview" class="stats-grid analytics-cards">
  <article class="stat-card"><div class="stat-top"><span>Verified matches</span><div class="stat-icon violet"><Swords :size="17"/></div></div><div class="stat-value">{{ overview.totals.verified_matches }}</div><div class="stat-foot"><span>Confirmed or organizer-resolved</span></div></article>
  <article class="stat-card"><div class="stat-top"><span>Active tournaments</span><div class="stat-icon blue"><Trophy :size="17"/></div></div><div class="stat-value">{{ overview.totals.active_tournaments }}</div><div class="stat-foot"><span>Registration open or in progress</span></div></article>
  <article class="stat-card"><div class="stat-top"><span>Entrant registrations</span><div class="stat-icon orange"><Users :size="17"/></div></div><div class="stat-value">{{ overview.totals.entrants }}</div><div class="stat-foot"><span>Across supported games</span></div></article>
  <article class="stat-card"><div class="stat-top"><span>Game leaderboards</span><div class="stat-icon violet"><BarChart3 :size="17"/></div></div><div class="stat-value">{{ overview.totals.games_tracked }}</div><div class="stat-foot"><span>Separate rating per game</span></div></article>
</section>
<section v-if="overview" class="analytics-game-tabs"><button v-for="game in overview.games" :key="game.code" :class="{ active: activeGame===game.code }" :style="{ '--game-accent': gameColors[game.code] }" @click="activeGame=game.code">{{ game.name }}</button></section>

<div v-if="selectedGame">
  <section class="analytics-game-intro"><ProtectedGameImage class="analytics-game-cover" :src="gameAsset('game', selectedGame.code)" :alt="selectedGame.name" :fallback="selectedGame.code.toUpperCase()"/><div><span :style="{ color: gameColors[selectedGame.code] }">{{ selectedGame.elo_kind }}</span><h2>{{ selectedGame.name }}</h2><p>{{ selectedGame.focus }} · Data updates from verified matches.</p></div><span class="analytics-match-count">{{ selectedGame.verified_matches }} verified matches</span></section>
  <section class="analytics-metric-grid"><article v-for="metric in selectedGame.metrics" :key="metric.label" class="analytics-metric panel"><span>{{ metric.label }}</span><b>{{ metric.value }}</b></article></section>

  <section class="analytics-detail-grid">
    <article class="panel analytics-panel"><div class="analytics-panel-head"><div><h2>{{ selectedGame.elo_kind }} rankings</h2><p>Ratings update from verified match results.</p></div><span class="rating-k">K{{ selectedGame.code==='sf6'?'40':'32' }}</span></div><div v-if="selectedGame.leaderboard.length" class="ranking-table"><div class="ranking-head"><span>Rank / entrant</span><span>Rating</span><span>W — L</span></div><div v-for="entrant in selectedGame.leaderboard" :key="entrant.key" class="ranking-row"><span><i :class="{ champion: entrant.rank===1 }">{{ String(entrant.rank).padStart(2,'0') }}</i><b>{{ entrant.name }}</b><small>{{ entrant.kind }} · {{ entrant.matches }} matches</small></span><strong>{{ entrant.rating }}</strong><em>{{ entrant.wins }} — {{ entrant.losses }}</em></div></div><div v-else class="analytics-empty">No verified results for this game yet. Generate demo data to populate its ratings.</div></article>
    <article class="panel analytics-panel"><div class="analytics-panel-head"><div><h2>{{ selectedGame.code==='cs2'?'Map pool':selectedGame.code==='valorant'?'Agent picks':selectedGame.code==='sf6'?'Character picks':'Hero picks' }}</h2><p>Most common in verified match data.</p></div><BarChart3 :size="17"/></div><div v-if="selectedGame.distribution.length" class="distribution-list"><div v-for="item in selectedGame.distribution" :key="item.name" class="distribution-item"><div class="distribution-copy"><ProtectedGameImage class="distribution-art" :src="assetUrl(selectedGame.code, distributionCategory(selectedGame.code), item.name)" :alt="item.name" :fallback="playerInitials(item.name)"/><span><b>{{ item.name }}</b><small>{{ item.count }} picks · {{ item.share }}%</small></span></div><div class="distribution-track"><i :style="{ width: item.percent + '%', background: gameColors[selectedGame.code] }"/></div></div></div><div v-else class="analytics-empty">No game-specific stats have been recorded yet.</div></article>
  </section>

  <section v-if="selectedGame.code!=='valorant' && selectedGame.map_agent_usage?.length" class="panel analytics-panel analytics-map-gallery"><div class="analytics-panel-head"><div><h2>{{ selectedGame.code==='sf6' ? 'Stage art' : 'Map art' }}</h2><p>Artwork linked to map or stage names in verified match data.</p></div></div><div class="analytics-map-cards"><article v-for="map in selectedGame.map_agent_usage" :key="map.name"><ProtectedGameImage :src="assetUrl(selectedGame.code, mapCategory(selectedGame.code), map.name)" :alt="map.name" :fallback="playerInitials(map.name)"/><b>{{ map.name }}</b><small>{{ map.matches }} matches</small></article></div></section>

  <section v-if="selectedGame.code==='valorant'" class="valorant-analytics-block">
    <div class="analytics-section-title"><div><span class="eyebrow"><span class="eyebrow-line"/> VALORANT COMPETITIVE</span><h2>Player leaderboards</h2><p>Compare individual match performance and team-result ELO.</p></div><span class="analytics-source-note">{{ selectedGame.stat_matches }} matches with entered stats</span></div>
    <nav class="val-filter-tabs" aria-label="Valorant leaderboard filters"><button v-for="filter in leaderboardFilters" :key="filter.key" :class="{active:activeLeaderboard===filter.key}" @click="activeLeaderboard=filter.key">{{ filter.label }}</button></nav>
    <TransitionGroup name="leaderboard-swap" tag="div" class="val-leaderboard-grid">
      <article v-for="(player,index) in currentLeaderboard" :key="activeLeaderboard+'-'+player.player_id" class="val-player-card" :style="{'--card-index':index}">
        <div class="val-player-portrait"><img v-if="player.avatar_url" :src="player.avatar_url" :alt="player.name"/><span v-else>{{ playerInitials(player.name) }}</span></div>
        <div class="val-player-main"><small>#{{ String(index+1).padStart(2,'0') }} · {{ activeLeaderboard==='elo_rating'?'PLAYER ELO':activeLeaderboard==='wins'?'MOST WINS':activeLeaderboard==='matches'?'MOST PLAYED':'TOP PERFORMANCE' }}</small><b>{{ player.name }}</b><span>{{ player.kills }} / {{ player.deaths }} / {{ player.assists }} K/D/A · {{ player.acs }} ACS</span></div><strong>{{ leaderboardScore(player) }}</strong>
      </article>
    </TransitionGroup>
    <div v-if="!currentLeaderboard.length" class="analytics-empty">No Valorant player stat lines yet. Confirm results and enter player stats to build these leaderboards.</div>

    <div class="val-insight-grid">
      <article class="panel analytics-panel val-usage-panel"><div class="analytics-panel-head"><div><h2>Agent &amp; weapon usage</h2><p>Pick counts use entered player stat lines, not game telemetry.</p></div><BarChart3 :size="17"/></div>
        <div class="val-usage-columns">
          <div><h3>Most picked agents</h3><div v-if="selectedGame.distribution.length" class="val-pick-list"><div v-for="item in selectedGame.distribution" :key="item.name" class="val-pick-row"><ProtectedGameImage class="val-agent-portrait" :src="gameAsset('agents',item.name)" :alt="item.name" :fallback="playerInitials(item.name)"/><div><b>{{ item.name }}</b><small>{{ item.count }} picks · {{ item.share }}%</small><i><em :style="{width:item.percent+'%'}"/></i></div></div></div><div v-else class="analytics-empty">No agent picks recorded.</div></div>
          <div><h3>Most used guns</h3><div v-if="selectedGame.weapons?.length" class="val-pick-list"><div v-for="item in selectedGame.weapons.slice(0,8)" :key="item.name" class="val-pick-row"><ProtectedGameImage class="val-weapon-glyph" :src="gameAsset('weapons',item.name)" :alt="item.name" fallback="⌁"/><div><b>{{ item.name }}</b><small>{{ item.count }} recorded loadouts</small><i><em :style="{width:item.percent+'%'}"/></i></div></div></div><div v-else class="analytics-empty">Gun loadouts appear after organizers enter weapons used in match details.</div></div>
        </div>
      </article>
      <article class="panel analytics-panel val-map-panel"><div class="analytics-panel-head"><div><h2>Map &amp; agent meta</h2><p>Agents seen on each most-played map.</p></div><Trophy :size="17"/></div>
        <div v-if="selectedGame.map_agent_usage?.length" class="val-map-browser"><nav><button v-for="map in selectedGame.map_agent_usage" :key="map.name" :class="{active:currentMap?.name===map.name}" @click="selectedMapName=map.name"><span>{{ map.name }}</span><small>{{ map.matches }} matches</small></button></nav><div v-if="currentMap" class="val-map-detail"><ProtectedGameImage class="val-map-art" :src="gameAsset('maps',currentMap.name)" :alt="currentMap.name" :fallback="playerInitials(currentMap.name)" :label="currentMap.name"/><h3>Top agents · {{ currentMap.name }}</h3><div v-for="(agent,index) in currentMap.agents" :key="agent.name" class="val-map-agent"><span>{{ String(index+1).padStart(2,'0') }}</span><b>{{ agent.name }}</b><i><em :style="{width:agent.percent+'%'}"/></i><strong>{{ agent.count }}</strong></div><div v-if="!currentMap.agents.length" class="analytics-empty">No agent picks recorded for this map.</div></div></div><div v-else class="analytics-empty">Map and agent trends appear when both are entered in match stats.</div>
      </article>
    </div>

    <article class="panel analytics-panel val-team-panel"><div class="analytics-panel-head"><div><h2>Team scouting</h2><p>Team record, map history, agent pool, and player contributions.</p></div><Users :size="17"/></div>
      <template v-if="selectedGame.teams?.length"><label class="val-team-select">Select team<select v-model="selectedTeamId"><option v-for="team in selectedGame.teams" :key="team.team_id" :value="team.team_id">{{ team.name }} · {{ team.matches }} matches</option></select></label><div v-if="currentTeam" class="val-team-report"><header><div><span>TEAM REPORT</span><h3>{{ currentTeam.name }}</h3></div><strong>{{ currentTeam.win_rate }}%<small>win rate</small></strong></header><div class="val-team-kpis"><div><small>Record</small><b>{{ currentTeam.wins }}–{{ currentTeam.losses }}</b></div><div><small>Team K/D</small><b>{{ currentTeam.kd }}</b></div><div><small>Rounds won</small><b>{{ currentTeam.rounds_won }}</b></div><div><small>First bloods</small><b>{{ currentTeam.first_bloods }}</b></div><div><small>Plants</small><b>{{ currentTeam.plants }}</b></div></div><div class="val-team-columns"><div><h4>Agent pool</h4><span v-for="agent in currentTeam.agents.slice(0,8)" :key="agent.name" class="val-team-agent">{{ agent.name }} <b>{{ agent.count }}</b></span></div><div><h4>Map history</h4><span v-for="map in currentTeam.maps.slice(0,6)" :key="map.name" class="val-team-agent">{{ map.name }} <b>{{ map.matches }}</b></span></div></div><div class="val-team-roster"><h4>Player contributions</h4><div v-for="player in currentTeam.players" :key="player.player_id"><span class="val-mini-avatar">{{ playerInitials(player.name) }}</span><b>{{ player.name }}</b><small>{{ player.matches }} matches · {{ player.wins }} wins</small><strong>{{ player.kda }} KDA · {{ player.acs }} ACS</strong><em>{{ player.top_agent || 'No agent' }}</em></div></div></div></template><div v-else class="analytics-empty">Team scouting appears for completed matches registered by teams with saved player stats.</div>
    </article>
  </section>

  <section class="panel analytics-panel analytics-players" :style="{ '--game-accent': gameColors[selectedGame.code] }">
    <div class="analytics-panel-head"><div><h2>Player &amp; pick performance</h2><p>Compare a player’s overall record with their results on each {{ pickLabels[selectedGame.code].toLowerCase() }}.</p></div><Users :size="17"/></div>
    <div v-if="selectedGame.players.length && selectedPlayer" class="player-pick-explorer"><div class="player-pick-controls"><label>Player<select v-model="selectedPlayerId"><option v-for="player in selectedGame.players" :key="player.player_id" :value="player.player_id">{{ player.name }} · {{ player.matches }} match stat lines</option></select></label><label>{{ pickLabels[selectedGame.code] }} performance<select :value="selectedPlayerPick?.name || ''" @change="selectedPickName=$event.target.value"><option v-for="pick in playerPickOptions" :key="pick.name" :value="pick.name">{{ pick.name }} · {{ pick.stats.matches }} matches · {{ pick.stats.win_rate }}% wins</option></select></label></div><div class="player-pick-columns"><article class="player-performance-card"><div class="player-performance-title"><span>PLAYER OVERALL</span><b>{{ selectedPlayer.name }}</b><small>{{ selectedPlayer.matches }} stat lines · {{ selectedPlayer.wins }} wins</small></div><div class="player-pick-metrics"><div v-for="metric in mainPlayerMetrics(selectedPlayer,selectedGame.code)" :key="metric.label"><small>{{ metric.label }}</small><b>{{ metric.value }}</b></div></div></article><article v-if="selectedPlayerPick" class="player-performance-card pick-performance-card"><div class="player-performance-title"><span>{{ selectedPlayerPick.name === bestPlayerPick?.name ? 'BEST RECORDED PICK' : 'PICK PERFORMANCE' }}</span><b>{{ selectedPlayerPick.name }}</b><small>{{ selectedPlayerPick.stats.wins }} wins from {{ selectedPlayerPick.stats.matches }} matches</small></div><div class="player-pick-metrics"><div v-for="metric in pickMetrics(selectedPlayerPick,selectedGame.code)" :key="metric.label"><small>{{ metric.label }}</small><b>{{ metric.value }}</b></div></div><small v-if="selectedGame.code==='cs2'" class="pick-context-note">CS2 combat stats are match totals for matches where this weapon was recorded; they are not per-weapon kill counts.</small></article></div></div><div v-else class="analytics-empty">Player pick statistics appear after demo data or verified match stats are recorded.</div>
  </section>
</div>
<div v-else-if="busy" class="analytics-loading panel">Loading verified match analytics…</div>
</template>
