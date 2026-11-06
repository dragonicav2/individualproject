<script setup>
import { ref } from 'vue';
import { Swords } from '@lucide/vue';
import Login from './Login.vue';
import { api, setToken } from '../../api';
import { navigate } from '../../router';

const busy = ref(false); const error = ref('');
async function submit(credentials) {
  busy.value = true; error.value = '';
  try { const response = await api('/auth/login', { method: 'POST', body: credentials }); setToken(response.token); navigate('/', true); }
  catch (reason) { error.value = reason.message; }
  finally { busy.value = false; }
}
</script>
<template><main class="auth-page-shell auth-light-shell"><section class="auth-page-card"><a class="auth-brand" href="/" @click.prevent="navigate('/',true)"><span class="brand-mark"><Swords :size="19"/></span> bracket<span>.</span></a><div class="auth-page-heading"><div class="eyebrow"><span class="eyebrow-line"></span> PLAYER & ORGANIZER ACCOUNT</div><h1>Welcome back</h1><p>Sign in to manage your tournaments, join a team, or compete.</p></div><Login :busy="busy" :error="error" @submit="submit" @register="navigate('/register')" @forgot="navigate('/forgot-password')"/><p class="auth-page-switch">New to Bracket? <a href="/register" @click.prevent="navigate('/register')">Create an account</a></p><p class="auth-page-switch admin-login-link"><a href="/admin/login" @click.prevent="navigate('/admin/login')">Platform administrator sign in</a></p></section><footer>© 2026 Bracket · Tournament operations</footer></main></template>
