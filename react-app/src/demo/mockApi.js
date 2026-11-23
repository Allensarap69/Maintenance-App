// In-browser stand-in for api/*.php so the demo runs without Apache/MySQL.
// Responses mirror the PHP endpoints' JSON shapes.
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
  const email = (p.get('email') || '').toLowerCase();
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
  if (Object.values(ACCOUNTS).includes(email) && p.get('password') === DEMO_PASSWORD) {
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
  const email = (p.get('reg_email') || '').toLowerCase();
  if (email === ACCOUNTS.crash) return fatal();
  if (email === ACCOUNTS.taken || email === ACCOUNTS.ok) {
    return json({ success: false, message: 'Email already registered. Please use a different email or login.' });
  }
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
    const match = url.match(/\/api\/([a-z_]+)\.php/);
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
