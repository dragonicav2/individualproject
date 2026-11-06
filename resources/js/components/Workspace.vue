<script setup>import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Swords, LayoutDashboard, Trophy, Users, Radio, BarChart3, Settings, Bell, Search, Plus, ChevronRight, ChevronDown, CalendarDays, MapPin, MoreHorizontal, ArrowUpRight, CircleHelp, Menu, X, Shield, Server, Clock3, CheckCircle2, Database, Copy, ImagePlus } from '@lucide/vue';
import { api, download, getToken, setSessionToken, setToken, upload } from '../api';
import OverviewPage from './pages/OverviewPage.vue';
import TournamentsPage from './pages/TournamentsPage.vue';
import TeamsPage from './pages/TeamsPage.vue';
import LiveMatchesPage from './pages/LiveMatchesPage.vue';
import AnalyticsPage from './pages/AnalyticsPage.vue';
import AdminGameAssetsPage from './pages/AdminGameAssetsPage.vue';
import AdminWorkspacePage from './pages/AdminWorkspacePage.vue';
import ProfilePage from './pages/ProfilePage.vue';
import SettingsPage from './pages/SettingsPage.vue';
import StandingsTable from './pages/StandingsTable.vue';
import SingleEliminationBracket from './pages/SingleEliminationBracket.vue';
import DoubleEliminationBracket from './pages/DoubleEliminationBracket.vue';
import MatchDetailsModal from './pages/MatchDetailsModal.vue';
import { navigate as navigateRoute } from '../router';
const activeNav = ref(window.location.pathname === '/tournaments' ? 'Tournaments' : 'Overview');
const savedTheme = window.localStorage.getItem('bracket-theme') === 'light' ? 'light' : 'dark';
const theme = ref(savedTheme);
document.documentElement.dataset.theme = savedTheme;
const accountMenuOpen = ref(false);
const showTournament = ref(false);
const authUser = ref(null);
const selectedTournament = ref(null);
const tournamentTab = ref('overview');
const selectedMatchDetail = ref(null);
const tournamentMatches = ref([]);
const liveMatches = ref([]);
const liveMatchesLoading = ref(false);
const openingMlbbRoom = ref(false);
const mlbbOcrResult = ref(null);
const mlbbOcrError = ref('');
const extractingMlbbOcr = ref(false);
const demoPlayerPreview = ref(false);
const demoPlayerTeamInvite = ref('');
const mlbbRoomError = ref('');
const tournamentEntries = ref([]);
const tournamentStandings = ref([]);
const myEntryId = ref(null);
const myEntrantId = ref(null);
const myEntryCheckedInAt = ref(null);
const checkInClock = ref(Date.now());
let checkInClockTimer;
const checkInAvailability = computed(() => {
  const startsAt = selectedTournament.value?.starts_at;
  if (!startsAt) return { allowed: false, message: 'Check-in is unavailable until the organizer schedules a start time.' };
  const start = new Date(startsAt).getTime();
  const now = checkInClock.value;
  if (now < start - 60 * 60 * 1000) return { allowed: false, message: 'Check-in opens one hour before the tournament starts.' };
  if (now > start) return { allowed: false, message: 'The tournament start time has passed; check-in is closed.' };
  return { allowed: true, message: 'Check-in is open.' };
});
const teamIdInput = ref('');
const newTeamName = ref('');
const teamInviteCodes = ref({});
const teams = ref([]);
const workspaceGame = ref('cs2');
const searchQuery = ref('');
const loading = ref(false);
const isBusy = ref(false);
const actionError = ref('');
const toast = ref('');
const resultForms = reactive({});
const disputeForms = reactive({});
const disputeEvidencePreviews = reactive({});
const editingResultIds = reactive({});
const matchServers = reactive({});
const serverForms = reactive({});
const cs2Regions = ['private-lan'];
const cs2Maps = ['de_ancient', 'de_anubis', 'de_dust2', 'de_inferno', 'de_mirage', 'de_nuke', 'de_overpass', 'de_vertigo'];
const nav = [  { label: 'Overview', icon: LayoutDashboard },  { label: 'Tournaments', icon: Trophy, count: '4' },  { label: 'Teams & players', icon: Users },  { label: 'Live matches', icon: Radio, live: true },  { label: 'Analytics', icon: BarChart3 },  { label: 'Game artwork', icon: ImagePlus, adminOnly: true },  { label: 'My profile', icon: Users, playerOnly: true },  { label: 'Settings', icon: Settings },];
const visibleNav = computed(() => nav.filter(item => (!item.playerOnly || !!authUser.value) && (!item.adminOnly || !!authUser.value?.is_platform_admin)));
function setTheme(value) { if (!['light', 'dark'].includes(value)) return; theme.value = value; document.documentElement.dataset.theme = value; window.localStorage.setItem('bracket-theme', value); }
const games = [  { name: 'Counter-Strike 2', short: 'CS2', color: '#e7a83e', symbol: '◉' },  { name: 'VALORANT', short: 'VAL', color: '#ff5264', symbol: '◈' },  { name: 'Street Fighter 6', short: 'SF6', color: '#56b7a7', symbol: '拳' },  { name: 'Mobile Legends', short: 'MLBB', color: '#8472f4', symbol: '✦' },];
const sampleTournaments = [  { id: 'T-0248', name: 'Northern Lights Open', game: 'Counter-Strike 2', format: 'Double elimination', teams: '24 / 32', date: 'Today, 18:00', status: 'Registration open', initials: ['NL', 'K', 'V'], accent: '#e9a83d', demo: true },  { id: 'T-0247', name: 'VALORANT Community Cup #08', game: 'VALORANT', format: 'Groups + playoffs', teams: '16 / 16', date: 'Today, 20:30', status: 'In progress', initials: ['V', 'R', 'A'], accent: '#ff5264', demo: true },  { id: 'T-0246', name: 'Friday Night Fights', game: 'Street Fighter 6', format: 'Double elimination', teams: '18 / 32', date: 'Fri, 19:00', status: 'Registration open', initials: ['F', 'N', 'J'], accent: '#56b7a7', demo: true },];
const tournaments = ref([...sampleTournaments]);
const match = ref({ status: 'Ready', server: 'SG • Singapore', map: 'de_mirage', address: '103.42.18.206:27015' });
const displayName = computed(() => authUser.value?.name?.split(' ')[0] || 'Jordan');
const isOrganizerAccount = computed(() => authUser.value?.account_type === 'organizer');
const isOrganizer = computed(() => isOrganizerAccount.value && Number(selectedTournament.value?.organizer_id) === Number(authUser.value?.id));
function matchHeading(match) { const phase = { winners: 'Winners bracket', losers: 'Losers bracket', grand_final: 'Grand final' }[match.bracket_side || 'winners']; return selectedTournament.value?.formatCode === 'double_elimination' ? `${phase} · Round ${match.round} · Match ${match.match_number}` : `Round ${match.round} · Match ${match.match_number}`; }
const gameTeamsForTournament = computed(() => teams.value.filter(team => team.game === selectedTournament.value?.gameCode));
const displayedTournamentMatches = computed(() => !isOrganizer.value && myEntryId.value ? tournamentMatches.value.filter(match => [match.entrant_a_id, match.entrant_b_id].some(id => Number(id) === Number(myEntryId.value))) : tournamentMatches.value);
const fullTeamsForTournament = computed(() => gameTeamsForTournament.value.filter(team => Number(team.members_count || 0) >= Number(selectedTournament.value?.team_size || 1)));
const greeting = computed(() => authUser.value ? `Good afternoon, ${displayName.value}` : 'Good afternoon, Jordan');
function notify(message) { toast.value = message;
 setTimeout(() => toast.value = '', 2800);
 }const gameNames = { cs2: 'Counter-Strike 2', valorant: 'VALORANT', sf6: 'Street Fighter 6', mlbb: 'Mobile Legends' };
