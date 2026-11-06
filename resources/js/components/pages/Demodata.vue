<script setup>
import { computed, onMounted, ref } from 'vue';
import { ArrowLeft, CheckCircle2, ChevronRight, Database, Gamepad2, Radio, RefreshCw, Shield, Swords, Trophy, Users } from '@lucide/vue';
import { api } from '../../api';
import { navigate } from '../../router';

const busy = ref(false);
const error = ref('');
const generated = ref(null);
const canGenerate = ref(false);
const catalogLoading = ref(true);
const demoTournaments = ref([]);
const resultDemoSide = ref('a');
const resultDemoState = ref('scheduled');
const demoScoreA = ref(1);
const demoScoreB = ref(0);
const resultDemoMessage = ref('');
const demoResolution = ref('Reviewed the submitted score and accepted the match evidence.');
const showDemoDisputeForm = ref(false);
const demoDisputeReason = ref('The submitted score does not match our recorded game result.');
const demoEvidenceFile = ref(null);
const demoEvidencePreview = ref('');
const demoDisputeOutcome = ref('valid');
const demoResolvedOutcome = ref('');
const catalog = computed(() => demoTournaments.value.filter(tournament => tournament.name?.startsWith('Demo ·')));
const valorantAnalyticsTournament = computed(() => generated.value?.valorant_analytics_demo || demoTournaments.value.find(tournament => tournament.name === 'Demo · VALORANT Analytics Showcase') || null);

async function loadDemoTournaments() {
  catalogLoading.value = true;
  try {
    const result = await api('/tournaments');
    const rows = Array.isArray(result.data) ? result.data : (result.data?.data || []);
    demoTournaments.value = rows.filter(tournament => tournament.name?.startsWith('Demo ·'));
  } catch {
    demoTournaments.value = [];
  } finally {
    catalogLoading.value = false;
  }
}

onMounted(async () => {
  try {
    const response = await api('/auth/me');
    canGenerate.value = response.user?.account_type === 'organizer';
  } catch { canGenerate.value = false; }
  await loadDemoTournaments();
});

async function generate() {
  busy.value = true;
  error.value = '';
  try {
    generated.value = (await api('/demo-data/generate', { method: 'POST', body: {} })).data;
    await loadDemoTournaments();
  } catch (reason) {
    error.value = reason.message;
  } finally {
    busy.value = false;
  }
}

function submitDemoScore() {
  resultDemoMessage.value = '';
  if (Number(demoScoreA.value) === Number(demoScoreB.value) || ![Number(demoScoreA.value), Number(demoScoreB.value)].includes(1)) {
    resultDemoMessage.value = 'For this BO1 example, enter a winning score of 1–0 or 0–1.';
    return;
  }
  resultDemoState.value = 'pending';
  resultDemoSide.value = 'b';
}

function confirmDemoScore() {
  resultDemoState.value = 'confirmed';
  resultDemoMessage.value = 'Demo only: the result is now shown as confirmed.';
}

function disputeDemoScore() {
  resultDemoMessage.value = '';
  showDemoDisputeForm.value = true;
}

function selectDemoEvidence(event) {
  const file = event.target.files?.[0] || null;
  if (demoEvidencePreview.value) URL.revokeObjectURL(demoEvidencePreview.value);
  demoEvidenceFile.value = file;
  demoEvidencePreview.value = file ? URL.createObjectURL(file) : '';
}

function submitDemoDispute() {
  resultDemoMessage.value = '';
  if (demoDisputeReason.value.trim().length < 8 || !demoEvidenceFile.value) {
    resultDemoMessage.value = 'Enter a reason of at least 8 characters and attach a screenshot.';
    return;
  }
  showDemoDisputeForm.value = false;
  resultDemoState.value = 'disputed';
  resultDemoSide.value = 'organizer';
}

