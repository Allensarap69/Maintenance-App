import { useRef, useState } from 'react';
import {
  ArrowRight, Check, CircleCheck, Eye, EyeOff, LoaderCircle, Lock, Mail, MapPin, Phone, ShieldCheck, User,
} from 'lucide-react';
import Modal from './Modal';
import { Alert, TipRotator } from './feedback';
import { getCsrfToken, postForm, resetCsrfToken } from '../api';

// Formats input as 09** *** **** (11 digits, digits only) — same as index.php.
function formatPhoneNumber(value) {
  const digits = value.replace(/\D/g, '').slice(0, 11);
  const parts = [];
  if (digits.length > 0) parts.push(digits.slice(0, 4));
  if (digits.length > 4) parts.push(digits.slice(4, 7));
  if (digits.length > 7) parts.push(digits.slice(7, 11));
  return parts.join(' ');
}

const PW_LEVELS = [
  { pct: '18%', color: '#ef4444', text: 'Too short' },
  { pct: '38%', color: '#f97316', text: 'Weak' },
  { pct: '62%', color: '#FACC15', text: 'Fair' },
  { pct: '82%', color: '#84cc16', text: 'Good' },
  { pct: '100%', color: '#22c55e', text: 'Strong' },
];

function passwordStrength(v) {
  if (!v.length) return null;
  let s = 0;
  if (v.length >= 8) s++;
  if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
  if (/\d/.test(v)) s++;
  if (/[^A-Za-z0-9]/.test(v)) s++;
  s = v.length < 6 ? 0 : Math.max(s, 1);
  return PW_LEVELS[s];
}

