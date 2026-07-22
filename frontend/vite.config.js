import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// NOTE: base is '/' because this app is deployed at the site root
// (http://77.37.87.189, or your domain root). If you ever move it into a
// subfolder, change this to match AND update .htaccess's RewriteBase together.
export default defineConfig({
  base: '/',
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
