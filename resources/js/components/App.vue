<script setup>
import { ref, watch } from 'vue';
import { api, getToken, setToken } from '../api';
import { currentPath, navigate } from '../router';
import Workspace from './Workspace.vue';
import LoginPage from './auth/LoginPage.vue';
import AdminLoginPage from './auth/AdminLoginPage.vue';
import AdminPasswordPage from './auth/AdminPasswordPage.vue';
import RegisterPage from './auth/RegisterPage.vue';
import ForgotPasswordPage from './auth/ForgotPasswordPage.vue';
import ResetPasswordPage from './auth/ResetPasswordPage.vue';
import CreateTournamentPage from './pages/CreateTournamentPage.vue';
import DemoData from './pages/Demodata.vue';

const checkingAdminSession = ref(currentPath.value === '/admin/login' && Boolean(getToken()));

watch(currentPath, async (path) => {
  checkingAdminSession.value = path === '/admin/login' && Boolean(getToken());
  if (['/', '/tournaments', '/tournaments/create', '/demo-data', '/admin/users', '/admin/settings', '/admin/game-artwork'].includes(path) && !getToken()) navigate(path.startsWith('/admin/') ? '/admin/login' : '/login', true);
  else if (path === '/admin/password' && !getToken()) navigate('/admin/login', true);
  else if ((path === '/login' || path === '/register') && getToken()) navigate('/', true);

  if (path === '/admin/login' && getToken()) {
    const token = getToken();
    try {
      const response = await api('/auth/me');
      if (currentPath.value !== '/admin/login' || getToken() !== token) return;
      const user = response.user;
      navigate(user?.is_platform_admin ? (user.must_change_password ? '/admin/password' : '/') : '/', true);
    } catch {
      if (currentPath.value === '/admin/login' && getToken() === token) setToken(null);
    } finally {
      checkingAdminSession.value = false;
    }
  }
}, { immediate: true });
</script>
<template><Workspace v-if="currentPath === '/' || currentPath === '/tournaments' || currentPath === '/admin/users' || currentPath === '/admin/settings' || currentPath === '/admin/game-artwork'"/><CreateTournamentPage v-else-if="currentPath === '/tournaments/create'"/><DemoData v-else-if="currentPath === '/demo-data'"/><main v-else-if="currentPath === '/admin/login' && checkingAdminSession" class="auth-page-shell auth-light-shell" aria-label="Checking sign-in status"/><AdminLoginPage v-else-if="currentPath === '/admin/login'"/><AdminPasswordPage v-else-if="currentPath === '/admin/password'"/><LoginPage v-else-if="currentPath === '/login'"/><RegisterPage v-else-if="currentPath === '/register'"/><ForgotPasswordPage v-else-if="currentPath === '/forgot-password'"/><ResetPasswordPage v-else-if="currentPath.startsWith('/reset-password/')"/><LoginPage v-else/></template>
