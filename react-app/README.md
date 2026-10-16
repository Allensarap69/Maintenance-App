# Mindanao Eversure — React Landing Page

React (Vite) port of `index.php`. The PHP backend still handles auth —
the React app calls JSON endpoints under `api/`:

| Endpoint | Purpose |
|---|---|
| `api/csrf.php` (GET) | Session-bound CSRF token |
| `api/auth_status.php` (GET) | Returns logged-in role + dashboard redirect |
| `api/login.php` (POST) | urlencoded: `email`, `password`, `csrf_token` → JSON |
| `api/register.php` (POST) | urlencoded: `reg_*` fields + `csrf_token` → JSON |

## Develop

1. Start Apache/MySQL in XAMPP (the PHP project must be reachable at
   `http://localhost/Advance_Database-System`).
2. `npm install` (first time only)
3. `npm run dev` → http://localhost:5173

Vite proxies `/api/*` to the PHP project, so sessions/CSRF work
same-origin. To point at a different Apache path:

```bash
PHP_PROJECT=http://localhost/my-path npm run dev
```

## Build / deploy

`npm run build` → `dist/`. To serve the React page from Apache, copy
`dist/` contents into a folder under htdocs (e.g. `app/`) and set
`VITE_API_BASE` / `VITE_PHP_APP_BASE` at build time if paths differ —
see `src/config.js`.

## Structure

- `src/App.jsx` — page composition, modal + delay-overlay state, auth redirect
- `src/components/` — Navbar, Hero, Features, Services, HowItWorks,
  HealthSection, TrackSection, Reminders, Footer, ScrollTop, Modal,
  LoginModal, RegisterModal, DelayOverlay, BrandIcons
- `src/hooks/effects.js` — theme toggle, scroll reveal, smooth anchors
- `src/api.js` — fetch helpers (CSRF, form POST, auth status)
- `src/styles/` — copies of the project's existing CSS (landing, app,
  ui, mobile, fonts) — edit the originals under `assets/css/` and
  re-copy, or convert these into the source of truth
