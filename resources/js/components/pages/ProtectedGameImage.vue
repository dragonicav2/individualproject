<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { getToken } from '../../api';

const props = defineProps({ src: { type: String, default: '' }, alt: { type: String, default: '' }, fallback: { type: String, default: '' }, label: { type: String, default: '' } });
const objectUrl = ref('');
let controller = null;
function clearImage() {
  if (objectUrl.value) URL.revokeObjectURL(objectUrl.value);
  objectUrl.value = '';
}
watch(() => props.src, async (src) => {
  controller?.abort();
  clearImage();
  if (!src) return;
  controller = new AbortController();
  try {
    const token = getToken();
    const response = await fetch(src, { headers: token ? { Authorization: `Bearer ${token}` } : {}, cache: 'no-store', signal: controller.signal });
    if (!response.ok) return;
    objectUrl.value = URL.createObjectURL(await response.blob());
  } catch (error) {
    if (error.name !== 'AbortError') console.warn('Could not load protected game artwork.');
  }
}, { immediate: true });
onBeforeUnmount(() => { controller?.abort(); clearImage(); });
</script>

<template>
  <span class="protected-game-image" :class="{ 'has-image': !!objectUrl }">
    <img v-if="objectUrl" :src="objectUrl" :alt="alt"/>
    <span v-else class="protected-game-image-fallback">{{ fallback }}</span>
    <small v-if="label">{{ label }}</small>
  </span>
</template>