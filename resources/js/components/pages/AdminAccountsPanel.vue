<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { api } from '../../api';

const accounts = ref([]);
const busy = ref(false);
const creating = ref(false);
const error = ref('');
const notice = ref('');
const credentials = ref(null);
const form = reactive({ name: '', email: '' });
const expiresLabel = computed(() => credentials.value?.expires_at ? new Date(credentials.value.expires_at).toLocaleString() : '');

async function load() {
  busy.value = true; error.value = '';
  try { accounts.value = (await api('/admin/users')).data || []; }
  catch (reason) { error.value = reason.message; }
  finally { busy.value = false; }
}
async function createAccount() {
  creating.value = true; error.value = ''; notice.value = ''; credentials.value = null;
  try {
    credentials.value = await api('/admin/users', { method: 'POST', body: { name: form.name, email: form.email } });
    form.name = ''; form.email = '';
    notice.value = 'Admin account created. Copy its temporary password now; it will only be shown here once.';
    await load();
  } catch (reason) { error.value = reason.message; }
  finally { creating.value = false; }
}
async function reissue(user) {
  error.value = ''; notice.value = ''; credentials.value = null;
  try {
    credentials.value = await api(`/admin/users/${user.id}/temporary-password`, { method: 'POST', body: {} });
    notice.value = `A new five-minute password was issued for ${user.email}.`;
    await load();
  } catch (reason) { error.value = reason.message; }
}
async function copyPassword() {
  try { await navigator.clipboard.writeText(credentials.value.temporary_password); notice.value = 'Temporary password copied. Share it privately with the new admin.'; }
  catch { error.value = 'Clipboard access failed. Select and copy the password manually.'; }
}
onMounted(load);
</script>

<template>
  <section class="admin-users-page">
    <header class="admin-users-heading"><div><div class="eyebrow"><span class="eyebrow-line"/> ADMIN ACCESS</div><h2>Administrator accounts</h2><p>Create monitoring-only accounts. Temporary passwords expire in five minutes and require a password change at first sign-in.</p></div><button class="secondary-button" :disabled="busy" @click="load">{{ busy ? 'Refreshing…' : 'Refresh list' }}</button></header>
    <div v-if="error" class="form-error admin-feedback">{{ error }}</div><div v-if="notice" class="admin-notice">{{ notice }}</div>
    <article v-if="credentials" class="admin-temp-credential"><div><b>Temporary password</b><p>Share privately. Valid until {{ expiresLabel }}. The recipient must choose a new password before viewing the dashboard.</p></div><code>{{ credentials.temporary_password }}</code><button class="primary-button" @click="copyPassword">Copy password</button></article>

    <div class="admin-account-layout">
      <form class="panel admin-create-form" @submit.prevent="createAccount"><div class="eyebrow"><span class="eyebrow-line"/> NEW ACCOUNT</div><h3>Create limited admin</h3><label>Display name<input v-model="form.name" maxlength="80" required/></label><label>Email address<input v-model="form.email" type="email" autocomplete="off" required/></label><button class="primary-button" :disabled="creating">{{ creating ? 'Creating…' : 'Create account' }}</button><small>Admins can view platform analytics. They cannot browse profiles, join teams, create tournaments, or change match data.</small></form>
      <section class="panel admin-account-list"><header><h3>Admin access roster</h3><span>{{ accounts.length }} accounts</span></header><div v-if="busy&&!accounts.length" class="empty-state">Loading admin accounts…</div><div v-else-if="!accounts.length" class="empty-state">No admin accounts were found.</div><article v-for="account in accounts" :key="account.id" class="admin-account-row"><div class="admin-account-avatar">{{ account.name.slice(0,2).toUpperCase() }}</div><div class="admin-account-copy"><b>{{ account.name }} <small v-if="account.admin_role==='super_admin'">PRIMARY</small></b><span>{{ account.email }}</span><em>{{ account.must_change_password ? 'Password setup required' : 'Active' }}<template v-if="account.temporary_password_expires_at"> · temporary pass issued {{ new Date(account.temporary_password_expires_at).toLocaleTimeString() }}</template></em></div><button v-if="account.admin_role==='admin'" class="text-button" @click="reissue(account)">Issue new temporary password</button></article></section>
    </div>
  </section>
</template>