function resolveDemoDispute() {
  resultDemoMessage.value = '';
  if (demoResolution.value.trim().length < 8) {
    resultDemoMessage.value = 'Add a resolution note of at least 8 characters.';
    return;
  }
  if (Number(demoScoreA.value) === Number(demoScoreB.value) || ![Number(demoScoreA.value), Number(demoScoreB.value)].includes(1)) {
    resultDemoMessage.value = 'For this BO1 example, enter a winning score of 1–0 or 0–1.';
    return;
  }
  resultDemoState.value = 'confirmed';
  demoResolvedOutcome.value = demoDisputeOutcome.value;
  resultDemoMessage.value = `Demo only: the organizer marked the dispute ${demoDisputeOutcome.value} and confirmed the result.`;
}

function resetResultDemo() {
  demoScoreA.value = 1;
  demoScoreB.value = 0;
  resultDemoState.value = 'scheduled';
  resultDemoSide.value = 'a';
  resultDemoMessage.value = '';
  showDemoDisputeForm.value = false;
  demoDisputeReason.value = 'The submitted score does not match our recorded game result.';
  demoEvidenceFile.value = null;
  demoDisputeOutcome.value = 'valid';
  demoResolvedOutcome.value = '';
  if (demoEvidencePreview.value) URL.revokeObjectURL(demoEvidencePreview.value);
  demoEvidencePreview.value = '';
  demoResolution.value = 'Reviewed the submitted score and accepted the match evidence.';
}

function openValorantAnalyticsDemo() {
  const tournamentId = generated.value?.valorant_analytics_demo?.id || valorantAnalyticsTournament.value?.id;
  if (!tournamentId) return;
  navigate('/?analytics_demo=' + tournamentId);
}
function openMlbbEmulatorDemo() {
  const demo = generated.value?.mlbb_emulator_demo;
  if (!demo) return;
  navigate(`/tournaments?demo_mlbb_tournament=${demo.tournament_id}`);
}

function openMatchOperationsDemo(game) {
  const demo = generated.value?.match_operations_demo?.[game];
  if (!demo) return;
  navigate(`/tournaments?demo_tournament=${demo.tournament_id}&demo_match=${demo.match_id}`);
}

function openDemoTournament(tournament) {
  navigate(`/tournaments?demo_tournament=${tournament.id}`);
}

function openOrganizerLiveMatches() {
  navigate('/?demo_live_matches=1');
}
</script>

