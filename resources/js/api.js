const TOKEN_KEY = 'bracket_api_token';
const SESSION_TOKEN_KEY = 'bracket_session_api_token';
const SESSION_MODE_KEY = 'bracket_session_mode';

export function getToken() {
  return window.sessionStorage.getItem(SESSION_TOKEN_KEY) || window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
  if (window.sessionStorage.getItem(SESSION_MODE_KEY) === 'demo-player') {
    if (token) window.sessionStorage.setItem(SESSION_TOKEN_KEY, token);
    else {
      window.sessionStorage.removeItem(SESSION_TOKEN_KEY);
      window.sessionStorage.removeItem(SESSION_MODE_KEY);
    }
    return;
  }
  if (token) window.localStorage.setItem(TOKEN_KEY, token);
  else window.localStorage.removeItem(TOKEN_KEY);
}

export function setSessionToken(token, mode = 'demo-player') {
  window.sessionStorage.setItem(SESSION_MODE_KEY, mode);
  window.sessionStorage.setItem(SESSION_TOKEN_KEY, token);
}

export async function api(path, options = {}) {
  const headers = { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(options.headers || {}) };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const response = await fetch(`/api/v1${path}`, { ...options, headers, body: options.body ? JSON.stringify(options.body) : undefined });
  let payload = {};
  try { payload = await response.json(); } catch {}
  if (!response.ok) {
    const validation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
    throw new Error(validation || payload.message || `Request failed (${response.status})`);
  }
  return payload;
}

export async function upload(path, formData) {
  const headers = { Accept: 'application/json' };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const response = await fetch(`/api/v1${path}`, { method: 'POST', headers, body: formData });
  let payload = {};
  try { payload = await response.json(); } catch {}
  if (!response.ok) {
    const validation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
    throw new Error(validation || payload.message || `Upload failed (${response.status})`);
  }
  return payload;
}

export async function download(path, filename) {
  const headers = {};
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const response = await fetch(`/api/v1${path}`, { headers });
  if (!response.ok) throw new Error(`Download failed (${response.status})`);
  const objectUrl = URL.createObjectURL(await response.blob());
  const link = document.createElement('a');
  link.href = objectUrl;
  link.download = filename;
  link.click();
  setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}
