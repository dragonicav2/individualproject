<script setup>
import { ref } from 'vue';

defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  autocomplete: { type: String, default: 'current-password' },
  minlength: { type: [String, Number], default: undefined },
  required: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);

const visible = ref(false);
</script>

<template>
  <label class="password-field">
    {{ label }}
    <span class="password-field-wrap">
      <input
        :value="modelValue"
        :type="visible ? 'text' : 'password'"
        :autocomplete="autocomplete"
        :minlength="minlength"
        :required="required"
        @input="$emit('update:modelValue', $event.target.value)"
      />
      <button
        class="password-toggle"
        type="button"
        :aria-label="visible ? 'Hide password' : 'Show password'"
        :aria-pressed="visible"
        @click="visible = !visible"
      >{{ visible ? 'Hide' : 'Show' }}</button>
    </span>
  </label>
</template>
