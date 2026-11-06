<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ImagePlus, RefreshCw, ShieldCheck, Trash2, Upload } from '@lucide/vue';
import { api, upload } from '../../api';
import ProtectedGameImage from './ProtectedGameImage.vue';

const games = [
  { code: 'cs2', name: 'Counter-Strike 2' },
  { code: 'valorant', name: 'VALORANT' },
  { code: 'sf6', name: 'Street Fighter 6' },
  { code: 'mlbb', name: 'Mobile Legends' },
];
const categories = {
  cs2: [{ id: 'game', name: 'Game artwork' }, { id: 'map', name: 'Maps' }, { id: 'weapon', name: 'Weapons' }],
  valorant: [{ id: 'game', name: 'Game artwork' }, { id: 'map', name: 'Maps' }, { id: 'agent', name: 'Agents' }, { id: 'weapon', name: 'Weapons' }],
  sf6: [{ id: 'game', name: 'Game artwork' }, { id: 'stage', name: 'Stages' }, { id: 'character', name: 'Fighters' }],
  mlbb: [{ id: 'game', name: 'Game artwork' }, { id: 'map', name: 'Maps' }, { id: 'hero', name: 'Heroes' }, { id: 'item', name: 'Items' }],
};
const rows = ref([]), analytics = ref(null), loading = ref(false), saving = ref(false), error = ref(''), message = ref('');
const filterGame = ref('all');
const previewAudience = ref('organizer');
const selectedPreview = ref(null);
const localPreviewUrl = ref('');
const form = ref({ game: 'valorant', category: 'agent', display_name: '', image: null });
const fileInput = ref(null);
const formCategories = computed(() => categories[form.value.game] || []);
const visibleRows = computed(() => filterGame.value === 'all' ? rows.value : rows.value.filter(row => row.game === filterGame.value));
const previewAsset = computed(() => form.value.image ? { game: form.value.game, category: form.value.category, display_name: form.value.display_name || form.value.image.name, image_url: '' } : selectedPreview.value);
const previewStats = computed(() => {
  const game = analytics.value?.games?.find(item => item.code === previewAsset.value?.game);
  if (!game || !previewAsset.value) return null;
  const name = previewAsset.value.display_name;
  if (['map', 'stage'].includes(previewAsset.value.category)) return game.map_agent_usage?.find(item => item.name.toLowerCase() === name.toLowerCase()) || null;
  if (previewAsset.value.category === 'weapon') return game.weapons?.find(item => item.name.toLowerCase() === name.toLowerCase()) || null;
  if (['agent', 'character', 'hero', 'item'].includes(previewAsset.value.category)) return game.distribution?.find(item => item.name.toLowerCase() === name.toLowerCase()) || null;
  return null;
});
const previewCount = computed(() => previewStats.value?.matches ?? previewStats.value?.count ?? 0);
const previewTitle = computed(() => previewAsset.value ? `${gameName(previewAsset.value.game)} · ${categoryName(previewAsset.value.category)}` : 'Choose an image to preview');
function slug(name) { return String(name || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
const suggestions = computed(() => {
  if (!analytics.value) return [];
  const candidates = [];
  for (const game of analytics.value.games || []) {
    const specs = [{ category: 'game', name: game.code }];
    const pickCategory = { cs2: 'map', valorant: 'agent', sf6: 'character', mlbb: 'hero' }[game.code];
    for (const pick of game.distribution || []) specs.push({ category: pickCategory, name: pick.name });
    const mapCategory = game.code === 'sf6' ? 'stage' : 'map';
    for (const map of game.map_agent_usage || []) specs.push({ category: mapCategory, name: map.name });
    for (const weapon of game.weapons || []) specs.push({ category: 'weapon', name: weapon.name });
    for (const spec of specs) {
      if (!categories[game.code]?.some(category => category.id === spec.category)) continue;
      if (rows.value.some(row => row.game === game.code && row.category === spec.category && row.slug === slug(spec.name))) continue;
      if (!candidates.some(row => row.game === game.code && row.category === spec.category && slug(row.name) === slug(spec.name))) candidates.push({ game: game.code, ...spec });
    }
  }
  return candidates;
});
function prepareSuggestion(item) { form.value.game = item.game; form.value.category = item.category; form.value.display_name = item.name; fileInput.value?.click(); }
function selectPreview(row) { selectedPreview.value = row; }

function gameName(code) { return games.find(game => game.code === code)?.name || code; }
function categoryName(code) { return Object.fromEntries(Object.values(categories).flatMap(list => list.map(row => [row.id, row.name])))[code] || code; }
function updateGame(code) {
  form.value.game = code;
  if (!formCategories.value.some(category => category.id === form.value.category)) form.value.category = formCategories.value[0]?.id || 'game';
}
async function load() {
  loading.value = true; error.value = '';
  try { const [assetResponse, analyticsResponse] = await Promise.all([api('/admin/game-assets'), api('/analytics/overview')]); rows.value = assetResponse.data || []; analytics.value = analyticsResponse.data; if (!selectedPreview.value || !rows.value.some(row => row.id === selectedPreview.value.id)) selectedPreview.value = rows.value[0] || null; }
  catch (reason) { error.value = reason.message; }
  finally { loading.value = false; }
}
function chooseFile(event) { if (localPreviewUrl.value) URL.revokeObjectURL(localPreviewUrl.value); form.value.image = event.target.files?.[0] || null; localPreviewUrl.value = form.value.image ? URL.createObjectURL(form.value.image) : ''; }
async function save() {
  error.value = ''; message.value = '';
  if (!form.value.image) { error.value = 'Choose an image file first.'; return; }
  saving.value = true;
  try {
    const data = new FormData();
    data.append('game', form.value.game); data.append('category', form.value.category);
    data.append('display_name', form.value.display_name.trim()); data.append('image', form.value.image);
    const saved = await upload('/admin/game-assets', data);
    selectedPreview.value = saved.data;
    message.value = 'Artwork saved encrypted. Matching analytics rows will use it automatically.';
    form.value.display_name = ''; form.value.image = null;
    if (localPreviewUrl.value) URL.revokeObjectURL(localPreviewUrl.value);
    localPreviewUrl.value = '';
    if (fileInput.value) fileInput.value.value = '';
    await load();
  } catch (reason) { error.value = reason.message; }
  finally { saving.value = false; }
}
async function remove(row) {
  if (!window.confirm(`Delete ${row.display_name} artwork for ${gameName(row.game)}?`)) return;
  error.value = ''; message.value = '';
  try { await api(`/admin/game-assets/${row.id}`, { method: 'DELETE' }); rows.value = rows.value.filter(asset => asset.id !== row.id); if (selectedPreview.value?.id === row.id) selectedPreview.value = rows.value[0] || null; }
  catch (reason) { error.value = reason.message; }
}
onMounted(load);
onBeforeUnmount(() => { if (localPreviewUrl.value) URL.revokeObjectURL(localPreviewUrl.value); });
</script>

<template>
  <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"/> PLATFORM ADMIN</div><h1>Game artwork</h1><p>Manage private artwork used for game, map, agent, fighter, hero, weapon, and item statistics.</p></div><button class="secondary-button" :disabled="loading" @click="load"><RefreshCw :size="14" :class="{ 'demo-spin': loading }"/> Refresh library</button></section>
  <section class="asset-security-note panel"><ShieldCheck :size="20"/><div><b>Encrypted storage · signed-in access</b><p>Image bytes are encrypted on disk. The app decrypts them only through authenticated image requests. Use the exact map or character name shown in match statistics. Anyone allowed to view an image in the site can still save or capture it.</p></div></section>
  <section class="asset-admin-grid">
    <form class="asset-upload-card panel" @submit.prevent="save">
      <header><ImagePlus :size="19"/><div><h2>Add or replace artwork</h2><p>Using the same game, category, and name replaces that image.</p></div></header>
      <label>Game<select :value="form.game" @change="updateGame($event.target.value)"><option v-for="game in games" :key="game.code" :value="game.code">{{ game.name }}</option></select></label>
      <label>Artwork type<select v-model="form.category"><option v-for="category in formCategories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
      <label>Exact name / image key<input v-model="form.display_name" required maxlength="120" placeholder="e.g. Ascent, Jett, Ryu, Miya, de_mirage"/><small>Must match the name entered in match stats. Game artwork uses the short key: cs2, valorant, sf6, or mlbb.</small></label>
      <label class="asset-file-picker">Image file<input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" required @change="chooseFile"/><small>JPG, PNG, or WebP · up to 5 MB · max 4096 × 4096</small></label>
      <div v-if="error" class="form-error">{{ error }}</div><div v-if="message" class="asset-success">{{ message }}</div>
      <button class="primary-button" :disabled="saving"><Upload :size="14"/>{{ saving ? 'Encrypting and saving…' : 'Save artwork' }}</button>
    </form>
    <section class="asset-library panel">
      <header><div><h2>Artwork library</h2><p>{{ rows.length }} encrypted image{{ rows.length === 1 ? '' : 's' }}</p></div><select v-model="filterGame" aria-label="Filter artwork by game"><option value="all">All games</option><option v-for="game in games" :key="game.code" :value="game.code">{{ game.name }}</option></select></header>
      <div v-if="suggestions.length" class="asset-suggestions"><b>Missing for recorded analytics · {{ suggestions.length }}</b><button v-for="item in suggestions" :key="item.game + item.category + item.name" type="button" @click="prepareSuggestion(item)"><span>{{ item.name }}</span><small>{{ gameName(item.game) }} · {{ categoryName(item.category) }}</small></button></div>
      <div v-if="loading && !rows.length" class="analytics-loading">Loading artwork…</div>
      <div v-else-if="!visibleRows.length" class="asset-empty"><ImagePlus :size="24"/><b>No artwork yet</b><span>Upload a game logo, map, or character portrait to start filling the analytics cards.</span></div>
      <div v-else class="asset-library-list"><article v-for="row in visibleRows" :key="row.id" class="asset-library-row" :class="{ 'asset-preview-selected': !form.image && selectedPreview?.id===row.id }"><button type="button" class="asset-library-row-select" @click="selectPreview(row)"><ProtectedGameImage :src="row.image_url" :alt="row.display_name" :fallback="row.display_name.slice(0,2).toUpperCase()"/><span><b>{{ row.display_name }}</b><small>{{ gameName(row.game) }} · {{ categoryName(row.category) }} · {{ (row.byte_size / 1024).toFixed(0) }} KB</small></span></button><button class="asset-delete" :aria-label="`Delete ${row.display_name}`" title="Delete artwork" @click="remove(row)"><Trash2 :size="14"/></button></article></div>
    </section>
  </section>
  <section class="asset-live-preview panel">
    <header class="asset-preview-header"><div><div class="eyebrow"><span class="eyebrow-line"/> LIVE PLACEMENT PREVIEW</div><h2>{{ previewAsset?.display_name || 'Select or upload artwork' }}</h2><p>{{ previewTitle }} · See how the art sits beside game analytics.</p></div><nav aria-label="Preview audience"><button :class="{active:previewAudience==='organizer'}" @click="previewAudience='organizer'">Organizer view</button><button :class="{active:previewAudience==='player'}" @click="previewAudience='player'">Player view</button></nav></header>
    <p class="asset-preview-audience-note">{{ previewAudience==='organizer' ? 'Organizers see the platform activity overview above game analytics.' : 'Players see game performance analytics without organizer platform controls.' }} Both views use the same game, map, and character artwork below.</p>
    <div v-if="previewAsset" class="asset-preview-canvas">
      <div v-if="previewAudience==='organizer'" class="asset-preview-platform-strip"><b>PLATFORM OVERVIEW</b><span>Players <strong>128</strong></span><span>Tournaments <strong>24</strong></span><span>Verified matches <strong>316</strong></span></div>
      <div class="asset-preview-game-head"><div class="asset-preview-image" :class="{ wide: previewAsset.category==='game', portrait: ['agent','character','hero'].includes(previewAsset.category), icon: ['weapon','item'].includes(previewAsset.category) }"><img v-if="localPreviewUrl && form.image" :src="localPreviewUrl" :alt="previewAsset.display_name"/><ProtectedGameImage v-else-if="previewAsset.image_url" :src="previewAsset.image_url" :alt="previewAsset.display_name" :fallback="previewAsset.display_name.slice(0,2).toUpperCase()"/><span v-else>{{ previewAsset.display_name.slice(0,2).toUpperCase() }}</span></div><div class="asset-preview-copy"><small>{{ previewAsset.game.toUpperCase() }} · VERIFIED PERFORMANCE</small><h3>{{ gameName(previewAsset.game) }}</h3><p>{{ previewAsset.category==='game' ? 'Game artwork / cover' : `${categoryName(previewAsset.category)} analytics` }} · {{ previewAsset.display_name }}</p></div><span class="asset-preview-match-count">{{ previewCount }} {{ ['map','stage'].includes(previewAsset.category) ? 'matches' : 'picks' }}</span></div>
      <div class="asset-preview-metrics"><span><small>Verified matches</small><b>{{ previewStats?.matches ?? '—' }}</b></span><span><small>{{ ['map','stage'].includes(previewAsset.category) ? 'Map usage' : 'Pick share' }}</small><b>{{ previewStats?.share ? `${previewStats.share}%` : 'Analytics linked by exact name' }}</b></span><span><small>Asset category</small><b>{{ categoryName(previewAsset.category) }}</b></span></div>
      <p v-if="!previewStats && previewAsset.category!=='game'" class="asset-preview-unlinked">This image will appear when match statistics use this exact name. Once matches reference it, the analytics panel will show its real pick and map counts.</p>
    </div>
    <div v-else class="asset-preview-empty">Choose an item from Artwork library or select an image file to preview it here.</div>
  </section>
</template>
