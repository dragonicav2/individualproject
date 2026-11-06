<script setup>
import { ref } from 'vue';
import PasswordField from './PasswordField.vue';
defineProps({ busy: Boolean, error: String });
const emit = defineEmits(['submit', 'register', 'forgot']);
const email = ref(''); const password = ref('');
const incompletePrompt = ref('');
function promptForMissingFields() { incompletePrompt.value = 'Please fill in your email and password before signing in.'; }
</script>
<template><form @submit.prevent="emit('submit',{email,password})" @invalid.capture="promptForMissingFields" @input="incompletePrompt = ''"><label>Email<input v-model="email" type="email" autocomplete="email" required/></label><PasswordField v-model="password" label="Password" autocomplete="current-password" required/><p class="auth-forgot-link"><a href="/forgot-password" @click.prevent="emit('forgot')">Forgot password?</a></p><div v-if="incompletePrompt" class="form-error" role="alert">{{ incompletePrompt }}</div><div v-if="error" class="form-error">{{ error }}</div><div class="modal-actions"><button type="button" class="secondary-button" @click="emit('register')">Create account</button><button class="primary-button" :disabled="busy">{{ busy ? 'Please wait…' : 'Sign in' }}</button></div></form></template>
