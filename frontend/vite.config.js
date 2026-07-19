import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// NOTE: base is set to '/fdert/' to match the existing .htaccess RewriteBase
// used when this app is deployed in a subfolder on the Hostinger VPS.
// Change both this and .htaccess's RewriteBase together if you deploy to a
// different path (or to '/' if it becomes the site root).
export default defineConfig({
  base: '/fdert/',
  plugins: [react()],
  server: {
    proxy: {
      // During local development, proxy API calls to your real PHP backend
      // so cookies/sessions work without CORS headaches. Point this at your
      // staging server, e.g. http://localhost:8080 if running PHP locally
      // (php -S localhost:8080 -t deploy/), or your VPS staging URL.
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
  },
});
