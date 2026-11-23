import { apiUrl } from './config';

let csrfToken = null;

const REQUEST_TIMEOUT_MS = 15000;

// Thrown for transport-level failures so the UI can tell "offline /
// server unreachable / too slow" apart from a normal `success: false`.
export class ApiError extends Error {
  constructor(kind, message) {
    super(message);
    this.kind = kind; // 'offline' | 'timeout' | 'network'
  }
}

async function request(endpoint, init = {}) {
  if (navigator.onLine === false) {
    throw new ApiError('offline', 'You appear to be offline. Check your connection and try again.');
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  try {
    return await fetch(apiUrl(endpoint), { credentials: 'include', ...init, signal: controller.signal });
  } catch (err) {
    if (err.name === 'AbortError') {
      throw new ApiError('timeout', 'The server is taking too long to respond. Please try again.');
    }
    throw new ApiError('network', 'Could not reach the server. Make sure Apache is running, then try again.');
  } finally {
    clearTimeout(timer);
  }
}

async function parseJson(res) {
  try {
    return await res.json();
  } catch {
    return { success: false, server_error: true, message: 'Unexpected server response. Please try again in a moment.' };
  }
}

export async function getCsrfToken() {
  if (!csrfToken) {
    const res = await request('csrf.php');
    const data = await parseJson(res);
    csrfToken = data.csrf_token || '';
  }
  return csrfToken;
}

export function resetCsrfToken() {
  csrfToken = null;
}

// POST a urlencoded body so $_POST + verify_csrf_token() work unchanged.
export async function postForm(endpoint, fields) {
  const res = await request(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(fields),
  });
  return parseJson(res);
}

export async function getAuthStatus() {
  const res = await request('auth_status.php');
  return parseJson(res);
}
