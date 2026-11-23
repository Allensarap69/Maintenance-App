// In-browser stand-in for api/*.php so the demo runs without Apache/MySQL.
// Responses mirror the PHP endpoints' JSON shapes.
import { API_BASE } from '../config';

export const settings = { serverDown: false, latency: 700 };

export const DEMO_PASSWORD = 'Rider@123';
export const ACCOUNTS = {
  ok: 'rider@eversure.ph',
  slow: 'slow@eversure.ph',
  locked: 'locked@eversure.ph',
  crash: 'crash@eversure.ph',
  expired: 'expired@eversure.ph',
  taken: 'taken@eversure.ph',
};

const registered = new Map();
let failedAttempts = 0;
let csrfExpiredOnce = false;

const json = (body, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const fatal = () => new Response('<b>Fatal error</b>: Uncaught PDOException', { status: 500 });

const wait = (ms, signal) => new Promise((resolve, reject) => {
  const t = setTimeout(resolve, ms);
  signal?.addEventListener('abort', () => {
    clearTimeout(t);
    reject(new DOMException('Aborted', 'AbortError'));
  });
});

function login(p) {
  const email = (p.get('email') || '').trim().toLowerCase();
  if (email === ACCOUNTS.crash) return fatal();
  if (email === ACCOUNTS.expired && !csrfExpiredOnce) {
    csrfExpiredOnce = true;
    return json({ success: false, csrf_error: true, message: 'Security validation failed. Please refresh the page and try again.' });
  }
  if (email === ACCOUNTS.locked) {
    return json({
      success: false,
      message: 'Too many failed attempts. Please wait 8 seconds before trying again.',
      delay: { delay: 8, locked: false, attempts: 4, remaining: 1 },
    });
  }
  const password = registered.get(email) ?? (Object.values(ACCOUNTS).includes(email) ? DEMO_PASSWORD : null);
  if (password !== null && p.get('password') === password) {
    failedAttempts = 0;
    return json({ success: true, redirect: 'demo.html?signedin=1' });
  }
  failedAttempts += 1;
  if (failedAttempts >= 3) {
    const seconds = 5 * (failedAttempts - 2);
    return json({
      success: false,
      message: `Too many failed attempts. Please wait ${seconds} seconds before trying again.`,
      delay: { delay: seconds, locked: false, attempts: failedAttempts, remaining: Math.max(0, 5 - failedAttempts) },
    });
  }
  return json({ success: false, message: 'Invalid email or password.', delay: { delay: 0, locked: false, attempts: failedAttempts, remaining: 5 - failedAttempts } });
}

function register(p) {
  const email = (p.get('reg_email') || '').trim().toLowerCase();
  if (email === ACCOUNTS.crash) return fatal();
  if (email === ACCOUNTS.taken || email === ACCOUNTS.ok || registered.has(email)) {
    return json({ success: false, message: 'Email already registered. Please use a different email or login.' });
  }
  registered.set(email, p.get('reg_password') || '');
  return json({ success: true, message: 'Registration successful! You can now login.' });
}

export function resetMockState() {
  failedAttempts = 0;
  csrfExpiredOnce = false;
}

export function installMockApi() {
  const realFetch = window.fetch.bind(window);
  window.fetch = async (input, init = {}) => {
    const url = typeof input === 'string' ? input : input.url;
    const match = url.startsWith(`${API_BASE}/`) && url.slice(API_BASE.length).match(/^\/([a-z_]+)\.php/);
    if (!match) return realFetch(input, init);
    const endpoint = match[1];
    const params = new URLSearchParams(init.body ? String(init.body) : '');
    const quick = endpoint === 'csrf' || endpoint === 'auth_status';
    const extra = endpoint === 'login' && params.get('email')?.toLowerCase() === ACCOUNTS.slow ? 2500 : 0;
    await wait((quick ? 150 : settings.latency) + extra, init.signal);
    if (settings.serverDown) throw new TypeError('Failed to fetch');
    switch (endpoint) {
      case 'csrf': return json({ csrf_token: 'demo-token' });
      case 'auth_status': return json({ logged_in: false });
      case 'login': return login(params);
      case 'register': return register(params);
      default: return json({ success: false, message: 'Not found' }, 404);
    }
  };
}
