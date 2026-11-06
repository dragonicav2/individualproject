import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
export default defineConfig({ plugins: [laravel({ input: ['resources/js/app.js'], refresh: true }), vue()], server: { host: '127.0.0.1', port: 5173, strictPort: true, hmr: { host: '127.0.0.1' } } });
