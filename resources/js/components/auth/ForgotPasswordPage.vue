<script setup>
import { ref } from 'vue';
import { Swords } from '@lucide/vue';
import { api } from '../../api';
import { navigate } from '../../router';

const email = ref('');
const busy = ref(false);
const error = ref('');
const message = ref('');

async function submit() {
  busy.value = true;
  error.value = '';
  message.value = '';
  try {
    const response = await api('/auth/forgot-password', { method: 'POST', body: { email: email.value } });
    message.value = response.message;
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
      <div class="auth-page-heading"><div class="eyebrow"><span class="eyebrow-line"/> ACCOUNT RECOVERY</div><h1>Reset your password</h1><p>Enter the email address on your account and we’ll send a password reset link if it exists.</p></div>
      <form @submit.prevent="submit">
        <label>Email<input v-model="email" type="email" autocomplete="email" required/></label>
        <div v-if="error" class="form-error" role="alert">{{ error }}</div>
        <div v-if="message" class="auth-success-message" role="status">{{ message }}</div>
        <div class="modal-actions"><button type="button" class="secondary-button" @click="navigate('/login')">Back to sign in</button><button class="primary-button" :disabled="busy">{{ busy ? 'Sending…' : 'Send reset link' }}</button></div>
      </form>
    </section>
    <footer>© 2026 Bracket · Account recovery</footer>
  </main>
</template>
