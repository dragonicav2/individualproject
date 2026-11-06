<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Save, UserRound, Camera, Trash2 } from '@lucide/vue';

const props = defineProps({ user: { type: Object, default: null }, busy: Boolean, error: { type: String, default: '' } });
const emit = defineEmits(['save', 'upload-avatar', 'delete-avatar']);
const gameOptions = [
  { code: 'cs2', label: 'Counter-Strike 2', placeholder: 'Steam name' },
  { code: 'valorant', label: 'VALORANT', placeholder: 'Riot ID' },
  { code: 'sf6', label: 'Street Fighter 6', placeholder: 'Fighter ID' },
  { code: 'mlbb', label: 'Mobile Legends', placeholder: 'Game ID' },
];
const form = reactive({ name: '', bio: '', game_interests: [], game_profiles: {} });
const localAvatar = ref('');
const fileInput = ref(null);
const initials = computed(() => (form.name || 'P').trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase());
const avatarPreview = computed(() => localAvatar.value || props.user?.avatar_url || '');

function clearLocalAvatar() {
  if (localAvatar.value.startsWith('blob:')) URL.revokeObjectURL(localAvatar.value);
  localAvatar.value = '';
}

function selectAvatar(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return;
  if (file.size > 2 * 1024 * 1024) return;
  clearLocalAvatar();
  localAvatar.value = URL.createObjectURL(file);
  emit('upload-avatar', file);
  event.target.value = '';
}

watch(() => props.user, (user) => {
  form.name = user?.name || '';
  form.bio = user?.bio || '';
  form.game_interests = [...(user?.game_interests || (user?.favorite_game ? [user.favorite_game] : []))];
  form.game_profiles = { ...(user?.game_profiles || {}) };
  clearLocalAvatar();
}, { immediate: true, deep: true });
</script>

<template>
  <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"/> PLAYER ACCOUNT</div><h1>My profile</h1><p>Set up the name, bio and game IDs other tournament participants can recognize.</p></div></section>
  <form class="profile-page panel" @submit.prevent="emit('save', { name: form.name.trim(), bio: form.bio, game_interests: form.game_interests, game_profiles: form.game_profiles })">
    <header class="profile-page-header">
      <div class="profile-avatar-wrap"><div class="profile-large-avatar"><img v-if="avatarPreview" :src="avatarPreview" alt="Profile picture preview"/><span v-else>{{ initials || 'P' }}</span></div><label class="avatar-upload-button" :class="{ disabled: busy }"><Camera :size="14"/> Change photo<input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" :disabled="busy" @change="selectAvatar"/></label><button v-if="user?.avatar_url" type="button" class="avatar-remove-button" :disabled="busy" @click="emit('delete-avatar')"><Trash2 :size="13"/> Remove</button><small class="avatar-help">JPG, PNG, or WebP · max 2 MB</small></div>
      <div class="profile-heading-copy"><h2>{{ form.name || 'Player profile' }}</h2><span>{{ user?.email }}</span><small>Make your player card easy to recognize in teams and tournaments.</small></div><UserRound :size="22" class="profile-header-icon"/>
    </header>
    <div v-if="error" class="form-error">{{ error }}</div>
    <div class="profile-form-grid"><label>Display name<input v-model="form.name" required maxlength="80"/></label></div>
    <fieldset class="profile-interest-picker"><legend>Games I’m interested in</legend><p>Select every game you want to follow. You can change this list anytime.</p><div class="registration-game-options"><label v-for="game in gameOptions" :key="game.code" :class="{ selected: form.game_interests.includes(game.code) }"><input v-model="form.game_interests" type="checkbox" :value="game.code"/><span>{{ game.label }}</span></label></div><small>Players can be interested in multiple games.</small></fieldset>
    <label class="profile-bio-field">Player bio<textarea v-model="form.bio" maxlength="500" rows="4" placeholder="Your play style, goals, or a short intro…"/><small>{{ form.bio.length }} / 500</small></label>
    <section class="profile-game-ids"><div><h3>Game profiles</h3><p>Add the IDs teammates need to find or invite you.</p></div><label v-for="game in gameOptions" :key="game.code">{{ game.label }}<input v-model="form.game_profiles[game.code]" maxlength="80" :placeholder="game.placeholder"/></label></section>
    <footer class="profile-save-row"><small>Email: {{ user?.email }}</small><button class="primary-button" :disabled="busy"><Save :size="15"/> {{ busy ? 'Saving…' : 'Save profile' }}</button></footer>
  </form>
</template>
