// API + PHP app URL configuration.
//
// Dev (npm run dev): requests to /api/* are proxied by Vite to the PHP
// project on Apache (see vite.config.js). PHP_APP_BASE is used for links
// and post-login redirects that leave the React app (dashboards, app
// download) — cookie scope ignores ports, so the PHP session stays valid.
//
// Production: set VITE_API_BASE / VITE_PHP_APP_BASE at build time, or
// serve the build output from inside the PHP project folder so the
// relative defaults work.
export const API_BASE = import.meta.env.VITE_API_BASE || '/api';
export const PHP_APP_BASE =
  import.meta.env.VITE_PHP_APP_BASE || 'http://localhost/Advance_Database-System';

export const apiUrl = (path) => `${API_BASE}/${path}`;
export const phpUrl = (path) => (PHP_APP_BASE ? `${PHP_APP_BASE}/${path}` : `/${path}`);
