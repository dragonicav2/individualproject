<script setup>
import { ref } from 'vue';
import { KeyRound, Swords } from '@lucide/vue';
import PasswordField from './PasswordField.vue';
import { api, setToken } from '../../api';
import { navigate } from '../../router';

const currentPassword = ref('');
const password = ref('');
const confirmation = ref('');
const busy = ref(false);
const error = ref('');

async function submit() {
  busy.value = true; error.value = '';
  try {
    await api('/auth/password', { method: 'PATCH', body: { current_password: currentPassword.value, password: password.value, password_confirmation: confirmation.value } });
    navigate('/', true);
  } catch (reason) { error.value = reason.message; }
  finally { busy.value = false; }
}
async function signOut() {
  try { await api('/auth/logout', { method: 'POST' }); } catch {}
  setToken(null); navigate('/admin/login', true);
}
</script>

<template>
  <main class="auth-page-shell admin-auth-shell"><section class="auth-page-card"><a class="auth-brand" href="/" @click.prevent="navigate('/',true)"><span class="brand-mark"><Swords :size="19"/></span> bracket<span>.</span></a><div class="auth-page-heading"><div class="eyebrow"><span class="eyebrow-line"/> FIRST SIGN IN</div><h1><KeyRound :size="24"/> Set your password</h1><p>Your temporary administrator password is only for initial access. Choose a personal password to continue to the monitoring dashboard.</p></div><form @submit.prevent="submit"><PasswordField v-model="currentPassword" label="Temporary password" autocomplete="current-password" required/><PasswordField v-model="password" label="New password" autocomplete="new-password" minlength="8" required/><small class="password-policy">At least 8 characters with a letter, number, and special character. It cannot contain your display name.</small><PasswordField v-model="confirmation" label="Confirm new password" autocomplete="new-password" minlength="8" required/><div v-if="error" class="form-error">{{ error }}</div><div class="modal-actions"><button type="button" class="secondary-button" @click="signOut">Sign out</button><button class="primary-button" :disabled="busy">{{ busy ? 'Saving…' : 'Save password and continue' }}</button></div></form></section><footer>© 2026 Bracket · Secure administrator access</footer></main>
</template>
