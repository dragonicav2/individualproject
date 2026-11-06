<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { ArrowLeft, CalendarDays, Check, ChevronRight, CircleHelp, Clock3, Gamepad2, Shield, Swords, Trophy, Users } from '@lucide/vue';
import { api } from '../../api';
import { navigate } from '../../router';

const busy = ref(false);
const checkingAccess = ref(true);
const canCreate = ref(false);
const error = ref('');
const created = ref(null);
const form = reactive({ name: '', game: 'cs2', team_size: 5, format: 'single_elimination', best_of: 3, capacity: 16, starts_at: '', allow_organizer_entry: false });
const gameOptions = [
  { id: 'cs2', title: 'Counter-Strike 2', short: 'CS2', color: '#c99750', description: '5v5 tactical FPS' },
  { id: 'valorant', title: 'VALORANT', short: 'VAL', color: '#e45c6c', description: '5v5 tactical shooter' },
  { id: 'sf6', title: 'Street Fighter 6', short: 'SF6', color: '#48a695', description: '1v1 fighting game' },
  { id: 'mlbb', title: 'Mobile Legends', short: 'MLBB', color: '#7b69d7', description: '5v5 mobile MOBA' },
];
const selectedGame = computed(() => gameOptions.find(game => game.id === form.game));
const startsAtLocalMin = computed(() => { const date = new Date(Date.now() + 60_000); return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0,16); });

onMounted(async () => {
  try {
    const response = await api('/auth/me');
    canCreate.value = response.user?.account_type === 'organizer' && !response.user?.is_platform_admin;
  } catch {
    canCreate.value = false;
  } finally {
    checkingAccess.value = false;
  }
});

async function submit() {
  error.value = '';
  if (!form.name.trim()) { error.value = 'Enter a tournament name.'; return; }
  if (Number(form.capacity) < 2 || Number(form.capacity) > 512) { error.value = 'Entrant capacity must be between 2 and 512.'; return; }
  if (form.starts_at && new Date(form.starts_at) <= new Date()) { error.value = 'Choose a start time in the future.'; return; }
  busy.value = true;
  try {
    const payload = { ...form, name: form.name.trim(), team_size: Number(form.team_size), best_of: Number(form.best_of), capacity: Number(form.capacity), starts_at: form.starts_at ? new Date(form.starts_at).toISOString() : null };
    const response = await api('/tournaments', { method: 'POST', body: payload });
    created.value = response.data;
  } catch (reason) { error.value = reason.message; }
  finally { busy.value = false; }
}
</script>