const formatNames = { single_elimination: 'Single elimination', double_elimination: 'Double elimination', round_robin: 'Round robin', groups_playoffs: 'Groups + playoffs', swiss: 'Swiss' };
const gameColors = { cs2: '#e9a83d', valorant: '#ff5264', sf6: '#56b7a7', mlbb: '#8472f4' };
const gameSymbols = { cs2: '◉', valorant: 'V', sf6: '拳', mlbb: '✦' };
function normalizeTournament(item) {  const game = gameNames[item.game] || item.game || 'Unknown game';
  const count = item.entries_count ?? item.entries?.length ?? 0;
  const status = { registration_open: 'Registration open', live: 'In progress', draft: 'Draft', completed: 'Completed' }[item.status] || item.status;
  return { ...item, id: String(item.id), publicId: `T-${String(item.id).padStart(4, '0')}`, game, gameCode: item.game, formatCode: item.format, format: formatNames[item.format] || item.format, statusCode: item.status, allowOrganizerEntry: !!item.settings?.allow_organizer_entry, seedingStrategy: item.settings?.seeding_strategy || 'fixed', settings: item.settings || {}, teams: `${count}${item.capacity ? ` / ${item.capacity}` : ''} entrants`, date: item.starts_at ? new Date(item.starts_at).toLocaleString() : 'Schedule pending', status, initials: [gameSymbols[item.game] || '•'], accent: gameColors[item.game] || '#8472f4' };
}function requireAuth() { if (!authUser.value) { navigateRoute('/login');
 notify('Sign in to continue');
 return false;
 } return true;
 }async function refreshTournaments() {  try {    const mine = getToken() ? await api('/my/tournaments') : { data: [] };
    const publicEvents = await api('/tournaments');
    const unique = new Map([...(publicEvents.data || []), ...(mine.data || [])].map(item => [String(item.id), item]));
    const rows = [...unique.values()].map(normalizeTournament);
    tournaments.value = rows.length ? rows : [...sampleTournaments];
  } catch { tournaments.value = [...sampleTournaments];
 }}async function refreshTeams() {  if (!getToken()) { teams.value = [];
 return;
 }  try { const response = await api('/my/teams');
 teams.value = response.data || [];
 }  catch (error) { actionError.value = error.message;
 }}async function signOut() {  try { await api('/auth/logout', { method: 'POST' });
 } catch {}  setToken(null);
 authUser.value = null;
 teams.value = [];
 teamInviteCodes.value = {};
 await refreshTournaments();
 navigateRoute('/login');
}async function refreshEntries() {
  if (!selectedTournament.value || !isOrganizer.value) return;
  try { const result = await api(`/tournaments/${selectedTournament.value.id}/entries`); tournamentEntries.value = result.data || []; await refreshSelected(); }
  catch (error) { actionError.value = error.message; }
}async function autoSeedTournament() {
  if (!selectedTournament.value || !isOrganizer.value) return;
  isBusy.value = true;
  actionError.value = '';
  try {
    const result = await api(`/tournaments/${selectedTournament.value.id}/auto-seed`, { method: 'POST' });
    tournamentEntries.value = result.data || [];
    notify('Entrants seeded by game ELO');
  } catch (error) { actionError.value = error.message; }
  finally { isBusy.value = false; }
}async function openTournament(row) { selectedMatchDetail.value = null; tournamentTab.value = 'overview'; if (row.demo || !/^\d+$/.test(row.id)) { notify('These rows are sample events. Create a tournament or sign in to load real events.');
 return;
 }  isBusy.value = true;
 actionError.value = '';
 myEntryId.value = null;
 myEntrantId.value = null;
 myEntryCheckedInAt.value = null;
 teamIdInput.value = '';
 tournamentStandings.value = [];
  try {    const response = await api(`/tournaments/${row.id}`);
    selectedTournament.value = normalizeTournament(response);
    const [bracket] = await Promise.all([api(`/tournaments/${row.id}/bracket`)]);
    tournamentMatches.value = bracket.data || [];

    if (selectedTournament.value.formatCode === 'round_robin') { const standings = await api(`/tournaments/${row.id}/standings`); tournamentStandings.value = standings.data || []; }
    tournamentEntries.value = [];
    if (getToken()) {      try {        const mineEntry = await api(`/tournaments/${row.id}/my-entry`);
        myEntryId.value = mineEntry.data?.id || null;
        myEntrantId.value = mineEntry.data?.id || null;
        myEntryCheckedInAt.value = mineEntry.data?.checked_in_at || null;
      } catch {}      if (getToken()) await loadMatchServers();      try {        const mine = await api(`/tournaments/${row.id}/entries`);
        tournamentEntries.value = mine.data || [];
      } catch {}    }    showTournament.value = true;
  } catch (error) { notify(error.message);
 }  finally { isBusy.value = false;
 }}async function openRegistration() {  isBusy.value = true;
 actionError.value = '';
  try { await api(`/tournaments/${selectedTournament.value.id}/open-registration`, { method: 'POST' });
 await refreshSelected();
 notify('Registration is open');
 }  catch (error) { actionError.value = error.message;
 }  finally { isBusy.value = false;
 }}async function refreshSelected() {  const response = await api(`/tournaments/${selectedTournament.value.id}`);
  selectedTournament.value = normalizeTournament(response);
  await refreshTournaments();
}async function toggleOrganizerParticipation(allowed) { try { const response = await api(`/tournaments/${selectedTournament.value.id}/organizer-participation`, { method: 'PATCH', body: { allow_organizer_entry: allowed } }); selectedTournament.value = normalizeTournament(response.data); await refreshTournaments(); notify(allowed ? 'Organizer participation enabled' : 'Organizer participation disabled'); } catch (error) { actionError.value = error.message; await refreshSelected(); } }async function updateSeedingStrategy(strategy) {
  if (!selectedTournament.value || !isOrganizer.value) return;
  isBusy.value = true;
  actionError.value = '';
  try {
    const response = await api(`/tournaments/${selectedTournament.value.id}/seeding-strategy`, { method: 'PATCH', body: { seeding_strategy: strategy } });
    selectedTournament.value = normalizeTournament(response.data);
    await refreshTournaments();
    notify('Round reseeding strategy saved');
  } catch (error) { actionError.value = error.message; await refreshSelected(); }
  finally { isBusy.value = false; }
}async function registerForTournament() {  if (!requireAuth()) return;
  isBusy.value = true;
 actionError.value = '';
  try {    const body = Number(selectedTournament.value.team_size) === 5 ? { team_id: Number(teamIdInput.value) } : {};
    const response = await api(`/tournaments/${selectedTournament.value.id}/register`, { method: 'POST', body });
    myEntryId.value = response.data.id;
 myEntrantId.value = response.data.id;
 myEntryCheckedInAt.value = response.data.checked_in_at || null;
 await refreshSelected();
 notify('Registration confirmed. Check in before the organizer publishes the bracket.');
  } catch (error) { actionError.value = error.message;
 }  finally { isBusy.value = false;
}}async function createTeam(payload = null) {  if (!requireAuth()) return;
  if (payload) { newTeamName.value = payload.name;
 workspaceGame.value = payload.game;
 }  isBusy.value = true;
 actionError.value = '';
  try {    const response = await api('/teams', { method: 'POST', body: { name: newTeamName.value, game: workspaceGame.value, is_private: true } });
    await refreshTeams();
    teamIdInput.value = response.data.id;
 newTeamName.value = '';
    const invite = await api(`/teams/${response.data.id}/invites`, { method: 'POST', body: { expires_in_hours: 72, max_uses: 4 } });
    teamInviteCodes.value[response.data.id] = invite.code;
 notify(`Team created. Share its invite code with your roster.`);
  } catch (error) { actionError.value = error.message;
 }  finally { isBusy.value = false;
}}async function inviteTeam(teamId) {
  isBusy.value = true; actionError.value = '';
  try { const invite = await api(`/teams/${teamId}/invites`, { method: 'POST', body: { expires_in_hours: 72, max_uses: 4 } }); teamInviteCodes.value[teamId] = invite.code; notify('Invite created. Copy and share the code with your roster.'); }
  catch (error) { actionError.value = error.message; }
  finally { isBusy.value = false; }
}async function leaveTeam(teamId) {
  isBusy.value = true; actionError.value = '';
  try { await api(`/teams/${teamId}/membership`, { method: 'DELETE' }); await refreshTeams(); notify('You left the team.'); }
  catch (error) { actionError.value = error.message; }
  finally { isBusy.value = false; }
}async function checkIn() {  if (!requireAuth() || !myEntryId.value) return;
  isBusy.value = true;
  try { const response = await api(`/tournament-entries/${myEntryId.value}/check-in`, { method: 'POST' });
 myEntryCheckedInAt.value = response.data.checked_in_at;
 notify('You are checked in');
 await refreshSelected();
 }  catch (error) { actionError.value = error.message;
 }  finally { isBusy.value = false;
 }}async function withdrawEntry() {  if (!myEntryId.value) return;
  isBusy.value = true;
 actionError.value = '';
  try { await api(`/tournament-entries/${myEntryId.value}`, { method: 'DELETE' });
 myEntryId.value = null;
 myEntrantId.value = null;
 myEntryCheckedInAt.value = null;
 await refreshSelected();
 notify('Tournament registration withdrawn');
 }  catch (error) { actionError.value = error.message;
 }  finally { isBusy.value = false;
 }}async function updateSeed(entry, value) {  try { await api(`/tournament-entries/${entry.id}/seed`, { method: 'PATCH', body: { seed: value === '' ? null : Number(value) } });
 const refreshed = await api(`/tournaments/${selectedTournament.value.id}/entries`);
 tournamentEntries.value = refreshed.data || [];
 notify('Entrant seed saved');
 }  catch (error) { actionError.value = error.message;
 }}async function publishTournament() {  isBusy.value = true;
 actionError.value = '';
  try { const result = await api(`/tournaments/${selectedTournament.value.id}/publish`, { method: 'POST' });
 tournamentMatches.value = result.data.matches || [];
 await refreshSelected();
 notify('Bracket published');
 }  catch (error) { actionError.value = error.message;
 }  finally { isBusy.value = false;
}}async function loadMatchServers() {
  await Promise.all(displayedTournamentMatches.value.map(async gameMatch => {
    serverForms[gameMatch.id] ||= { region: 'private-lan', map: 'de_mirage' };
    try { const result = await api(`/matches/${gameMatch.id}/game-room`); matchServers[gameMatch.id] = result.data; }
    catch (error) { if (!String(error.message).includes('(404)')) actionError.value = error.message; else delete matchServers[gameMatch.id]; }
  }));
}function serverForm(gameMatch) { return serverForms[gameMatch.id] ||= { region: 'private-lan', map: 'de_mirage' }; }
async function provisionMatchServer(gameMatch) {
  isBusy.value = true; actionError.value = '';
  try { const result = await api(`/matches/${gameMatch.id}/cs2-server`, { method: 'POST', body: serverForm(gameMatch) }); matchServers[gameMatch.id] = result.data; notify('CS2 server provisioning requested'); }
  catch (error) { actionError.value = error.message; }
  finally { isBusy.value = false; }
}async function refreshMatchServer(gameMatch) {
  try { const result = await api(`/matches/${gameMatch.id}/game-room`); matchServers[gameMatch.id] = result.data; }
  catch (error) { actionError.value = error.message; }
}async function stopMatchServer(gameMatch) {
  isBusy.value = true; actionError.value = '';
  try { const result = await api(`/matches/${gameMatch.id}/cs2-server/stop`, { method: 'POST' }); await refreshMatchServer(gameMatch); notify(result.data.demo_received ? 'Server stopped · demo saved to this match' : 'Server stopped · demo upload is still pending'); }
  catch (error) { actionError.value = error.message; }
  finally { isBusy.value = false; }
}async function downloadMatchDemo(gameMatch) {
  try { await download(`/matches/${gameMatch.id}/cs2-demo`, `match-${gameMatch.id}.dem`); }
  catch (error) { actionError.value = error.message; }
}async function copyServerConnect(gameMatch) {
  const server = matchServers[gameMatch.id];
  if (!server?.host || !server?.port) return;
  try { await navigator.clipboard.writeText(`connect ${server.host}:${server.port}`); notify('Connect command copied'); }
  catch { notify(`Connect ${server.host}:${server.port}`); }
}async function openMlbbRoom(gameMatch) {
  mlbbRoomError.value = '';
  if (!isOrganizer.value || selectedTournament.value?.gameCode !== 'mlbb') return;
  const roomWindow = window.open('about:blank', '_blank');
  if (!roomWindow) { mlbbRoomError.value = 'Your browser blocked the new tab. Allow pop-ups for this tournament app, then try again.'; return; }
  roomWindow.opener = null;
  openingMlbbRoom.value = true;
  try {
    const result = await api(`/matches/${gameMatch.id}/mlbb-room`);
    if (!result.data?.url) throw new Error('The emulator station did not return a browser URL.');
    roomWindow.location.replace(result.data.url);
  } catch (error) {
    roomWindow.close();
    mlbbRoomError.value = error.message;
  } finally { openingMlbbRoom.value = false; }
}function openMlbbPlayerPreview(payload) {
  const gameMatch = payload?.match;
  const side = payload?.side;
  if (!gameMatch || !['a', 'b'].includes(side) || !isOrganizer.value || !(selectedTournament.value?.settings?.demo_mlbb_emulator === true || selectedTournament.value?.settings?.demo_player_result_reporting === true || selectedTournament.value?.settings?.demo_match_operations === true)) return;
  const query = new URLSearchParams({ demo_mlbb_tournament: selectedTournament.value.id, demo_mlbb_match: String(gameMatch.id), demo_mlbb_side: side, demo_player: '1' });
  const playerWindow = window.open(`/tournaments?${query}`, '_blank');
  if (playerWindow) playerWindow.opener = null;
}async function saveMlbbRoomCode(payload) {
  const gameMatch = payload?.match || selectedMatchDetail.value;
  if (!gameMatch || !isOrganizer.value) return;
  isBusy.value = true;
  actionError.value = '';
  try {
    await api(`/matches/${gameMatch.id}/mlbb-room-code`, { method: 'PATCH', body: { room_code: payload.room_code } });
    await reloadBracket();
    selectedMatchDetail.value = tournamentMatches.value.find(row => Number(row.id) === Number(gameMatch.id)) || null;
    notify('Room code shared with both participating sides');
  } catch (error) { actionError.value = error.message; }
  finally { isBusy.value = false; }
}async function copyMlbbRoomCode(code) {
  if (!code) return;
  try { await navigator.clipboard.writeText(code); notify('MLBB room code copied'); }
  catch { notify(`Room code: ${code}`); }
}async function copyDemoTeamInvite() {
  if (!demoPlayerTeamInvite.value) return;
  try { await navigator.clipboard.writeText(demoPlayerTeamInvite.value); notify('Practice team invite copied'); }
  catch { notify(`Invite code: ${demoPlayerTeamInvite.value}`); }
}async function uploadProfileAvatar(file) {
  if (!file) return;
  isBusy.value = true;
  actionError.value = '';
  try {
    const form = new FormData();
    form.append('avatar', file);
    const response = await upload('/auth/profile/avatar', form);
    authUser.value = response.user;
    notify('Profile picture updated');
  } catch (error) { actionError.value = error.message; }
  finally { isBusy.value = false; }
}async function removeProfileAvatar() {
  isBusy.value = true;
  actionError.value = '';
  try {
    const response = await api('/auth/profile/avatar', { method: 'DELETE' });
    authUser.value = response.user;
    notify('Profile picture removed');
  } catch (error) { actionError.value = error.message; }
  finally { isBusy.value = false; }
}async function saveProfile(payload) {
  isBusy.value = true;
  actionError.value = '';
  try {
    const response = await api('/auth/profile', { method: 'PATCH', body: payload });
    authUser.value = response.user;
    notify('Player profile saved');
  } catch (error) { actionError.value = error.message; }
  finally { isBusy.value = false; }
}async function submitResult(gameMatch) {  const target = Math.floor(Number(gameMatch.best_of) / 2) + 1;
  const values = resultForms[gameMatch.id] || { score_a: target, score_b: 0 };
  try { await api(`/matches/${gameMatch.id}/result`, { method: 'POST', body: { score_a: Number(values.score_a), score_b: Number(values.score_b) } });
 notify(isOrganizer.value ? 'Score recorded as pending; confirm it to advance the bracket' : 'Score sent to the opposing entrant for confirmation');
 await reloadBracket();
 }  catch (error) { actionError.value = error.message;
 }}function resultForm(gameMatch) {  if (!resultForms[gameMatch.id]) {    resultForms[gameMatch.id] = { score_a: gameMatch.score_a ?? Math.floor(Number(gameMatch.best_of) / 2) + 1, score_b: gameMatch.score_b ?? 0, resolution: '', reason: '', outcome: 'valid' };
  }  return resultForms[gameMatch.id];
}function ownsMatchEntrant(gameMatch) {  return !!myEntrantId.value && [gameMatch.entrant_a_id, gameMatch.entrant_b_id].some(id => Number(id) === Number(myEntrantId.value));
}function canRespondToResult(gameMatch) {  return gameMatch.status === 'result_pending' && ownsMatchEntrant(gameMatch) && Number(gameMatch.result_reporter_id) !== Number(authUser.value?.id);
}function canOrganizerConfirmResult(gameMatch) { return isOrganizer.value && gameMatch.status === 'result_pending';
}async function confirmResult(gameMatch) {  try { await api(`/matches/${gameMatch.id}/confirm-result`, { method: 'POST' });
 notify('Result confirmed');
 await reloadBracket();
 }  catch (error) { actionError.value = error.message;
  }}function disputeForm(gameMatch) { if (!disputeForms[gameMatch.id]) disputeForms[gameMatch.id] = { open: false, reason: '', evidence: null }; return disputeForms[gameMatch.id];
}function disputeResult(gameMatch) { disputeForms[gameMatch.id] = { open: true, reason: '', evidence: null }; actionError.value = '';
}function cancelDispute(gameMatch) { disputeForms[gameMatch.id] = { open: false, reason: '', evidence: null };
}async function submitDispute(gameMatch) { const form = disputeForm(gameMatch); if (!form.evidence) { actionError.value = 'Attach a screenshot as evidence for the dispute.'; return; } isBusy.value = true; actionError.value = ''; try { const payload = new FormData(); payload.append('reason', form.reason); payload.append('evidence', form.evidence); await upload(`/matches/${gameMatch.id}/dispute`, payload); cancelDispute(gameMatch); notify('Dispute and screenshot evidence sent to the organizer'); await reloadBracket(); } catch (error) { actionError.value = error.message; } finally { isBusy.value = false; }
}async function viewDisputeEvidence(gameMatch) { const dispute = [...(gameMatch.disputes || [])].reverse().find(item => item.evidence_name); if (!dispute) { actionError.value = 'No screenshot evidence is attached to this dispute.'; return; } try { const response = await fetch(`/api/v1/matches/${gameMatch.id}/disputes/${dispute.id}/evidence`, { headers: { Authorization: `Bearer ${getToken()}` } }); if (!response.ok) throw new Error('Could not load the dispute screenshot.'); if (disputeEvidencePreviews[gameMatch.id]) URL.revokeObjectURL(disputeEvidencePreviews[gameMatch.id]); disputeEvidencePreviews[gameMatch.id] = URL.createObjectURL(await response.blob()); } catch (error) { actionError.value = error.message; }
}async function resolveDispute(gameMatch) {  const values = resultForm(gameMatch);
   try {    await api(`/matches/${gameMatch.id}/resolve-dispute`, { method: 'POST', body: { score_a: Number(values.score_a), score_b: Number(values.score_b), outcome: values.outcome, resolution: values.resolution } });
    notify('Dispute resolved and result recorded');
 await reloadBracket();
  } catch (error) { actionError.value = error.message;
}}function startResultCorrection(gameMatch) { editingResultIds[gameMatch.id] = true; const form = resultForm(gameMatch); form.score_a = Number(gameMatch.score_a); form.score_b = Number(gameMatch.score_b); form.reason = ''; }async function correctMatchResult(gameMatch) { const values = resultForm(gameMatch); try { await api(`/matches/${gameMatch.id}/result`, { method: 'PATCH', body: { score_a: Number(values.score_a), score_b: Number(values.score_b), reason: values.reason } }); editingResultIds[gameMatch.id] = false; notify('Confirmed result corrected and audit history saved'); await reloadBracket(); } catch (error) { actionError.value = error.message; }}async function joinTeamByCode(code = null) {  if (!requireAuth()) return;
  isBusy.value = true;
 actionError.value = '';
  try {    const response = await api('/teams/join-by-code', { method: 'POST', body: { code } });
    await refreshTeams();
    teamIdInput.value = response.team.id;
 notify(`Joined ${response.team.name}. Team ID: ${response.team.id}`);
  } catch (error) { actionError.value = error.message;
 }  finally { isBusy.value = false;
 }}const visibleTournaments = computed(() => tournaments.value.filter(t => `${t.name} ${t.game} ${t.status}`.toLowerCase().includes(searchQuery.value.toLowerCase())));
