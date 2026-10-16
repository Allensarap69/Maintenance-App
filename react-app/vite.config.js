import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The XAMPP/Apache location of the PHP project. The dev server proxies
// /api/* requests to <PHP_PROJECT>/api/* so the React app can call the
// PHP JSON endpoints same-origin (session cookies keep working).
// Override with PHP_PROJECT env var if your Apache path differs.
const PHP_PROJECT = process.env.PHP_PROJECT || 'http://localhost/Advance_Database-System';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: PHP_PROJECT,
        changeOrigin: true,
      },
    },
  },
});