<template>
  <main class="create-page">
    <section v-if="checkingAccess" class="panel create-access-state"><h1>Checking account access…</h1></section>
    <section v-else-if="!canCreate" class="panel create-access-state"><span class="eyebrow">ORGANIZER ACCESS REQUIRED</span><h1>Tournament creation is for organizers</h1><p>Your current account can browse tournaments, but only an organizer account can create one.</p><button class="primary-button" @click="navigate('/tournaments')">Back to tournaments</button></section>
    <template v-else>
    <header class="create-page-top"><button class="back-link" @click="navigate('/tournaments')"><ArrowLeft :size="16"/> Back to tournaments</button><span class="create-save-state"><Shield :size="14"/> Private draft until registration opens</span></header>
    <div v-if="created" class="create-success panel"><div class="success-mark"><Check :size="24"/></div><span class="eyebrow">DRAFT CREATED</span><h1>{{ created.name }}</h1><p>Your tournament is saved. Open registration when you’re ready to invite players.</p><button class="primary-button" @click="navigate('/tournaments')">Go to tournaments <ChevronRight :size="16"/></button></div>
    <template v-else>
      <section class="create-intro"><div><div class="eyebrow"><span class="eyebrow-line"/> TOURNAMENT SETUP · STEP 1 OF 1</div><h1>Create your tournament</h1><p>Set up the essentials now. You can open registration after reviewing your draft.</p></div><div class="setup-badge"><Trophy :size="16"/> Organizer setup</div></section>
      <div class="create-layout">
        <form class="create-form panel" @submit.prevent="submit">
          <section class="create-section"><div class="create-section-heading"><span>01</span><div><h2>Event details</h2><p>Give your competition a name players will recognize.</p></div></div><label class="create-label">Tournament name<input v-model="form.name" maxlength="120" required placeholder="e.g. Northstar Open 2026"/><small>{{ form.name.length }} / 120 characters</small></label></section>
          <section class="create-section"><div class="create-section-heading"><span>02</span><div><h2>Choose a game</h2><p>Game determines which roster types can enter.</p></div></div><div class="game-picker"><button v-for="game in gameOptions" :key="game.id" type="button" class="game-choice" :class="{ selected: form.game === game.id }" :style="{ '--game-color': game.color }" @click="form.game=game.id"><span class="game-choice-mark">{{ game.short }}</span><span><b>{{ game.title }}</b><small>{{ game.description }}</small></span><Check v-if="form.game===game.id" :size="17" class="choice-check"/></button></div></section>
          <section class="create-section"><div class="create-section-heading"><span>03</span><div><h2>Competition format</h2><p>Choose how entrants progress through the event.</p></div></div><div class="format-picker"><button type="button" class="format-choice" :class="{ selected: form.format==='single_elimination' }" @click="form.format='single_elimination'"><span class="format-icon"><Swords :size="18"/></span><span><b>Single elimination</b><small>One loss and the entrant is out. Fast, clear playoffs.</small></span><i>{{ form.format==='single_elimination'?'Selected':'Select' }}</i></button><button type="button" class="format-choice" :class="{ selected: form.format==='double_elimination' }" @click="form.format='double_elimination'"><span class="format-icon"><Trophy :size="18"/></span><span><b>Double elimination</b><small>Two losses to eliminate. Winners and losers brackets, then a grand final.</small></span><i>{{ form.format==='double_elimination'?'Selected':'Select' }}</i></button><button type="button" class="format-choice" :class="{ selected: form.format==='round_robin' }" @click="form.format='round_robin'"><span class="format-icon"><Users :size="18"/></span><span><b>Round robin</b><small>Every entrant plays each other. Standings use wins and round difference.</small></span><i>{{ form.format==='round_robin'?'Selected':'Select' }}</i></button></div></section>
          <section class="create-section"><div class="create-section-heading"><span>04</span><div><h2>Entrants and match rules</h2><p>Set roster size, event capacity, and series length.</p></div></div><div class="create-fields"><label class="create-label">Entrant type<select v-model.number="form.team_size"><option :value="1">1v1 · individual players</option><option :value="5">5v5 · teams</option></select></label><label class="create-label">Best of<select v-model.number="form.best_of"><option v-for="n in [1,3,5,7,9,11]" :key="n" :value="n">Best of {{ n }}</option></select></label><label class="create-label">Entrant limit<input v-model.number="form.capacity" type="number" min="2" max="512" required/><small>Maximum players or teams</small></label></div><label class="organizer-entry-option"><input v-model="form.allow_organizer_entry" type="checkbox"/><span><b>Allow me to compete in this tournament</b><small>The organizer can register as a player or enter one of their eligible teams.</small></span></label></section>
          <section class="create-section schedule-section"><div class="create-section-heading"><span>05</span><div><h2>Schedule</h2><p>You can leave the start time unset and decide later.</p></div></div><label class="create-label">Start date and time<input v-model="form.starts_at" type="datetime-local" :min="startsAtLocalMin"/><small>Shown in your local time zone.</small></label></section>
          <div v-if="error" class="form-error">{{ error }}</div><footer class="create-form-footer"><span><CircleHelp :size="15"/> You can change the event by managing its draft.</span><div><button type="button" class="secondary-button" @click="navigate('/tournaments')">Cancel</button><button class="primary-button" :disabled="busy">{{ busy?'Saving draft…':'Create tournament draft' }} <ChevronRight :size="16"/></button></div></footer>
        </form>
        <aside class="create-summary"><article class="summary-card"><div class="summary-top"><span>EVENT PREVIEW</span><span class="draft-pill">DRAFT</span></div><div class="summary-game" :style="{ '--game-color': selectedGame.color }"><span>{{ selectedGame.short }}</span></div><h2>{{ form.name || 'Your tournament name' }}</h2><p>{{ selectedGame.title }} · {{ form.team_size===1?'1v1 players':'5v5 teams' }}</p><div class="summary-divider"/><div class="summary-row"><span><Trophy :size="15"/> Format</span><b>{{ form.format==='single_elimination'?'Single elimination':form.format==='double_elimination'?'Double elimination':'Round robin' }}</b></div><div class="summary-row"><span><Gamepad2 :size="15"/> Series</span><b>Best of {{ form.best_of }}</b></div><div class="summary-row"><span><Users :size="15"/> Capacity</span><b>{{ form.capacity }} entrants</b></div><div class="summary-row"><span><CalendarDays :size="15"/> Starts</span><b>{{ form.starts_at?new Date(form.starts_at).toLocaleString():'To be scheduled' }}</b></div><div class="summary-row"><span><Shield :size="15"/> Organizer plays</span><b>{{ form.allow_organizer_entry?'Allowed':'Disabled' }}</b></div></article><div class="summary-tip"><Clock3 :size="16"/><p><b>Next step</b><br/>Once saved, open registration from the tournament page. Entrants register and check in before you publish the bracket.</p></div></aside>
      </div>
    </template>
    </template>
  </main>
</template>