async function loadLiveMatches() { if (!getToken()) { liveMatches.value = []; return; } liveMatchesLoading.value = true; actionError.value = ''; try { const response = await api('/my/matches'); liveMatches.value = response.data || []; } catch (error) { actionError.value = error.message; } finally { liveMatchesLoading.value = false; } }
async function saveMatchSchedule(matchId, value) { isBusy.value = true; actionError.value = ''; try { await api(`/matches/${matchId}/schedule`, { method: 'PATCH', body: { scheduled_at: new Date(value).toISOString() } }); notify('Match time saved'); await loadLiveMatches(); } catch (error) { actionError.value = error.message; } finally { isBusy.value = false; } }
async function setMatchReady(matchId, ready) { try { await api(`/matches/${matchId}/ready`, { method: 'POST', body: { ready } }); await loadLiveMatches(); } catch (error) { actionError.value = error.message; } }
async function openTournamentFromMatch(id) { const row = tournaments.value.find(item => Number(item.id) === Number(id)); if (row) await openTournament(row); else { await refreshTournaments(); const refreshed = tournaments.value.find(item => Number(item.id) === Number(id)); if (refreshed) await openTournament(refreshed); } }
function navigate(label) { activeNav.value = label; if (label === 'Live matches') loadLiveMatches();
 window.scrollTo({ top: 0, behavior: 'smooth' });
}async function reloadBracket() {  const result = await api(`/tournaments/${selectedTournament.value.id}/bracket`);
 tournamentMatches.value = result.data || [];
 if (selectedTournament.value.formatCode === 'round_robin') { const standings = await api(`/tournaments/${selectedTournament.value.id}/standings`); tournamentStandings.value = standings.data || []; }
 await refreshSelected();
}async function saveMatchStats(payload) { if (!selectedMatchDetail.value) return; const id = selectedMatchDetail.value.id; isBusy.value = true; actionError.value = ''; try { await api(`/matches/${id}/stats`, { method: 'PATCH', body: { game_stats: payload } }); await reloadBracket(); selectedMatchDetail.value = tournamentMatches.value.find(row => Number(row.id) === Number(id)) || null; notify('Game stats saved and analytics updated'); } catch (error) { actionError.value = error.message; } finally { isBusy.value = false; }
}async function extractMlbbScreenshot(file) { if (!selectedMatchDetail.value || !file) return; extractingMlbbOcr.value = true; mlbbOcrError.value = ''; mlbbOcrResult.value = null; try { const form = new FormData(); form.append('screenshot', file); const response = await upload(`/matches/${selectedMatchDetail.value.id}/mlbb-screenshot-extract`, form); mlbbOcrResult.value = response.data; notify('Screenshot read. Review the extracted draft before saving.'); } catch (error) { mlbbOcrError.value = error.message; } finally { extractingMlbbOcr.value = false; }
}onMounted(async () => {  checkInClockTimer = window.setInterval(() => { checkInClock.value = Date.now(); }, 30000); const query = new URLSearchParams(window.location.search);
 if (query.get('demo_player') === '1') {
   try {
     const preview = await api('/demo-data/player-session', { method: 'POST', body: { match_id: Number(query.get('demo_mlbb_match')), side: query.get('demo_mlbb_side') } });
     setSessionToken(preview.data.token);
     demoPlayerPreview.value = true;
     demoPlayerTeamInvite.value = preview.data.team_invite_code || '';
   } catch (error) { actionError.value = `Could not open the demo player session: ${error.message}`; }
 }
 if (getToken()) {    try { const result = await api('/auth/me');
 authUser.value = result.user;
 if (authUser.value.must_change_password) { navigateRoute('/admin/password', true); return; }
 if (authUser.value.is_platform_admin) return;
 }    catch { setToken(null);
 navigateRoute('/login', true);
 return;
 }  }  await Promise.all([refreshTournaments(), refreshTeams()]);
 const demoQuery = new URLSearchParams(window.location.search);
  if (demoQuery.get('demo_live_matches') === '1') {
    activeNav.value = 'Live matches';
    await loadLiveMatches();
    window.history.replaceState({}, '', window.location.pathname);
  }
  if (demoQuery.get('analytics_demo')) activeNav.value = 'Analytics';
 const demoTournamentId = demoQuery.get('demo_tournament') || demoQuery.get('demo_mlbb_tournament');
  const demoMatchId = demoQuery.get('demo_match') || demoQuery.get('demo_mlbb_match');
 if (demoTournamentId) {
   const demoTournament = tournaments.value.find(row => Number(row.id) === Number(demoTournamentId));
   if (demoTournament) {
     await openTournament(demoTournament);
     tournamentTab.value = 'matches';
     if (demoMatchId && demoQuery.get('demo_player') !== '1') selectedMatchDetail.value = tournamentMatches.value.find(row => Number(row.id) === Number(demoMatchId)) || null;
   }
   window.history.replaceState({}, '', window.location.pathname);
 }
  if (demoQuery.get('demo_player') === '1') {
    showTournament.value = false;
    activeNav.value = 'Live matches';
    await loadLiveMatches();
  }
});
onUnmounted(() => { if (checkInClockTimer) window.clearInterval(checkInClockTimer); Object.values(disputeEvidencePreviews).forEach(url => URL.revokeObjectURL(url)); });
</script><template>
<AdminWorkspacePage v-if="authUser?.is_platform_admin" :user="authUser"/><div v-else class="shell" @click="accountMenuOpen=false">
  <aside class="sidebar"><div class="brand"><div class="brand-mark"><Swords :size="19"/></div><span>bracket<span class="brand-dot">.</span></span></div><div class="nav-label">WORKSPACE</div><nav><button v-for="item in visibleNav" :key="item.label" class="nav-item" :class="{selected:activeNav===item.label}" @click="navigate(item.label)"><component :is="item.icon" :size="17"/><span>{{item.label}}</span></button></nav><div class="sidebar-bottom"><button class="profile-switch" @click="authUser?navigate('My profile'):navigateRoute('/login')"><div class="user-avatar"><img v-if="authUser?.avatar_url" :src="authUser.avatar_url" alt=""/><span v-else>{{authUser?.name?.slice(0,2).toUpperCase()||'JM'}}</span></div><div class="workspace-copy"><b>{{authUser?.name||'Sign in'}}</b><small>{{authUser?'View profile & account':'Player / organizer account'}}</small></div><ChevronRight v-if="authUser" :size="14" class="profile-switch-arrow"/></button></div></aside>
  <main class="main"><header class="topbar"><div class="breadcrumb">Workspace <ChevronRight :size="13"/><b>{{activeNav}}</b></div><div class="top-actions"><button v-if="authUser" class="demo-nav-button" @click="navigateRoute('/demo-data')"><Database :size="15"/> Demo data</button><div v-if="authUser" class="account-menu-wrap" @click.stop><button class="user-avatar small account-trigger" :aria-expanded="accountMenuOpen" aria-label="Open account menu" title="Account menu" @click="accountMenuOpen=!accountMenuOpen"><img v-if="authUser.avatar_url" :src="authUser.avatar_url" alt=""/><span v-else>{{authUser.name.slice(0,2).toUpperCase()}}</span></button><div v-if="accountMenuOpen" class="account-menu"><div class="account-menu-user"><b>{{authUser.name}}</b><small>{{authUser.account_type==='organizer'?'Tournament organizer':'Player account'}}</small></div><button @click="navigate('My profile');accountMenuOpen=false">My profile</button><button @click="navigate('Settings');accountMenuOpen=false">Settings</button><button class="account-signout" @click="signOut();accountMenuOpen=false">Sign out</button></div></div><button v-else class="sign-in-button" @click="navigateRoute('/login')">Sign in</button></div></header>
  <div class="content"><section v-if="demoPlayerPreview" class="demo-player-banner"><div><b>Player preview · {{ authUser?.name }}</b><small>Actions here use a temporary player session; your organizer tab stays signed in.</small><div v-if="demoPlayerTeamInvite" class="demo-player-invite"><span>Practice team invite</span><code>{{ demoPlayerTeamInvite }}</code><button class="text-button" @click="copyDemoTeamInvite">Copy code</button></div></div><button class="secondary-button" @click="activeNav='Teams & players'">Go to teams</button></section><SettingsPage v-if="activeNav==='Settings'" :theme="theme" @set-theme="setTheme"/><ProfilePage v-else-if="activeNav==='My profile'" :user="authUser" :busy="isBusy" :error="actionError" @save="saveProfile" @upload-avatar="uploadProfileAvatar" @delete-avatar="removeProfileAvatar"/><OverviewPage v-else-if="activeNav==='Overview'" :greeting="greeting" :tournaments="tournaments" :can-create="isOrganizerAccount" @create="navigateRoute('/tournaments/create')" @navigate="navigate" @open="openTournament"/><TournamentsPage v-else-if="activeNav==='Tournaments'" :tournaments="tournaments" :can-create="isOrganizerAccount" @create="navigateRoute('/tournaments/create')" @open="openTournament" @refresh="refreshTournaments"/><TeamsPage v-else-if="activeNav==='Teams & players'" :teams="teams" :invite-codes="teamInviteCodes" :busy="isBusy" :error="actionError" @create="createTeam" @join="joinTeamByCode" @invite="inviteTeam" @leave="leaveTeam" @refresh="refreshTeams"/><LiveMatchesPage v-else-if="activeNav==='Live matches'" :matches="liveMatches" :loading="liveMatchesLoading" :error="actionError" @browse="navigate('Tournaments')" @refresh="loadLiveMatches" @schedule="saveMatchSchedule" @ready="setMatchReady" @open-tournament="openTournamentFromMatch"/><AnalyticsPage v-else-if="activeNav==='Analytics'" :show-organizer-analytics="isOrganizerAccount"/><AdminGameAssetsPage v-else-if="activeNav==='Game artwork'"/></div></main>
  <Transition><div v-if="toast" class="toast"><CheckCircle2 :size="17"/>{{toast}}</div></Transition>
  <div v-if="showTournament&&selectedTournament" class="modal-backdrop" @click.self="showTournament=false"><div class="modal tournament-modal"><div class="modal-head"><div><span class="eyebrow">{{selectedTournament.publicId}} · {{selectedTournament.game}}</span><h2>{{selectedTournament.name}}</h2></div><button class="icon-button" @click="showTournament=false"><X :size="18"/></button></div><div class="event-meta"><span>{{selectedTournament.status}}</span><span>{{selectedTournament.format}}</span><span>{{selectedTournament.team_size===1?'1v1':'5v5'}}</span><span>BO{{selectedTournament.best_of}}</span><span>{{selectedTournament.entries_count||0}} / {{selectedTournament.capacity||'∞'}}</span></div><div v-if="actionError" class="form-error">{{actionError}}</div><nav class="tournament-tabs" aria-label="Tournament sections"><button :class="{active:tournamentTab==='overview'}" @click="tournamentTab='overview'">Overview</button><button v-if="isOrganizer" :class="{active:tournamentTab==='entrants'}" @click="tournamentTab='entrants'">Entrants <small>{{selectedTournament.entries_count||tournamentEntries.length||0}}</small></button><button v-else :class="{active:tournamentTab==='matches'}" @click="tournamentTab='matches'">{{myEntryId?'My matches':'Matches'}} <small>{{displayedTournamentMatches.length}}</small></button><button :class="{active:tournamentTab==='bracket'}" @click="tournamentTab='bracket'">Bracket &amp; standings <small>{{tournamentMatches.length}}</small></button><button v-if="isOrganizer" :class="{active:tournamentTab==='matches'}" @click="tournamentTab='matches'">Matches <small>{{tournamentMatches.length}}</small></button></nav><div v-if="tournamentTab==='overview'" class="tournament-overview panel"><div><small>FORMAT</small><b>{{selectedTournament.format}}</b></div><div><small>ENTRANTS</small><b>{{selectedTournament.entries_count||0}} / {{selectedTournament.capacity||'∞'}}</b></div><div><small>GAME MODE</small><b>{{selectedTournament.team_size===1?'1v1':'5v5'}} · BO{{selectedTournament.best_of}}</b></div><div><small>ROUNDS / MATCHES</small><b>{{tournamentMatches.length?`${tournamentMatches.length} scheduled`:'Not published yet'}}</b></div></div><section v-if="tournamentTab==='overview'&&!isOrganizer" class="player-tournament-card panel"><div class="player-status-icon">{{myEntryId?'IN':'—'}}</div><div><b>{{myEntryId?'You are entered':'Player view'}}</b><p>{{myEntryId?`${displayedTournamentMatches.length} of your matches are listed under My matches.`:selectedTournament.statusCode==='registration_open'?'Register to join this tournament and get your own match list.':'Follow the bracket and confirmed results here.'}}</p></div><button v-if="myEntryId" class="secondary-button" @click="tournamentTab='matches'">Go to my matches</button></section>
  <div v-if="tournamentTab==='overview'&&isOrganizer" class="organizer-actions"><button v-if="selectedTournament.statusCode==='draft'" class="primary-button" :disabled="isBusy" @click="openRegistration">Open registration</button><button v-if="selectedTournament.statusCode==='registration_open'" class="primary-button" :disabled="isBusy||tournamentEntries.filter(e=>e.checked_in_at).length<2" @click="publishTournament">Publish bracket</button><button v-if="selectedTournament.statusCode==='registration_open'" class="secondary-button" :disabled="isBusy||tournamentEntries.length<2" @click="autoSeedTournament">Auto-seed by ELO</button><button v-if="selectedTournament.statusCode==='registration_open'" class="secondary-button" @click="refreshEntries">Refresh entrants</button><label v-if="['draft','registration_open'].includes(selectedTournament.statusCode)&&selectedTournament.formatCode!=='round_robin'" class="reseeding-select"><span>Round reseeding</span><select :value="selectedTournament.seedingStrategy" :disabled="isBusy" @change="updateSeedingStrategy($event.target.value)"><option value="fixed">Fixed bracket</option><option value="random_each_round">Randomize every round</option><option value="high_vs_low">Higher ELO vs lower ELO</option><option value="similar_elo">Closest ELO matchups</option></select><small>Controls how entrants are paired after each completed round.</small></label><span v-if="selectedTournament.statusCode==='registration_open'">Checked in {{tournamentEntries.filter(e=>e.checked_in_at).length}} / {{tournamentEntries.length}}</span><label v-if="['draft','registration_open'].includes(selectedTournament.statusCode)" class="organizer-toggle"><input type="checkbox" :checked="selectedTournament.allowOrganizerEntry" :disabled="isBusy" @change="toggleOrganizerParticipation($event.target.checked)"/><span><b>Allow organizer to compete</b><small>Join this event with your player account or an eligible team.</small></span></label></div>
  <section v-if="tournamentTab==='entrants'&&isOrganizer&&tournamentEntries.length" class="detail-section"><h3>Entrants <small>{{tournamentEntries.length}}</small></h3><p class="section-note">ELO is separate for each game. New entrants start at 1000; edit seeds manually at any time before publishing.</p><div v-for="entry in tournamentEntries" :key="entry.id" class="entry-row"><span>{{entry.team?.name||entry.user?.name||'Entrant'}}<small class="entrant-elo">{{entry.elo_rating??1000}} ELO · {{entry.elo_matches??0}} rated matches</small></span><label class="seed-control">Seed<input :value="entry.seed||''" type="number" min="1" :max="selectedTournament.capacity||512" placeholder="Auto" @change="updateSeed(entry,$event.target.value)"/></label><span :class="entry.checked_in_at?'checkin-ok':'checkin-wait'">{{entry.checked_in_at?'Checked in':'Not checked in'}}</span></div></section>
  <div v-if="tournamentTab==='overview'&&isOrganizer&&selectedTournament.statusCode==='registration_open'&&!selectedTournament.allowOrganizerEntry&&!myEntryId" class="organizer-entry-note">Organizer entry is currently disabled. Turn it on above if you want to play.</div><section v-if="tournamentTab==='overview'&&selectedTournament.statusCode==='registration_open'&&(!isOrganizer||selectedTournament.allowOrganizerEntry||myEntryId)" class="registration-box"><b>{{isOrganizer?'Join your tournament':'Join this tournament'}}</b><p>{{selectedTournament.team_size===1?'Register and check in before the bracket is published.':'Choose a complete game-matched roster. Your team needs at least five members.'}}</p><template v-if="selectedTournament.team_size===5"><label>Your team<select v-model="teamIdInput"><option value="">Choose an eligible team</option><option v-for="team in gameTeamsForTournament" :key="team.id" :value="team.id" :disabled="Number(team.members_count||0)<5">{{team.name}} · {{team.members_count}} members{{Number(team.members_count||0)<5?' · roster incomplete':''}}</option></select></label><div v-if="!fullTeamsForTournament.length" class="roster-needed-note">{{gameTeamsForTournament.length?'Your roster is not full yet. Invite teammates from Teams & players.':'You do not have a team for this game yet.'}}</div><button v-if="!fullTeamsForTournament.length" class="secondary-button" @click="activeNav='Teams & players';showTournament=false">Manage teams and invites</button></template><button v-if="!myEntryId" class="primary-button" :disabled="isBusy||(selectedTournament.team_size===5&&(!teamIdInput||!fullTeamsForTournament.some(team=>Number(team.id)===Number(teamIdInput))))" @click="registerForTournament">{{selectedTournament.team_size===5?'Register team':'Register'}}</button><template v-else><span :class="myEntryCheckedInAt?'checkin-ok':'checkin-wait'">{{myEntryCheckedInAt?'Checked in':'Registered · check-in required'}}</span><small v-if="!myEntryCheckedInAt" class="checkin-window-note">{{checkInAvailability.message}}</small><button v-if="!myEntryCheckedInAt" class="primary-button" :disabled="isBusy||!checkInAvailability.allowed" @click="checkIn">Check in</button><button class="secondary-button" :disabled="isBusy" @click="withdrawEntry">Withdraw</button></template></section>
  <div v-if="tournamentTab==='entrants'&&isOrganizer&&!tournamentEntries.length" class="empty-state">No entrants have registered yet.</div><section v-if="tournamentTab==='bracket'" class="tournament-tab-content"><StandingsTable v-if="selectedTournament.formatCode === 'round_robin' && tournamentMatches.length" :rows="tournamentStandings"/><SingleEliminationBracket v-if="selectedTournament.formatCode==='single_elimination'&&tournamentMatches.length" :matches="tournamentMatches" :seeding-strategy="selectedTournament.seedingStrategy"/><DoubleEliminationBracket v-if="selectedTournament.formatCode==='double_elimination'&&tournamentMatches.length" :matches="tournamentMatches"/></section><section v-if="tournamentTab==='matches'" class="detail-section"><h3>{{!isOrganizer&&myEntryId?'Your matches':selectedTournament.formatCode==='round_robin'?'Round-robin schedule':'Bracket and matches'}} <small>{{tournamentMatches.length}}</small></h3><div v-if="!tournamentMatches.length" class="empty-state">The bracket or schedule appears after the organizer publishes.</div><div v-for="gameMatch in displayedTournamentMatches" :key="gameMatch.id" class="match-row"><div class="match-row-top"><b>{{matchHeading(gameMatch)}}</b><span class="match-status" :class="gameMatch.status">{{gameMatch.status.replace('_',' ')}}</span></div><div class="match-players"><span>{{gameMatch.entrant_a?.team?.name||gameMatch.entrant_a?.user?.name||'TBD'}}</span><b>{{gameMatch.score_a??'—'}} : {{gameMatch.score_b??'—'}}</b><span>{{gameMatch.entrant_b?.team?.name||gameMatch.entrant_b?.user?.name||'TBD'}}</span></div><button class="text-button match-detail-button" @click="selectedMatchDetail=gameMatch">View match details</button><div v-if="isOrganizer&&selectedTournament.gameCode!=='cs2'&&matchServers[gameMatch.id]?.provider==='demo-room'" class="demo-room-panel"><div class="demo-room-head"><span>SIMULATED MATCH ROOM</span><span class="server-state ready">ready</span></div><b>{{matchServers[gameMatch.id].map}}</b><div class="demo-room-code"><span>{{selectedTournament.gameCode==='valorant'?'Lobby code':selectedTournament.gameCode==='mlbb'?'Room ID':'Room code'}}</span><code>{{matchServers[gameMatch.id].host}}</code><button class="text-button" @click="refreshMatchServer(gameMatch)">Refresh</button></div><small>This is sample room data; it does not connect to the game.</small></div><div v-if="isOrganizer&&selectedTournament.gameCode==='cs2'&&['scheduled','result_pending'].includes(gameMatch.status)" class="server-ops"><div class="server-ops-head"><span><Server :size="15"/> Match server</span><span class="server-state" :class="matchServers[gameMatch.id]?.status||'none'">{{matchServers[gameMatch.id]?.status||'not provisioned'}}</span></div><div v-if="matchServers[gameMatch.id]?.status==='ready'" class="server-ready-box"><b v-if="matchServers[gameMatch.id].provider==='demo'">Demo placeholder · no live server</b><code>connect {{matchServers[gameMatch.id].host}}:{{matchServers[gameMatch.id].port}}</code><button class="text-button" @click="copyServerConnect(gameMatch)"><Copy :size="13"/> Copy command</button></div><div v-if="!matchServers[gameMatch.id]||matchServers[gameMatch.id]?.status==='failed'" class="server-setup"><label>Region<select v-model="serverForm(gameMatch).region"><option v-for="region in cs2Regions" :key="region" :value="region">{{region.replace('-', ' ')}}</option></select></label><label>Map<select v-model="serverForm(gameMatch).map"><option v-for="map in cs2Maps" :key="map" :value="map">{{map.replace('de_', '')}}</option></select></label><button class="secondary-button" :disabled="isBusy" @click="provisionMatchServer(gameMatch)">{{matchServers[gameMatch.id]?.status==='failed'?'Retry provisioning':'Provision server'}}</button></div><div v-else-if="matchServers[gameMatch.id]?.status==='provisioning'" class="server-pending-box"><span>Starting your private-LAN CS2 server.</span><button class="text-button" @click="refreshMatchServer(gameMatch)">Refresh status</button></div><button v-if="matchServers[gameMatch.id]?.status==='ready'" class="text-button server-refresh" @click="refreshMatchServer(gameMatch)">Refresh server details</button></div><form v-if="gameMatch.status==='scheduled'&&authUser&&!isOrganizer&&ownsMatchEntrant(gameMatch)" class="result-form" @submit.prevent="submitResult(gameMatch)"><label>Score A<input v-model.number="resultForm(gameMatch).score_a" type="number" min="0" :max="Math.floor(gameMatch.best_of/2)+1"/></label><label>Score B<input v-model.number="resultForm(gameMatch).score_b" type="number" min="0" :max="Math.floor(gameMatch.best_of/2)+1"/></label><button class="secondary-button">Report score</button></form><form v-if="isOrganizer&&(gameMatch.status==='scheduled'||(gameMatch.status==='result_pending'&&Number(gameMatch.result_reporter_id)===Number(authUser.id)))" class="result-form organizer-result-form" @submit.prevent="submitResult(gameMatch)"><label>Score A<input v-model.number="resultForm(gameMatch).score_a" type="number" min="0" :max="Math.floor(gameMatch.best_of/2)+1"/></label><label>Score B<input v-model.number="resultForm(gameMatch).score_b" type="number" min="0" :max="Math.floor(gameMatch.best_of/2)+1"/></label><button class="secondary-button">{{gameMatch.status==='result_pending'&&Number(gameMatch.result_reporter_id)===Number(authUser.id)?'Update pending score':'Record score'}}</button></form><div v-if="canRespondToResult(gameMatch)||canOrganizerConfirmResult(gameMatch)" class="match-actions"><button class="secondary-button" @click="confirmResult(gameMatch)">Confirm score</button><button v-if="canRespondToResult(gameMatch)" class="text-button" @click="disputeResult(gameMatch)">Dispute</button></div><form v-if="disputeForms[gameMatch.id]?.open" class="dispute-report-form" @submit.prevent="submitDispute(gameMatch)"><label>Reason for dispute<textarea v-model="disputeForm(gameMatch).reason" minlength="8" maxlength="2000" required placeholder="Explain why you believe the reported score is incorrect"/></label><label>Screenshot evidence<input type="file" accept="image/png,image/jpeg,image/webp" required @change="disputeForm(gameMatch).evidence=$event.target.files?.[0]||null"/><small>Attach JPG, PNG, or WebP · max 5 MB</small></label><div class="dispute-report-actions"><button class="primary-button" :disabled="isBusy">Submit dispute</button><button type="button" class="text-button" @click="cancelDispute(gameMatch)">Cancel</button></div></form><div v-if="isOrganizer&&gameMatch.status==='disputed'&&gameMatch.disputes?.length" class="dispute-evidence-card"><b>Dispute evidence</b><p>{{ gameMatch.disputes[gameMatch.disputes.length-1].reason }}</p><button type="button" class="text-button" @click="viewDisputeEvidence(gameMatch)">View screenshot proof · {{ gameMatch.disputes[gameMatch.disputes.length-1].evidence_name || '' }}</button><img v-if="disputeEvidencePreviews[gameMatch.id]" :src="disputeEvidencePreviews[gameMatch.id]" alt="Screenshot attached as dispute evidence"/></div><form v-if="isOrganizer&&gameMatch.status==='disputed'" class="result-form" @submit.prevent="resolveDispute(gameMatch)"><label>Score A<input v-model.number="resultForm(gameMatch).score_a" type="number" min="0"/></label><label>Score B<input v-model.number="resultForm(gameMatch).score_b" type="number" min="0"/></label><label>Dispute outcome<select v-model="resultForm(gameMatch).outcome" required><option value="valid">Valid · dispute is upheld</option><option value="invalid">Invalid · dispute is rejected</option></select></label><label>Resolution<input v-model="resultForm(gameMatch).resolution" minlength="8" required/></label><button class="primary-button">Resolve dispute</button></form><div v-if="gameMatch.status==='completed'&&gameMatch.disputes?.some(dispute=>dispute.outcome)" class="dispute-resolution-summary"><b>Dispute decision:</b> {{ gameMatch.disputes.find(dispute=>dispute.outcome)?.outcome }} · {{ gameMatch.disputes.find(dispute=>dispute.outcome)?.resolution }}</div><div v-if="isOrganizer&&gameMatch.status==='completed'" class="result-correction"><button v-if="!editingResultIds[gameMatch.id]" class="text-button" @click="startResultCorrection(gameMatch)">Correct confirmed result</button><form v-else class="result-form correction-form" @submit.prevent="correctMatchResult(gameMatch)"><label>Score A<input v-model.number="resultForm(gameMatch).score_a" type="number" min="0" :max="Math.floor(gameMatch.best_of/2)+1" required/></label><label>Score B<input v-model.number="resultForm(gameMatch).score_b" type="number" min="0" :max="Math.floor(gameMatch.best_of/2)+1" required/></label><label class="correction-reason">Correction reason<textarea v-model="resultForm(gameMatch).reason" minlength="8" maxlength="2000" required placeholder="Explain why this confirmed score needs correction"/></label><button class="primary-button">Save correction</button><button type="button" class="text-button" @click="editingResultIds[gameMatch.id]=false">Cancel</button></form></div><div v-if="gameMatch.status==='bye'" class="bye-note">Advances by bye</div></div></section></div></div>
