import { ref } from 'vue';

export const currentPath = ref(window.location.pathname);

export function navigate(path, replace = false) {
  if (replace) window.history.replaceState({}, '', path);
  else window.history.pushState({}, '', path);
  currentPath.value = new URL(path, window.location.href).pathname;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

window.addEventListener('popstate', () => {
  currentPath.value = window.location.pathname;
});
