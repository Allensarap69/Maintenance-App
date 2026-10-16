import { apiUrl } from './config';

let csrfToken = null;

async function parseJson(res) {
  try {
    return await res.json();
  } catch {
    return { success: false, message: 'Unexpected server response. Please try again.' };
  }
}

export async function getCsrfToken() {
  if (!csrfToken) {
    const res = await fetch(apiUrl('csrf.php'), { credentials: 'include' });
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
  const res = await fetch(apiUrl(endpoint), {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(fields),
  });
  return parseJson(res);
}

export async function getAuthStatus() {
  const res = await fetch(apiUrl('auth_status.php'), { credentials: 'include' });
  return parseJson(res);
}
