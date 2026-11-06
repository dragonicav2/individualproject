<script setup>
import { ref, computed } from 'vue';
import PasswordField from './PasswordField.vue';

const props = defineProps({ busy: Boolean, error: String });
const emit = defineEmits(['submit', 'login']);
const gameOptions = [
  { code: 'cs2', label: 'Counter-Strike 2' },
  { code: 'valorant', label: 'VALORANT' },
  { code: 'sf6', label: 'Street Fighter 6' },
  { code: 'mlbb', label: 'Mobile Legends' },
];
const name = ref('');
const email = ref('');
const accountType = ref('player');
const gameInterests = ref([]);
const password = ref('');
const passwordConfirmation = ref('');
const localError = ref('');
const incompletePrompt = ref('');
const visibleError = computed(() => localError.value || props.error);
const passwordChecks = computed(() => {
  const clean = (value) => value.toLowerCase().replace(/[^a-z0-9]/g, '');
  const cleanPassword = clean(password.value);
  const cleanName = clean(name.value);
  const nameParts = name.value.toLowerCase().split(/[^a-z0-9]+/).filter(part => part.length >= 4);
  return {
    length: password.value.length >= 8,
    letters: /[A-Za-z]/.test(password.value) && /[0-9]/.test(password.value),
    special: /[^A-Za-z0-9]/.test(password.value),
    name: !password.value || !((cleanName.length >= 3 && cleanPassword.includes(cleanName)) || nameParts.some(part => cleanPassword.includes(part))),
  };
});

function submit() {
  localError.value = '';
  if (accountType.value === 'player' && gameInterests.value.length === 0) {
    localError.value = 'Choose at least one game you are interested in.';
    return;
  }
  emit('submit', {
    name: name.value,
    email: email.value,
    account_type: accountType.value,
    game_interests: accountType.value === 'player' ? gameInterests.value : [],
    password: password.value,
    password_confirmation: passwordConfirmation.value,
  });
}
function promptForMissingFields() {
  incompletePrompt.value = 'Please complete the required account details before creating your account.';
}
</script>

<template>
  <form @submit.prevent="submit" @invalid.capture="promptForMissingFields" @input="incompletePrompt = ''">
    <label>Display name<input v-model="name" autocomplete="name" maxlength="80" required/></label>
    <fieldset class="account-type-picker">
      <legend>I’m joining as</legend>
      <label class="account-type-option" :class="{ selected: accountType==='player' }">
        <input v-model="accountType" type="radio" value="player"/>
        <span><b>Player</b><small>Join teams and tournaments, manage your profile, and report match results.</small></span>
      </label>
      <label class="account-type-option" :class="{ selected: accountType==='organizer' }">
        <input v-model="accountType" type="radio" value="organizer"/>
        <span><b>Tournament organizer</b><small>Create tournaments, manage entrants, run matches, and review results.</small></span>
      </label>
    </fieldset>

    <fieldset v-if="accountType==='player'" class="registration-game-picker">
      <legend>Which games are you interested in?</legend>
      <p>Choose one or more. You can add or remove games later from your profile.</p>
      <div class="registration-game-options">
        <label v-for="game in gameOptions" :key="game.code" :class="{ selected: gameInterests.includes(game.code) }">
          <input v-model="gameInterests" type="checkbox" :value="game.code"/>
          <span>{{ game.label }}</span>
        </label>
      </div>
      <small class="registration-game-hint">Players can select multiple games.</small>
    </fieldset>

    <label>Email<input v-model="email" type="email" autocomplete="email" required/></label>
    <PasswordField v-model="password" label="Password" autocomplete="new-password" minlength="8" required/>
    <small class="password-policy">Use 8+ characters with a letter, number, and special character. Don’t include your display name.</small>
    <ul class="password-checks"><li :class="{ met: passwordChecks.length }">At least 8 characters</li><li :class="{ met: passwordChecks.letters }">A letter and a number</li><li :class="{ met: passwordChecks.special }">A special character</li><li :class="{ met: passwordChecks.name }">Does not contain your display name</li></ul>
    <PasswordField v-model="passwordConfirmation" label="Confirm password" autocomplete="new-password" minlength="8" required/>
    <div v-if="incompletePrompt" class="form-error" role="alert">{{ incompletePrompt }}</div>
    <div v-if="visibleError" class="form-error">{{ visibleError }}</div>
    <div class="modal-actions">
      <button type="button" class="secondary-button" @click="emit('login')">I already have an account</button>
      <button class="primary-button" :disabled="busy">{{ busy ? 'Please wait…' : 'Create account' }}</button>
    </div>
  </form>
</template>
