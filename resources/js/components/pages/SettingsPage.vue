<script setup>
import { ref } from 'vue';
import { api } from '../../api';
import PasswordField from '../auth/PasswordField.vue';
defineProps({ theme: { type: String, default: 'dark' } });
const emit = defineEmits(['set-theme']);
const currentPassword = ref('');
const password = ref('');
const confirmation = ref('');
const passwordBusy = ref(false);
const passwordError = ref('');
const passwordSuccess = ref('');
async function updatePassword() {
  passwordBusy.value = true; passwordError.value = ''; passwordSuccess.value = '';
  try {
    const response = await api('/auth/password', { method: 'PATCH', body: { current_password: currentPassword.value, password: password.value, password_confirmation: confirmation.value } });
    passwordSuccess.value = response.message; currentPassword.value = ''; password.value = ''; confirmation.value = '';
  } catch (error) { passwordError.value = error.message; }
  finally { passwordBusy.value = false; }
}
const modes = [
  { id: 'dark', title: 'Dark arena', description: 'Deep navy surfaces with electric lime accents.' },
  { id: 'light', title: 'Light arena', description: 'Bright panels with the same competitive color accents.' },
];
</script>

<template>
  <section class="page-heading">
    <div>
      <div class="eyebrow"><span class="eyebrow-line"/> YOUR SETUP</div>
      <h1>Settings</h1>
      <p>Make the tournament hub feel right for your next match day.</p>
    </div>
  </section>
  <section class="settings-layout">
    <article class="panel settings-card">
      <div class="settings-card-heading"><span class="settings-mark">✦</span><div><h2>Appearance</h2><p>Choose the arena lighting for this browser.</p></div></div>
      <div class="theme-choice-grid" role="group" aria-label="Color theme">
        <button v-for="mode in modes" :key="mode.id" class="theme-choice" :class="{ selected: theme === mode.id }" :aria-pressed="theme === mode.id" @click="emit('set-theme', mode.id)">
          <span class="theme-preview" :class="`preview-${mode.id}`"><i/><i/><i/></span>
          <span class="theme-choice-copy"><b>{{ mode.title }}</b><small>{{ mode.description }}</small></span>
          <span class="theme-choice-check">{{ theme === mode.id ? 'ACTIVE' : 'SELECT' }}</span>
        </button>
      </div>
      <small class="settings-note">Your choice is saved on this device and applies across tournament, team, match, and profile pages.</small>
    </article>
    <article class="panel settings-card">
      <div class="settings-card-heading"><span class="settings-mark">⌑</span><div><h2>Change password</h2><p>Use at least 8 characters with a letter, number, and special character. Your display name cannot be part of it.</p></div></div>
      <form class="settings-password-form" @submit.prevent="updatePassword">
        <PasswordField v-model="currentPassword" label="Current password" autocomplete="current-password" required/>
        <PasswordField v-model="password" label="New password" autocomplete="new-password" minlength="8" required/>
        <PasswordField v-model="confirmation" label="Confirm new password" autocomplete="new-password" minlength="8" required/>
        <div v-if="passwordError" class="form-error">{{ passwordError }}</div><div v-if="passwordSuccess" class="settings-success">{{ passwordSuccess }}</div>
        <button class="primary-button" :disabled="passwordBusy">{{ passwordBusy ? 'Updating…' : 'Update password' }}</button>
      </form>
    </article>
  </section>
</template>