<template>
  <main class="demo-data-page">
    <header class="create-page-top"><button class="back-link" @click="navigate('/tournaments')"><ArrowLeft :size="16"/> Back to tournaments</button><span class="create-save-state"><Shield :size="14"/> Local development demo</span></header>
    <section class="demo-hero panel"><div class="demo-orbit"><Database :size="30"/><span/><span/></div><div><div class="eyebrow"><span class="eyebrow-line"/> WORKSPACE PREVIEW</div><h1>{{ canGenerate ? 'Fill your arena.' : 'Explore the arena.' }}</h1><p>{{ canGenerate ? 'Generate a complete set of realistic sample tournaments, teams, players and match results in one click.' : 'Browse sample tournaments, inspect brackets and match details, and explore the player experience.' }}</p><button v-if="canGenerate" class="primary-button demo-generate" :disabled="busy" @click="generate"><RefreshCw v-if="busy" :size="16" class="demo-spin"/><Database v-else :size="16"/>{{ busy ? 'Building your demo…' : generated ? 'Refresh demo data' : 'Generate demo data' }}</button><span v-else class="demo-player-readonly"><Shield :size="14"/> Player view · Demo tournaments are read-only examples</span></div></section>

    <div v-if="error" class="form-error demo-error">{{ error }}</div>
    <section v-if="canGenerate && catalog.length" class="organizer-live-demo panel"><div><div class="eyebrow"><span class="eyebrow-line"/> ORGANIZER WALKTHROUGH</div><h2>Live matches</h2><p>Open the organizer’s match operations view with generated upcoming matches and reported results.</p></div><button class="primary-button" @click="openOrganizerLiveMatches">Open organizer Live Matches <ChevronRight :size="14"/></button><small>Shows scheduling, player readiness, pending results, and the organizer’s match list without switching accounts.</small></section>
    <div v-if="generated" class="demo-success"><CheckCircle2 :size="17"/> Demo data is ready. Open <b>Demo · CS2 Dynamic Seeding Lab</b> for ELO seeding or <b>Demo · CS2 Double-Elimination Showcase</b> to inspect completed winners/losers rounds and the upcoming final. <div v-if="generated.match_operations_demo?.cs2 || generated.match_operations_demo?.mlbb" class="demo-mlbb-actions"><b>Upcoming match readiness &amp; room access</b><button v-if="generated.match_operations_demo?.cs2" class="text-button" @click="openMatchOperationsDemo('cs2')">Open CS2 ready match · preview player connection details</button><button v-if="generated.match_operations_demo?.mlbb" class="text-button" @click="openMatchOperationsDemo('mlbb')">Open MLBB ready match · preview player room code</button><small>Each match starts with Side A marked ready and Side B waiting. In match details, use the player preview buttons to see the other side’s ready action and participant-only connection details.</small></div><div v-if="generated.mlbb_emulator_demo" class="demo-mlbb-actions"><button class="text-button" @click="openMlbbEmulatorDemo">Open MLBB emulator tournament · choose any match</button><small>Open any available match, then preview either side from that match’s details.</small></div></div>

    <section class="result-demo-gateway panel">
      <div class="result-demo-heading"><span class="demo-card-icon ml"><Swords :size="18"/></span><div><div class="eyebrow"><span class="eyebrow-line"/> SCORE REPORTING · QUICK SCREENSHOT DEMO</div><h2>Preview both participant views in one place</h2><p>Switch sides and simulate score reporting without changing a real match or changing accounts.</p></div><span class="result-demo-only">DEMO ONLY</span></div>
      <div class="result-demo-switch" role="tablist" aria-label="Participant view">
        <button :class="{active:resultDemoSide==='a'}" @click="resultDemoSide='a'">Side A · Report score</button>
        <button :class="{active:resultDemoSide==='b'}" @click="resultDemoSide='b'">Side B · Review result</button>
        <button :class="{active:resultDemoSide==='organizer'}" @click="resultDemoSide='organizer'">Organizer · Resolve</button>
      </div>
      <article class="result-demo-match">
        <header><div><small>T-0018 · MOBILE LEGENDS · ROUND 1 · MATCH 1</small><b>Mobile Legends Community Cup</b></div><span class="result-demo-status" :class="resultDemoState">{{ resultDemoState.replace('_',' ') }}</span></header>
        <div class="result-demo-scoreline"><span><small>SIDE A</small><b>Mythic Reign</b></span><strong>{{ resultDemoState==='scheduled'?'—':demoScoreA }} <i>:</i> {{ resultDemoState==='scheduled'?'—':demoScoreB }}</strong><span class="right"><small>SIDE B</small><b>Land of Dawn</b></span></div>
        <div v-if="resultDemoSide==='a' && resultDemoState==='scheduled'" class="result-demo-controls"><p>Enter the series score, then submit it for the other side to review.</p><label>Score A<input v-model.number="demoScoreA" type="number" min="0" max="1"/></label><label>Score B<input v-model.number="demoScoreB" type="number" min="0" max="1"/></label><button class="primary-button" @click="submitDemoScore">Submit score</button></div>
        <div v-else-if="resultDemoSide==='b' && resultDemoState==='pending' && !showDemoDisputeForm" class="result-demo-controls"><p><b>Mythic Reign</b> submitted this score. The opponent can confirm it or dispute it.</p><button class="primary-button" @click="confirmDemoScore">Confirm score</button><button class="secondary-button" @click="disputeDemoScore">Dispute</button></div>
        <form v-else-if="resultDemoSide==='b' && resultDemoState==='pending' && showDemoDisputeForm" class="result-demo-controls organizer-resolution-demo" @submit.prevent="submitDemoDispute"><p><b>Dispute this score</b><span>Explain why the reported result is incorrect and attach a screenshot as evidence.</span></p><label class="resolution-demo-field">Dispute reason<textarea v-model="demoDisputeReason" minlength="8" maxlength="2000" required rows="2"/></label><label class="resolution-demo-field">Screenshot evidence<input type="file" accept="image/png,image/jpeg,image/webp" required @change="selectDemoEvidence"/></label><img v-if="demoEvidencePreview" class="result-demo-evidence-preview" :src="demoEvidencePreview" alt="Selected dispute evidence preview"/><button class="primary-button">Submit dispute</button><button type="button" class="text-button" @click="showDemoDisputeForm=false">Cancel</button></form>
        <div v-else-if="resultDemoSide==='organizer' && resultDemoState==='disputed'" class="result-demo-controls organizer-resolution-demo"><p><b>Dispute reason from Land of Dawn</b><span>“{{ demoDisputeReason }}”</span></p><div v-if="demoEvidencePreview" class="result-demo-evidence"><small>SCREENSHOT EVIDENCE · {{ demoEvidenceFile?.name }}</small><img class="result-demo-evidence-preview" :src="demoEvidencePreview" alt="Screenshot supplied with the match dispute"/></div><label>Final score · A<input v-model.number="demoScoreA" type="number" min="0" max="1"/></label><label>Final score · B<input v-model.number="demoScoreB" type="number" min="0" max="1"/></label><label>Dispute outcome<select v-model="demoDisputeOutcome"><option value="valid">Valid · uphold dispute</option><option value="invalid">Invalid · reject dispute</option></select></label><label class="resolution-demo-field">Resolution note<textarea v-model="demoResolution" minlength="8" maxlength="2000" rows="2"/></label><button class="primary-button" @click="resolveDemoDispute">Resolve dispute</button></div>
        <div v-else-if="resultDemoSide==='organizer'" class="result-demo-controls result-demo-feedback"><p v-if="resultDemoState==='confirmed'">{{ demoResolvedOutcome ? `Dispute marked ${demoResolvedOutcome}.` : 'The score was confirmed without a dispute.' }} Confirmed score: {{ demoScoreA }}–{{ demoScoreB }}.</p><template v-else><p>No dispute is open for the organizer to resolve yet.</p><button class="secondary-button" @click="resultDemoState='disputed';resultDemoSide='organizer';resultDemoMessage=''">Load disputed example</button></template></div>
        <div v-else class="result-demo-controls result-demo-feedback"><p v-if="resultDemoState==='scheduled'">No score has been submitted yet. Switch to Side A to report the demo result.</p><p v-else-if="resultDemoState==='pending'">Result pending confirmation by Side B.</p><p v-else-if="resultDemoState==='confirmed'">Result confirmed. The match is complete.</p><p v-else>Result disputed. The organizer can review it.</p><button v-if="resultDemoState!=='scheduled'" class="text-button" @click="resetResultDemo">Reset demo</button></div>
        <small v-if="resultDemoMessage" class="result-demo-message">{{ resultDemoMessage }}</small>
      </article>
      <small class="result-demo-note">This is a local visual preview for screenshots. Its score and buttons do not call the backend or save match data.</small>
    </section>

    <div v-if="valorantAnalyticsTournament" class="demo-success"><CheckCircle2 :size="17"/><b>VALORANT analytics showcase ready</b><span>8 teams · 28 completed round-robin matches · varied agents, maps, and weapon loadouts.</span><button class="text-button" @click="openValorantAnalyticsDemo">Open analytics dashboard</button><small>The dashboard opens on Analytics. Use the Valorant filters to compare player ELO, K/D/A, wins, agents by map, and team scouting.</small></div>
    <section v-if="!catalogLoading && catalog.length" class="demo-catalog panel"><div class="demo-catalog-heading"><div><div class="eyebrow"><span class="eyebrow-line"/> OPEN TO ALL PLAYERS</div><h2>Demo tournament directory</h2><p>Open an event to view its bracket, matches, teams, and available player-facing details.</p></div><span>{{ catalog.length }} events</span></div><div class="demo-catalog-list"><article v-for="tournament in catalog" :key="tournament.id" class="demo-catalog-item"><div><b>{{ tournament.name }}</b><small>{{ ({cs2:'Counter-Strike 2',valorant:'VALORANT',sf6:'Street Fighter 6',mlbb:'Mobile Legends'})[tournament.game] || tournament.game }} · {{ tournament.format?.replaceAll('_',' ') }} · {{ tournament.team_size===1?'1v1':'5v5' }} · {{ tournament.status?.replaceAll('_',' ') }}</small></div><button class="secondary-button" @click="openDemoTournament(tournament)">View event <ChevronRight :size="14"/></button></article></div></section>
    <section v-else-if="catalogLoading" class="demo-catalog panel"><span>Loading available demo tournaments…</span></section>
    <section v-else class="demo-catalog panel"><div class="demo-catalog-heading"><div><div class="eyebrow"><span class="eyebrow-line"/> OPEN TO ALL PLAYERS</div><h2>No generated events yet</h2><p>An organizer can generate the demo tournament set, then you can browse it here.</p></div></div></section>

    <section class="demo-grid">
      <article class="demo-card"><span class="demo-card-icon cs"><Radio :size="18"/></span><div><small>CS2 · LIVE + HISTORY</small><h2>Counter-Strike 2 Open</h2><p>Eight seeded 5v5 teams, projected playoff rounds, per-player match stats, historical ELO, and a ready-state demo match with sample participant connection details. Also includes a double-elimination showcase.</p></div></article>
      <article class="demo-card"><span class="demo-card-icon val"><Users :size="18"/></span><div><small>VALORANT · LIVE + HISTORY</small><h2>Community Cup</h2><p>An 8-team completed analytics showcase with 28 round-robin matches, varied results, agents, maps, weapons, and team scouting data.</p></div></article>
      <article class="demo-card"><span class="demo-card-icon sf"><Swords :size="18"/></span><div><small>STREET FIGHTER 6 · LIVE + HISTORY</small><h2>Round-robin standings</h2><p>Eight players, 28 current-event fixtures, confirmed results, and character-by-character set wins, perfect rounds, drive impacts, and individual ELO history.</p></div></article>
      <article class="demo-card"><span class="demo-card-icon ml"><Gamepad2 :size="18"/></span><div><small>MLBB · LIVE + HISTORY · EMULATOR STATION</small><h2>Scrim Night</h2><p>Eight sample teams, varied hero picks, one side already marked ready, and a sample room code visible to match participants. Organizers can open a player preview for either side.</p></div></article>
    </section>

    <section class="demo-foot panel"><div class="demo-foot-icon"><Trophy :size="18"/></div><div><b>What gets generated</b><p v-if="generated">{{ generated.tournaments }} tournaments · {{ generated.teams }} teams · {{ generated.players }} demo players · {{ generated.matches }} matches · {{ generated.historical_tournaments }} ELO history events · {{ generated.mlbb_emulator_demo ? `${generated.mlbb_emulator_demo.match_count} MLBB emulator-tournament matches` : 'no MLBB station tournament found' }}</p><p v-else>Eleven tournaments · 24 teams · 160 demo players · 160+ matches, including multi-pick player stats, an MLBB emulator tournament, a CS2 ELO seeding lab, and a double-elimination showcase</p><small>Generate or refresh this data, then open Analytics and choose a player to compare each game-specific pick. Demo player accounts use generated @example.test addresses.</small></div><button class="secondary-button" @click="navigate('/')">Open workspace <ArrowLeft :size="15" class="demo-open-arrow"/></button></section>
  </main>
</template>
