<script setup>
import { ref } from 'vue';
import { Swords } from '@lucide/vue';
import Register from './Register.vue';
import { api, setToken } from '../../api';
import { navigate } from '../../router';

const busy = ref(false); const error = ref('');
async function submit(details) {
  busy.value = true; error.value = '';
  try { const response = await api('/auth/register', { method: 'POST', body: details }); setToken(response.token); navigate('/', true); }
  catch (reason) { error.value = reason.message; }
  finally { busy.value = false; }
}
</script>
<template><main class="auth-page-shell auth-light-shell"><section class="auth-page-card"><a class="auth-brand" href="/" @click.prevent="navigate('/',true)"><span class="brand-mark"><Swords :size="19"/></span> bracket<span>.</span></a><div class="auth-page-heading"><div class="eyebrow"><span class="eyebrow-line"></span> START COMPETING</div><h1>Create your account</h1><p>Choose whether you’re here to play or run tournaments. Organizers can still participate in events when that tournament allows it.</p></div><Register :busy="busy" :error="error" @submit="submit" @login="navigate('/login')"/><p class="auth-page-switch">Already have an account? <a href="/login" @click.prevent="navigate('/login')">Sign in</a></p></section><footer>© 2026 Bracket · Tournament operations</footer></main></template>
