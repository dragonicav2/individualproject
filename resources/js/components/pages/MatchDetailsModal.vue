<script setup>
import { computed, ref, watch } from 'vue';
import { X, Pencil, Plus, Trash2, Save, Copy } from '@lucide/vue';

const props = defineProps({ match: { type: Object, required: true }, game: { type: String, required: true }, teamSize: { type: Number, default: 1 }, server: { type: Object, default: null }, roomCode: { type: String, default: '' }, canEditRoomCode: Boolean, canEditStats: Boolean, canStopServer: Boolean, canHostMlbbRoom: Boolean, canPreviewPlayer: Boolean, ocrResult: { type: Object, default: null }, extractingOcr: Boolean, ocrError: { type: String, default: '' }, openingRoom: Boolean, roomError: { type: String, default: '' }, saving: Boolean });
const emit = defineEmits(['close', 'save-stats', 'stop-server', 'download-demo', 'open-mlbb-room', 'save-room-code', 'copy-room-code', 'preview-player', 'extract-screenshot']);
const editing = ref(false);
const draft = ref(null);
const error = ref('');
const roomCodeDraft = ref(props.roomCode);
watch(() => props.roomCode, (value) => { roomCodeDraft.value = value || ''; });
function saveRoomCode() { emit('save-room-code', { match: props.match, room_code: roomCodeDraft.value.trim() }); }
const labels = { cs2: 'Counter-Strike 2', valorant: 'VALORANT', sf6: 'Street Fighter 6', mlbb: 'Mobile Legends' };
const pickLabel = computed(() => ({ cs2: 'Weapon', valorant: 'Agent', sf6: 'Character', mlbb: 'Hero' }[props.game]));
const pickField = computed(() => ({ cs2: 'weapon', valorant: 'agent', sf6: 'character', mlbb: 'hero' }[props.game]));
const metricFields = computed(() => ({
  cs2: [['kills', 'Kills'], ['deaths', 'Deaths'], ['assists', 'Assists'], ['adr', 'ADR']],
  valorant: [['kills', 'Kills'], ['deaths', 'Deaths'], ['assists', 'Assists'], ['acs', 'ACS'], ['first_bloods', 'First bloods'], ['plants', 'Plants']],
  sf6: [['sets_won', 'Sets won'], ['perfect_rounds', 'Perfect rounds'], ['drive_impacts', 'Drive impacts'], ['rounds_won', 'Rounds won']],
  mlbb: [['kills', 'Kills'], ['deaths', 'Deaths'], ['assists', 'Assists'], ['gold', 'Gold'], ['towers', 'Towers']],
}[props.game]));
const teamMetricFields = computed(() => ({ cs2: [['rounds_won', 'Rounds won']], valorant: [['rounds_won', 'Rounds won'], ['plants', 'Team plants']], mlbb: [['towers', 'Towers']], sf6: [] }[props.game]));
const usesKda = computed(() => ['cs2', 'valorant', 'mlbb'].includes(props.game));
const stats = computed(() => props.match.game_stats || null);
const sides = computed(() => [
  { side: 'A', entry: props.match.entrant_a, score: props.match.score_a },
  { side: 'B', entry: props.match.entrant_b, score: props.match.score_b },
].map((row) => {
  const key = String(row.entry?.id ?? '');
  const detail = key ? stats.value?.entrants?.[key] : null;
  const roster = row.entry?.user ? [row.entry.user] : (row.entry?.team?.members || []).map(member => member.user).filter(Boolean);
  return { ...row, name: row.entry?.team?.name || row.entry?.user?.name || 'TBD', detail, roster, players: detail?.players || [] };
}));
const noRoster = computed(() => sides.value.some(row => row.roster.length < props.teamSize));
const statRows = computed(() => sides.value.map(row => ({ ...row, rows: draft.value?.entrants?.[String(row.entry?.id)]?.players || [], teamMetrics: draft.value?.entrants?.[String(row.entry?.id)] || {} })));
function displayName(key) { return key.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
function kda(player) { return ((Number(player.kills || 0) + Number(player.assists || 0)) / Math.max(1, Number(player.deaths || 0))).toFixed(2); }
function defaultPlayer(player = {}) {
  const row = { player_id: Number(player.player_id || player.id), name: player.name || '', [pickField.value]: player[pickField.value] || '' };
  if (props.game === 'mlbb') row.items = Array.isArray(player.items) ? player.items : [];
  if (props.game === 'valorant') row.weapons = Array.isArray(player.weapons) ? player.weapons : [];
  for (const [field] of metricFields.value) row[field] = Number(player[field] ?? 0);
  return row;
}
function beginEdit() {
  error.value = '';
  const entrants = {};
  for (const side of sides.value) {
    const key = String(side.entry?.id ?? '');
    const existing = side.detail?.players || [];
    const rows = existing.map(defaultPlayer);
    for (const user of side.roster) {
      if (rows.length >= props.teamSize) break;
      if (!rows.some(row => Number(row.player_id) === Number(user.id))) rows.push(defaultPlayer(user));
    }
    entrants[key] = { ...Object.fromEntries(teamMetricFields.value.map(([field]) => [field, Number(side.detail?.[field] ?? 0)])), players: rows };
  }
  draft.value = { map: stats.value?.map || '', duration: stats.value?.duration || '', entrants };
  editing.value = true;
}
function availableRoster(side) { return side.roster.filter(user => !statRows.value.find(row => Number(row.entry?.id) === Number(side.entry?.id))?.rows.some(line => Number(line.player_id) === Number(user.id))); }
function addPlayer(side) {
  const key = String(side.entry?.id ?? '');
  const candidate = availableRoster(side)[0];
  if (candidate) draft.value.entrants[key].players.push(defaultPlayer(candidate));
}
function removePlayer(side, index) { draft.value.entrants[String(side.entry.id)].players.splice(index, 1); }
function playerName(side, player) { return side.roster.find(user => Number(user.id) === Number(player.player_id))?.name || player.name || 'Select player'; }
function itemBuild(player) { return (player.items || []).join(', '); }
function updateItemBuild(player, value) { player.items = value.split(',').map(item => item.trim()).filter(Boolean).slice(0, 6); }
function weaponLoadout(player) { return (player.weapons || []).join(', '); }
function updateWeapons(player, value) { player.weapons = value.split(',').map(item => item.trim()).filter(Boolean).slice(0, 5); }
function extractScreenshot(event) { const file = event.target.files?.[0]; if (file) emit('extract-screenshot', file); event.target.value = ''; }
function applyOcrDraft() {
  if (!props.ocrResult?.players?.length) return;
  beginEdit();
  draft.value.duration = props.ocrResult.summary?.duration || draft.value.duration;
  for (const side of sides.value) {
    const source = props.ocrResult.players.filter(player => player.side === side.side.toLowerCase());
    const rows = draft.value.entrants[String(side.entry.id)].players;
    source.slice(0, rows.length).forEach((player, index) => {
      rows[index].kills = Number(player.kills ?? rows[index].kills);
      rows[index].deaths = Number(player.deaths ?? rows[index].deaths);
      rows[index].assists = Number(player.assists ?? rows[index].assists);
      rows[index].gold = Number(player.gold ?? rows[index].gold);
      if (player.hero_suggestion?.name) rows[index].hero = player.hero_suggestion.name;
      rows[index].items = (player.item_suggestions || []).map(item => item.name).filter(Boolean);
    });
  }
}
function save() {
  error.value = '';
  if (noRoster.value) { error.value = 'The match entrant roster is not available. Refresh entrants and try again.'; return; }
  for (const side of sides.value) {
    const form = draft.value.entrants[String(side.entry.id)];
    if (form.players.length !== props.teamSize) { error.value = `Enter exactly ${props.teamSize} player stat lines for ${side.name}.`; return; }
    if (new Set(form.players.map(player => Number(player.player_id))).size !== form.players.length) { error.value = `A player appears more than once for ${side.name}.`; return; }
  }
  emit('save-stats', { map: draft.value.map, entrants: draft.value.entrants });
}
watch(() => props.match.game_stats, () => { if (editing.value) editing.value = false; });
</script>

<template>
  <div class="match-detail-backdrop" @click.self="emit('close')">
    <section class="match-detail-modal" role="dialog" aria-modal="true" :aria-label="`${labels[game]} match details`">
      <header class="match-detail-head"><div><span>{{ labels[game] }} · {{ match.bracket_side?.replace('_',' ') || `Round ${match.round}` }}</span><h2>Match {{ match.match_number }} details</h2><small>Best of {{ match.best_of }} · {{ match.status.replace('_',' ') }}</small></div><button class="icon-button" aria-label="Close match details" @click="emit('close')"><X :size="18"/></button></header>
      <div class="match-detail-score"><div v-for="row in sides" :key="row.side" :class="{ champion: Number(match.winner_entrant_id) === Number(row.entry?.id) }"><small>ENTRANT {{ row.side }}</small><b>{{ row.name }}</b></div><strong>{{ match.score_a ?? '—' }} <i>:</i> {{ match.score_b ?? '—' }}</strong></div>
      <section v-if="game==='mlbb' && canHostMlbbRoom" class="match-detail-server"><b>Mobile Legends emulator station</b><p>Open BlueStacks, create the in-game room, then publish its code so both match sides can see it.</p><div v-if="roomError" class="form-error">{{ roomError }}</div><button class="primary-button" :disabled="openingRoom" @click="emit('open-mlbb-room', match)">{{ openingRoom ? 'Opening station…' : 'Open emulator station' }}</button><form v-if="canEditRoomCode" class="mlbb-room-code-editor" @submit.prevent="saveRoomCode"><label>In-game room code<input v-model="roomCodeDraft" required maxlength="80" placeholder="Enter the code shown in Mobile Legends"/></label><button class="secondary-button" :disabled="saving">{{ saving ? 'Sharing…' : roomCode ? 'Update shared code' : 'Share with players' }}</button></form><div v-if="roomCode" class="mlbb-room-code-display"><span>Shared player room code</span><code>{{ roomCode }}</code><button class="text-button" @click="emit('copy-room-code', roomCode)"><Copy :size="13"/> Copy</button></div></section>
      <section v-else-if="game==='mlbb' && roomCode" class="match-detail-server mlbb-player-room-code"><b>Player room code</b><p>Use this in-game code to join the organizer’s Mobile Legends room.</p><div class="mlbb-room-code-display"><code>{{ roomCode }}</code><button class="secondary-button" @click="emit('copy-room-code', roomCode)"><Copy :size="13"/> Copy room code</button></div></section>
      <section v-if="canPreviewPlayer" class="match-detail-server mlbb-player-preview"><b>Preview this match as a player</b><p>Open a separate temporary player session for either participating side.</p><div><button class="secondary-button" @click="emit('preview-player', { match, side: 'a' })">Preview {{ sides[0].name }} view</button><button class="secondary-button" @click="emit('preview-player', { match, side: 'b' })">Preview {{ sides[1].name }} view</button></div></section>
      <section v-if="match.disputes?.length" class="match-detail-disputes"><h3>Result history</h3><article v-for="dispute in match.disputes" :key="dispute.id"><b>{{ dispute.raiser?.name || 'Entrant' }} disputed the reported score</b><p>{{ dispute.reason }}</p><div v-if="dispute.resolution" class="match-detail-resolution"><small>Organizer resolution</small><p>{{ dispute.resolution }}</p></div></article></section>
      <section v-if="match.result_corrections?.length" class="match-detail-disputes"><h3>Score corrections</h3><article v-for="correction in match.result_corrections" :key="correction.id"><b>{{ correction.corrector?.name || 'Organizer' }} changed {{ correction.previous_score_a }} : {{ correction.previous_score_b }} to {{ correction.corrected_score_a }} : {{ correction.corrected_score_b }}</b><p>{{ correction.reason }}</p></article></section>
      <section v-if="game==='mlbb' && canEditStats" class="mlbb-ocr-import"><div><b>Import scoreboard screenshot</b><p>PaddleOCR reads the text and numbers locally. Hero and item portraits are suggestions that must be reviewed.</p></div><label class="secondary-button ocr-upload-button"><input type="file" accept="image/png,image/jpeg,image/webp" @change="extractScreenshot"/>{{ extractingOcr ? 'Reading screenshot…' : 'Upload screenshot' }}</label><div v-if="ocrError" class="form-error">{{ ocrError }}</div><template v-if="ocrResult"><div class="ocr-summary"><span>Score {{ ocrResult.summary?.score_a ?? '—' }} : {{ ocrResult.summary?.score_b ?? '—' }}</span><span v-if="ocrResult.summary?.duration">{{ ocrResult.summary.duration }}</span><span v-if="ocrResult.summary?.battle_id">Battle ID {{ ocrResult.summary.battle_id }}</span></div><div class="ocr-review-list"><div v-for="player in ocrResult.players" :key="`${player.side}-${player.row}`"><b>{{ player.source_name }}</b><span>{{ player.kills ?? '—' }}/{{ player.deaths ?? '—' }}/{{ player.assists ?? '—' }} · {{ player.gold ?? '—' }} gold</span><small>Hero: {{ player.hero_suggestion?.name || 'needs review' }} · {{ player.item_suggestions?.filter(item => item.name).length || 0 }} item suggestions</small></div></div><button class="text-button" @click="applyOcrDraft">Use as editable stats draft</button></template></section>
      <section class="match-detail-stats"><div class="match-detail-section-head"><b>Game stats</b><span v-if="stats?.map">Map · {{ stats.map }}</span><button v-if="canEditStats && match.status==='completed' && !editing" class="secondary-button stats-edit-button" @click="beginEdit"><Pencil :size="13"/> {{stats ? 'Edit stats' : 'Enter stats'}}</button></div>
        <template v-if="stats"><div v-if="stats.duration" class="match-stat-duration">Duration · {{ stats.duration }}</div><div class="match-detail-sides"><article v-for="row in sides" :key="row.side" class="match-detail-team"><h3>{{ row.name }}</h3><div class="match-detail-metrics"><span v-for="metric in (game==='cs2'?['kills','deaths','assists','rounds_won']:game==='valorant'?['kills','deaths','assists','first_bloods','plants','rounds_won']:game==='sf6'?['sets_won','perfect_rounds','drive_impacts','rounds_won']:['kills','deaths','assists','gold','towers'])" v-show="row.detail?.[metric] !== undefined" :key="metric"><small>{{ displayName(metric) }}</small><b>{{ row.detail?.[metric] }}</b></span></div><div v-if="row.players.length" class="match-detail-player-list"><div class="match-detail-player-head"><span>Player</span><span>{{ pickLabel }}</span><span v-for="([field,label]) in metricFields.slice(0,5)" :key="field">{{ label }}</span><span v-if="usesKda">KDA</span></div><div v-for="player in row.players" :key="player.player_id" class="match-detail-player-row"><b>{{ player.name }}</b><span>{{ player[pickField] || '—' }}</span><span v-for="([field]) in metricFields.slice(0,5)" :key="field">{{ player[field] ?? '—' }}</span><span v-if="usesKda">{{ kda(player) }}</span><small v-if="game==='valorant'&&player.weapons?.length" class="mlbb-build">Weapons: {{ player.weapons.join(' · ') }}</small><small v-if="game==='mlbb'&&player.items?.length" class="mlbb-build">{{ player.items.join(' · ') }}</small></div></div></article></div></template>
        <div v-else-if="!editing" class="match-detail-empty">Detailed stats are not recorded for this match yet.</div>
        <form v-if="editing" class="stats-editor" @submit.prevent="save"><div class="stats-top-fields"><label class="stats-map-field">Map / stage<input v-model="draft.map" maxlength="80" placeholder="Optional"/></label><label v-if="game==='mlbb'" class="stats-map-field">Match duration<input v-model="draft.duration" maxlength="16" placeholder="e.g. 15:41"/></label></div><div v-if="error" class="form-error">{{ error }}</div><article v-for="side in statRows" :key="side.side" class="stats-edit-side"><header><b>{{ side.name }}</b><small>{{ side.rows.length }} / {{ teamSize }} players</small></header><div v-for="(player,index) in side.rows" :key="`${side.side}-${index}`" class="stats-edit-player"><div class="stats-edit-player-top"><select v-model.number="player.player_id" required><option v-for="user in side.roster" :key="user.id" :value="user.id">{{ user.name }}</option></select><button v-if="side.rows.length>1" type="button" class="text-button stats-remove-player" aria-label="Remove player" @click="removePlayer(side,index)"><Trash2 :size="13"/></button></div><label>Pick<input v-model="player[pickField]" :placeholder="pickLabel" maxlength="80"/></label><label v-for="([field,label]) in metricFields" :key="field">{{ label }}<input v-model.number="player[field]" type="number" min="0" max="99999" :step="field==='adr'||field==='acs'?0.1:1" required/></label><label v-if="game==='valorant'" class="stats-item-build">Weapons used<input :value="weaponLoadout(player)" @input="updateWeapons(player, $event.target.value)" maxlength="300" placeholder="Comma-separated, up to 5 weapons"/></label><label v-if="game==='mlbb'" class="stats-item-build">Item build<input :value="itemBuild(player)" @input="updateItemBuild(player, $event.target.value)" maxlength="500" placeholder="Comma-separated item names"/></label></div><button v-if="side.rows.length < teamSize && availableRoster(side).length" class="text-button stats-add-player" type="button" @click="addPlayer(side)"><Plus :size="13"/> Add player line</button><div v-for="([field,label]) in teamMetricFields" :key="field" class="stats-team-metric"><label>{{ label }}<input v-model.number="draft.entrants[String(side.entry.id)][field]" type="number" min="0" max="99999" required/></label></div></article><footer><button type="button" class="text-button" @click="editing=false">Cancel</button><button class="primary-button" :disabled="saving"><Save :size="14"/> {{ saving ? 'Saving…' : 'Save stats' }}</button></footer></form>
      </section>
      <section v-if="server" class="match-detail-server"><b>Match room / server</b><div><span>Status</span><strong>{{ server.status }}</strong></div><div v-if="server.region"><span>Region</span><strong>{{ server.region }}</strong></div><div v-if="server.map"><span>Map / room</span><strong>{{ server.map }}</strong></div><code v-if="server.host">{{ server.host }}{{ server.port ? `:${server.port}` : '' }}</code><small v-if="server.provider?.startsWith('demo')">Demo room details only; this does not connect to the game.</small><button v-if="server.demo_url" class="text-button" @click="emit('download-demo')">Download match demo ({{ Math.ceil((server.demo_size||0)/1048576) }} MB)</button><button v-if="canStopServer && server.status==='ready'" class="secondary-button" @click="emit('stop-server')">Stop server and save demo</button></section><section v-if="game==='cs2' && server?.stream_url" class="match-live-stream"><b>Match live video</b><iframe :src="server.stream_url" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen title="CS2 live match stream"></iframe><small>Video appears after an observer starts streaming from OBS.</small></section>
    </section>
  </div>
</template>

