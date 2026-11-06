<script setup>
import { ref } from 'vue';
import { Copy, LogOut, Plus, Shield, Users, UserPlus } from '@lucide/vue';

const props = defineProps({ teams: { type: Array, default: () => [] }, inviteCodes: { type: Object, default: () => ({}) }, busy: Boolean, error: String });
const emit = defineEmits(['create', 'join', 'invite', 'leave', 'refresh']);
const name = ref('');
const game = ref('cs2');
const code = ref('');
const copiedTeam = ref(null);
const names = { cs2: 'Counter-Strike 2', valorant: 'VALORANT', sf6: 'Street Fighter 6', mlbb: 'Mobile Legends: Bang Bang' };

async function copyCode(team) {
  try { await navigator.clipboard.writeText(teamCode(team)); copiedTeam.value = team.id; setTimeout(() => copiedTeam.value = null, 1600); } catch {}
}
function teamCode(team) { return props.inviteCodes[team.id] || ''; }
</script>

<template>
  <section class="page-heading"><div><div class="eyebrow"><span class="eyebrow-line"></span> ROSTER MANAGEMENT</div><h1>Teams & players</h1><p>Create your roster, bring players in with an invite code, and enter team tournaments.</p></div><button class="secondary-button" :disabled="busy" @click="emit('refresh')">Refresh teams</button></section>
  <section class="team-workspace">
    <article class="panel team-create-panel"><div class="panel-heading"><div class="heading-icon activity-icon"><Users :size="17"/></div><div class="panel-title"><h2>Start a team</h2><p>Create a private roster and invite teammates.</p></div></div><div class="team-form"><label>Team name<input v-model="name" maxlength="80" placeholder="e.g. Night Shift"/></label><label>Game<select v-model="game"><option value="cs2">Counter-Strike 2</option><option value="valorant">VALORANT</option><option value="sf6">Street Fighter 6</option><option value="mlbb">Mobile Legends: Bang Bang</option></select></label><button class="primary-button" :disabled="busy || !name.trim()" @click="emit('create',{name:name.trim(),game}); name='' "><Plus :size="15"/> Create private team</button><div class="team-form-divider">HAVE AN INVITE?</div><label>Invitation code<input v-model="code" placeholder="Paste the private invitation code"/></label><button class="secondary-button" :disabled="busy || !code.trim()" @click="emit('join',code.trim()); code=''">Join team</button><div v-if="error" class="form-error">{{ error }}</div></div></article>

    <article class="panel team-roster-panel"><div class="table-heading"><div><h2>Your teams</h2><p>{{ teams.length }} membership{{ teams.length === 1 ? '' : 's' }} across your account</p></div><Users :size="18"/></div><div v-if="!teams.length" class="empty-state spacious"><Users :size="24"/><b>No teams yet</b><span>Create a private team or join one with an invitation code. Your teams stay attached to your account.</span></div>
      <div v-for="team in teams" :key="team.id" class="team-list-row team-list-row-expanded"><div class="team-avatar">{{ team.name?.slice(0,2).toUpperCase() || 'TM' }}</div><div class="team-summary"><b>{{ team.name }}</b><small>{{ names[team.game] || team.game }} · {{ team.members_count || 1 }} roster members · Team #{{ team.id }}</small><details class="team-roster-details"><summary>View roster</summary><div class="team-roster-members"><span v-for="member in team.members || []" :key="member.id"><i class="roster-member-avatar"><img v-if="member.user?.avatar_url" :src="member.user.avatar_url" alt=""/><b v-else>{{ member.user?.name?.slice(0,1).toUpperCase() || '?' }}</b></i><i class="roster-member-copy"><b>{{ member.user?.name || 'Player' }}</b><small>{{ member.role }}</small></i></span></div></details><div v-if="inviteCodes[team.id]" class="team-invite-inline"><span>Private invite code</span><code>{{ inviteCodes[team.id] }}</code><button class="icon-button" title="Copy invitation code" @click="copyCode(team)"><Copy :size="13"/></button><small v-if="copiedTeam===team.id">Copied</small></div></div><span class="private-label"><Shield :size="13"/> {{ team.my_role || 'Member' }}</span><div class="team-actions"><button v-if="team.my_role==='owner'" class="secondary-button" :disabled="busy" @click="emit('invite',team.id)"><UserPlus :size="14"/> Invite</button><button v-else class="text-button" :disabled="busy" @click="emit('leave',team.id)"><LogOut :size="14"/> Leave</button></div></div>
    </article>
  </section>
</template>