const EMPTY_FORM = { reg_username: '', reg_email: '', reg_password: '', reg_phone: '', reg_address: '' };
const FIELD_ORDER = ['reg_username', 'reg_email', 'reg_password', 'reg_phone', 'reg_address'];
// Only characters PHP's FILTER_SANITIZE_EMAIL keeps, so login.php finds the stored address.
const EMAIL_RE = /^[A-Za-z0-9.!#$%&'*+\/=?^_`{|}~-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/;

const PW_RULES = [
  { test: (v) => v.length >= 8, label: '8+ characters' },
  { test: (v) => /[a-z]/.test(v) && /[A-Z]/.test(v), label: 'Upper & lowercase' },
  { test: (v) => /\d/.test(v), label: 'A number' },
  { test: (v) => /[^A-Za-z0-9]/.test(v), label: 'A symbol' },
];

const REGISTER_TIPS = [
  'Use a mobile number you check often — booking updates are sent by SMS.',
  'Add your full address so mechanics can find you for emergency service.',
  'You can add your motorcycles after signing in.',
];

function validate(f) {
  const e = {};
  if (f.reg_username.trim().length < 2) e.reg_username = 'Enter your full name.';
  if (!f.reg_email.trim()) e.reg_email = 'Enter your email address.';
  else if (!EMAIL_RE.test(f.reg_email.trim())) e.reg_email = 'Enter a valid email (e.g. you@example.com).';
  if (!f.reg_password) e.reg_password = 'Create a password.';
  else if (f.reg_password.length < 6) e.reg_password = 'Use at least 6 characters.';
  if (!/^09\d{9}$/.test(f.reg_phone.replace(/\D/g, ''))) e.reg_phone = 'Enter an 11-digit number starting with 09.';
  if (f.reg_address.trim().length < 5) e.reg_address = 'Enter your street, barangay and city.';
  return e;
}

export default function RegisterModal({ onClose, onSwitchToLogin, onRegistered }) {
  const [form, setForm] = useState(EMPTY_FORM);
  const [showPw, setShowPw] = useState(false);
  const [touched, setTouched] = useState({});
  const [submitted, setSubmitted] = useState(false);
  const [alert, setAlert] = useState(null); // { variant, title, message, action, n }
  const [status, setStatus] = useState('idle'); // idle | loading | success
  const [doneEmail, setDoneEmail] = useState('');
  const refs = useRef({});
  const submitRef = useRef(null);
  const busy = status === 'loading';

  const errors = validate(form);
  const fieldError = (name) => (touched[name] || submitted) && errors[name];
  const strength = passwordStrength(form.reg_password);

  const set = (field) => (e) => {
    const value = field === 'reg_phone' ? formatPhoneNumber(e.target.value) : e.target.value;
    setForm((f) => ({ ...f, [field]: value }));
  };
  const blur = (field) => () => setTouched((t) => ({ ...t, [field]: true }));

  const showAlert = (variant, title, message, action) =>
    setAlert((a) => ({ variant, title, message, action, n: (a?.n || 0) + 1 }));

  const submit = async () => {
    setSubmitted(true);
    const firstBad = FIELD_ORDER.find((f) => errors[f]);
    if (firstBad) {
      refs.current[firstBad]?.focus();
      const count = Object.keys(errors).length;
      showAlert('error', `Please fix ${count} field${count > 1 ? 's' : ''} below.`);
      return;
    }
    setAlert(null);
    setStatus('loading');
    try {
      const csrf_token = await getCsrfToken();
      const data = await postForm('register.php', { ...form, csrf_token });
      if (data.success) {
        setDoneEmail(form.reg_email.trim());
        setStatus('success');
        setForm(EMPTY_FORM);
        return;
      }
      setStatus('idle');
      if (data.csrf_error) {
        resetCsrfToken();
        showAlert('warning', 'Your session expired.', 'We refreshed it — press Create Account again.', { label: 'Try again', onClick: retry });
      } else if (/already registered/i.test(data.message || '')) {
        refs.current.reg_email?.focus();
        showAlert('warning', 'This email already has an account.', 'Sign in instead, or use a different email.',
          { label: 'Sign in instead', onClick: onSwitchToLogin });
      } else {
        showAlert('error', data.message || 'Registration failed.', data.server_error ? 'Please try again in a moment.' : undefined,
          data.server_error ? { label: 'Try again', onClick: retry } : undefined);
      }
    } catch (err) {
      setStatus('idle');
      showAlert('error', 'Connection problem', err.message, { label: 'Try again', onClick: retry });
    }
  };

  submitRef.current = submit;
  const retry = () => submitRef.current();

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!busy) submit();
  };

  const field = (name, { label, icon: Icon, full, children, ...input }) => {
    const err = fieldError(name);
    const ok = touched[name] && !errors[name];
    return (
      <div className={`auth-field${full ? ' full' : ''}`}>
        <label htmlFor={input.id}>{label}</label>
        <div className={`input-group${err ? ' ux-invalid' : ''}${ok ? ' ux-valid' : ''}`}>
          <span className="input-group-text"><Icon className="input-icon" /></span>
          <input
            ref={(el) => { refs.current[name] = el; }}
            className={`form-control${err ? ' is-invalid' : ''}`} name={name} required
            aria-invalid={Boolean(err)} aria-describedby={err ? `${input.id}Err` : undefined}
            value={form[name]} onChange={set(name)} onBlur={blur(name)}
            {...input}
          />
          {children}
        </div>
        {err && <div className="ux-field-error" id={`${input.id}Err`}>{err}</div>}
      </div>
    );
  };

  return (
    <Modal id="registerModal" labelId="registerModalLabel" onClose={onClose}>
      <button type="button" className="btn-close modal-close" aria-label="Close" onClick={onClose}></button>
      <div className="modal-body">
        <div className="auth-shell">
          <aside className="auth-visual" aria-label="Mindanao Eversure registration benefits">
            <div className="auth-visual-brand">
              <span className="auth-visual-logo"><img src="eversure-logo.png" alt="Mindanao Eversure logo" /></span>
              <span className="auth-visual-name">Mindanao Eversure<small>Motorcycle Service</small></span>
            </div>
            <div className="auth-visual-copy">
              <span className="auth-kicker">Rider Profile</span>
              <h3>Build a complete service record.</h3>
              <p>Create an account to schedule maintenance, monitor repairs, and receive service reminders.</p>
            </div>
            <div className="auth-visual-card">
              <span className="auth-card-label">What You Get</span>
              <strong className="auth-card-value">Bookings · History · Reminders</strong>
              <small className="auth-card-sub">One profile for every motorcycle</small>
            </div>
          </aside>

          <div className="auth-panel">
            {status === 'success' ? (
              <div className="ux-success-panel" role="status">
                <span className="ux-success-panel__icon"><CircleCheck /></span>
                <h4 id="registerModalLabel">You’re all set!</h4>
                <p>Your account for <strong>{doneEmail}</strong> is ready. Sign in to book your first service.</p>
                <button type="button" className="btn btn-primary w-100 auth-submit" onClick={() => onRegistered(doneEmail)}>
                  Continue to Sign In <ArrowRight />
                </button>
                <TipRotator label="Next step" tips={['Add your motorcycle on the dashboard to start tracking its health score.']} />
              </div>
            ) : (
              <>
                <div className="auth-heading">
                  <h4 id="registerModalLabel">Create your <span className="text-brand">account</span></h4>
                  <p>Tell us a few details to get started.</p>
                </div>

                {alert && (
                  <Alert
                    variant={alert.variant}
                    title={alert.title}
                    shakeKey={alert.variant === 'error' ? alert.n : undefined}
                    action={alert.action && !busy ? alert.action : undefined}
                    onDismiss={() => setAlert(null)}
                    className="mb-3"
                  >
                    {alert.message}
                  </Alert>
                )}

                <form onSubmit={handleSubmit} noValidate>
                  <fieldset disabled={busy} className="ux-fieldset">
                    <div className="auth-form-grid">
                      {field('reg_username', { label: 'Full Name', icon: User, id: 'regUsername', type: 'text', autoComplete: 'name', placeholder: 'Juan Dela Cruz' })}
                      {field('reg_email', { label: 'Email Address', icon: Mail, id: 'regEmail', type: 'email', autoComplete: 'email', inputMode: 'email', placeholder: 'you@example.com' })}

                      <div className="ux-field-stack">
                        {field('reg_password', {
                          label: 'Password', icon: Lock, id: 'regPassword', type: showPw ? 'text' : 'password',
                          autoComplete: 'new-password', placeholder: 'Create a password',
                          children: (
                            <span
                              className="input-group-text password-toggle" role="button" tabIndex="0"
                              onClick={() => setShowPw((s) => !s)}
                              onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && setShowPw((s) => !s)}
                              aria-label={showPw ? 'Hide password' : 'Show password'}
                            >
                              {showPw ? <EyeOff className="input-icon" /> : <Eye className="input-icon" />}
                            </span>
                          ),
                        })}
                        <div className="pw-meter" id="pwMeter" style={{ display: strength ? 'block' : 'none' }}>
                          <span style={strength ? { width: strength.pct, background: strength.color } : {}}></span>
                        </div>
                        <div className="pw-hint" id="pwHint" style={{ display: strength ? 'block' : 'none' }}>
                          Password strength: <strong id="pwLabel" style={strength ? { color: strength.color } : {}}>{strength?.text}</strong>
                        </div>
                        {form.reg_password && (
                          <ul className="ux-pw-rules" aria-label="Password tips">
                            {PW_RULES.map((r) => {
                              const met = r.test(form.reg_password);
                              return (
                                <li key={r.label} className={met ? 'is-met' : ''}>
                                  <Check /> {r.label}
                                </li>
                              );
                            })}
                          </ul>
                        )}
                      </div>

                      {field('reg_phone', {
                        label: 'Phone Number', icon: Phone, id: 'regPhone', type: 'tel', autoComplete: 'tel', inputMode: 'numeric',
                        maxLength: 13, title: '11-digit mobile number starting with 09 (e.g., 0917 123 4567)', placeholder: '09XX XXX XXXX',
                      })}
                      {field('reg_address', { label: 'Address', icon: MapPin, id: 'regAddress', type: 'text', full: true, autoComplete: 'street-address', placeholder: 'Street, Barangay, City' })}
                    </div>

                    <button type="submit" className="btn btn-primary w-100 auth-submit" aria-busy={busy}>
                      {busy ? <><LoaderCircle className="ux-spin" /> Creating Account…</> : <>Create Account <ArrowRight /></>}
                    </button>
                  </fieldset>
                </form>

                <TipRotator tips={REGISTER_TIPS} />

                <p className="auth-switch">
                  Already have an account?{' '}
                  <a href="#" onClick={(e) => { e.preventDefault(); onSwitchToLogin(); }}>Login here</a>
                </p>
              </>
            )}
            <div className="auth-secure"><ShieldCheck />Protected by secure authentication</div>
          </div>
        </div>
      </div>
    </Modal>
  );
}
