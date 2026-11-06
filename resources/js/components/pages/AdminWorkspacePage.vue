<script setup>
import { ref } from 'vue';
import { BarChart3, ImagePlus, LogOut, Settings, Shield, Users } from '@lucide/vue';
import { api, setToken } from '../../api';
import { navigate } from '../../router';
import AdminAnalyticsPanel from './AdminAnalyticsPanel.vue';
import AdminAccountsPanel from './AdminAccountsPanel.vue';
import SettingsPage from './SettingsPage.vue';
import AdminGameAssetsPage from './AdminGameAssetsPage.vue';

defineProps({ user: { type: Object, required: true } });
const activeTab = ref(window.location.pathname === '/admin/users' ? 'accounts' : window.location.pathname === '/admin/settings' ? 'settings' : window.location.pathname === '/admin/game-artwork' ? 'artwork' : 'analytics');
const signingOut = ref(false);
const theme = ref(window.localStorage.getItem('bracket-theme') === 'light' ? 'light' : 'dark');
document.documentElement.dataset.theme = theme.value;
function setTab(tab) { activeTab.value = tab; navigate(tab === 'accounts' ? '/admin/users' : tab === 'settings' ? '/admin/settings' : tab === 'artwork' ? '/admin/game-artwork' : '/', true); }
function setTheme(value) { theme.value = value; window.localStorage.setItem('bracket-theme', value); document.documentElement.dataset.theme = value; }
async function signOut() {
  signingOut.value = true;
  try { await api('/auth/logout', { method: 'POST' }); } catch {}
  setToken(null);
  navigate('/admin/login', true);
}
</script>

<template>
  <main class="admin-console">
    <aside class="admin-console-sidebar"><a class="admin-console-brand" href="/" @click.prevent="setTab('analytics')"><span>BRACKET</span><b>ADMIN</b></a><div class="admin-console-label">MONITORING</div><button class="admin-console-nav" :class="{active:activeTab==='analytics'}" @click="setTab('analytics')"><BarChart3 :size="17"/> Platform analytics</button><button v-if="user.admin_role==='super_admin'" class="admin-console-nav" :class="{active:activeTab==='accounts'}" @click="setTab('accounts')"><Users :size="17"/> Admin users</button><button v-if="user.admin_role==='super_admin'" class="admin-console-nav" :class="{active:activeTab==='artwork'}" @click="setTab('artwork')"><ImagePlus :size="17"/> Game artwork</button><div class="admin-console-label">ACCOUNT</div><button class="admin-console-nav" :class="{active:activeTab==='settings'}" @click="setTab('settings')"><Settings :size="17"/> Settings</button><div class="admin-console-policy"><Shield :size="17"/><b>Read only access</b><span>Tournament and team controls are unavailable to every admin account.</span></div><div class="admin-console-sidebar-foot"><b>{{ user.name }}</b><small>{{ user.admin_role==='super_admin'?'Primary administrator':'Monitoring administrator' }}</small></div></aside>
    <section class="admin-console-main"><header class="admin-console-topbar"><div><span>PLATFORM ADMINISTRATION</span><b>{{ activeTab==='accounts' ? 'Admin users' : activeTab==='settings' ? 'Settings' : activeTab==='artwork' ? 'Game artwork' : 'Platform overview' }}</b></div><button class="secondary-button" :disabled="signingOut" @click="signOut"><LogOut :size="15"/> {{ signingOut ? 'Signing out…' : 'Sign out' }}</button></header><div class="admin-console-content"><AdminAnalyticsPanel v-if="activeTab==='analytics'" admin-mode/><AdminAccountsPanel v-else-if="activeTab==='accounts'&&user.admin_role==='super_admin'"/><AdminGameAssetsPage v-else-if="activeTab==='artwork'&&user.admin_role==='super_admin'"/><SettingsPage v-else-if="activeTab==='settings'" :theme="theme" @set-theme="setTheme"/></div></section>
  </main>
</template>
