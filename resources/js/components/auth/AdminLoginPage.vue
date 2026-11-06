<script setup>
import { ref } from 'vue';
import { Shield, Swords } from '@lucide/vue';
import PasswordField from './PasswordField.vue';
import { api, setToken } from '../../api';
import { navigate } from '../../router';

const email = ref('');
const password = ref('');
const busy = ref(false);
const error = ref('');

async function submit() {
  busy.value = true;
  error.value = '';
  try {
    const response = await api('/auth/admin-login', { method: 'POST', body: { email: email.value, password: password.value } });
    setToken(response.token);
    navigate(response.user?.must_change_password ? '/admin/password' : '/', true);
  } catch (reason) {
    error.value = reason.message;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <main class="auth-page-shell auth-light-shell admin-auth-shell">
    <section class="auth-page-card">
      <a class="auth-brand" href="/" @click.prevent="navigate('/', true)"><span class="brand-mark"><Swords :size="19"/></span> bracket<span>.</span></a>
      <div class="auth-page-heading">
        <div class="eyebrow"><span class="eyebrow-line"></span> PLATFORM CONTROL</div>
        <h1><Shield :size="25"/> Administrator sign in</h1>
        <p>This private sign-in is for platform administrators.</p>
      </div>
      <form @submit.prevent="submit">
        <label>Administrator email<input v-model="email" type="email" autocomplete="username" required/></label>
        <PasswordField v-model="password" label="Password" autocomplete="current-password" required/>
        <div v-if="error" class="form-error">{{ error }}</div>
        <div class="modal-actions"><button type="button" class="secondary-button" @click="navigate('/login')">Player / organizer sign in</button><button class="primary-button" :disabled="busy">{{ busy ? 'Please wait…' : 'Sign in as admin' }}</button></div>
      </form>
    </section>
    <footer>© 2026 Bracket · Administrator access</footer>
  </main>
</template>
