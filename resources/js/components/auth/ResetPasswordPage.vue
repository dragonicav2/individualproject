<script setup>
import { ref } from 'vue';
import { Swords } from '@lucide/vue';
import PasswordField from './PasswordField.vue';
import { api } from '../../api';
import { navigate } from '../../router';

const token = decodeURIComponent(window.location.pathname.split('/').filter(Boolean).at(-1) || '');
const email = ref(new URLSearchParams(window.location.search).get('email') || '');
const password = ref('');
const confirmation = ref('');
const busy = ref(false);
const error = ref('');
const success = ref('');

async function submit() {
  busy.value = true;
  error.value = '';
  success.value = '';
  try {
    const response = await api('/auth/reset-password', { method: 'POST', body: { token, email: email.value, password: password.value, password_confirmation: confirmation.value } });
    success.value = response.message;
    password.value = '';
    confirmation.value = '';
  } catch (reason) {
    error.value = reason.message;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <main class="auth-page-shell auth-light-shell">
    <section class="auth-page-card">
      <a class="auth-brand" href="/login" @click.prevent="navigate('/login')"><span class="brand-mark"><Swords :size="19"/></span> bracket<span>.</span></a>
      <div class="auth-page-heading"><div class="eyebrow"><span class="eyebrow-line"/> ACCOUNT RECOVERY</div><h1>Choose a new password</h1><p>The reset link can only be used once and expires after the configured reset period.</p></div>
      <form @submit.prevent="submit">
        <label>Email<input v-model="email" type="email" autocomplete="email" required/></label>
        <PasswordField v-model="password" label="New password" autocomplete="new-password" minlength="8" required/>
        <PasswordField v-model="confirmation" label="Confirm new password" autocomplete="new-password" minlength="8" required/>
        <small class="password-policy">Use at least 8 characters with a letter, number, and special character. Don’t include your display name.</small>
        <div v-if="error" class="form-error" role="alert">{{ error }}</div>
        <div v-if="success" class="auth-success-message" role="status">{{ success }}</div>
        <div class="modal-actions"><button type="button" class="secondary-button" @click="navigate('/login')">Back to sign in</button><button class="primary-button" :disabled="busy || !!success">{{ busy ? 'Saving…' : success ? 'Password updated' : 'Reset password' }}</button></div>
      </form>
    </section>
    <footer>© 2026 Bracket · Account recovery</footer>
  </main>
</template>