<MatchDetailsModal v-if="selectedMatchDetail&&showTournament" :match="selectedMatchDetail" :game="selectedTournament.gameCode" :team-size="Number(selectedTournament.team_size)" :can-edit-stats="isOrganizer" :can-stop-server="isOrganizer" :can-host-mlbb-room="isOrganizer&&selectedTournament.gameCode==='mlbb'&&['scheduled','result_pending','disputed'].includes(selectedMatchDetail.status)" :can-preview-player="isOrganizer&&(selectedTournament.settings?.demo_mlbb_emulator===true||selectedTournament.settings?.demo_player_result_reporting===true||selectedTournament.settings?.demo_match_operations===true)&&selectedMatchDetail.entrant_a&&selectedMatchDetail.entrant_b" :room-code="selectedMatchDetail.mlbb_room_code" :can-edit-room-code="isOrganizer&&selectedTournament.gameCode==='mlbb'&&['scheduled','result_pending','disputed'].includes(selectedMatchDetail.status)" :opening-room="openingMlbbRoom" :room-error="mlbbRoomError" :ocr-result="mlbbOcrResult" :extracting-ocr="extractingMlbbOcr" :ocr-error="mlbbOcrError" :saving="isBusy" @save-stats="saveMatchStats" @save-room-code="saveMlbbRoomCode" @copy-room-code="copyMlbbRoomCode" @extract-screenshot="extractMlbbScreenshot" @stop-server="stopMatchServer(selectedMatchDetail)" @download-demo="downloadMatchDemo(selectedMatchDetail)" @open-mlbb-room="openMlbbRoom" @preview-player="openMlbbPlayerPreview" :server="matchServers[selectedMatchDetail.id]||null" @close="selectedMatchDetail=null"/>
</div>
</template>









