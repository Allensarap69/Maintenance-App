import { useEffect, useRef, useState } from 'react';
import { ArrowRight, CircleCheck, Eye, EyeOff, LoaderCircle, Lock, Mail, ShieldCheck } from 'lucide-react';
import Modal from './Modal';
import { Alert, TipRotator, useToast } from './feedback';
import { getCsrfToken, postForm, resetCsrfToken } from '../api';
import { phpUrl } from '../config';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

const LOGIN_TIPS = [
  'Use the same email you gave the shop so your bookings show up.',
  'Passwords are case-sensitive — watch out for Caps Lock.',
  'Repeated failed attempts trigger a short security delay to protect your account.',
];

function validate({ email, password }) {
  const errors = {};
  if (!email.trim()) errors.email = 'Enter your email address.';
  else if (!EMAIL_RE.test(email.trim())) errors.email = 'That doesn’t look like a valid email (e.g. you@example.com).';
  if (!password) errors.password = 'Enter your password.';
  return errors;
}

export default function LoginModal({ initialEmail = '', onClose, onSwitchToRegister, onDelay }) {
  const toast = useToast();
  const [email, setEmail] = useState(initialEmail);
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  const [showPw, setShowPw] = useState(false);
  const [capsLock, setCapsLock] = useState(false);
  const [errors, setErrors] = useState({});
  const [touched, setTouched] = useState({});
  const [alert, setAlert] = useState(null); // { variant, title, message, retry, n }
  const [status, setStatus] = useState('idle'); // idle | loading | success
  const emailRef = useRef(null);
  const passwordRef = useRef(null);
  const submitRef = useRef(null);
  const busy = status !== 'idle';

  useEffect(() => {
    (initialEmail ? passwordRef : emailRef).current?.focus();
  }, [initialEmail]);

  const showAlert = (variant, title, message, retry) =>
    setAlert((a) => ({ variant, title, message, retry, n: (a?.n || 0) + 1 }));

  const fieldError = (name) => (touched[name] || errors._submitted) && errors[name];

  const onBlur = (name) => () => {
    setTouched((t) => ({ ...t, [name]: true }));
    setErrors(validate({ email, password }));
  };

  const onKeyPw = (e) => setCapsLock(Boolean(e.getModifierState?.('CapsLock')));

  const submit = async () => {
    const v = validate({ email, password });
    if (Object.keys(v).length) {
      setErrors({ ...v, _submitted: true });
      (v.email ? emailRef : passwordRef).current?.focus();
      showAlert('error', 'Please check the highlighted fields.');
      return;
    }
    setErrors({});
    setAlert(null);
    setStatus('loading');
    try {
      const csrf_token = await getCsrfToken();
      const data = await postForm('login.php', {
        email: email.trim(),
        password,
        csrf_token,
        ...(remember ? { remember: '1' } : {}),
      });
      if (data.success) {
        setStatus('success');
        toast.success('Signed in successfully', { message: 'Opening your dashboard…' });
        setTimeout(() => {
          window.location.href = phpUrl(data.redirect || 'dashboard_customer.php');
        }, 800);
        return;
      }
      setStatus('idle');
      if (data.csrf_error) {
        resetCsrfToken();
        showAlert('warning', 'Your session expired.', 'We refreshed it for you — please sign in again.', submit);
        return;
      }
      if (data.delay && (data.delay.delay > 0 || data.delay.locked)) {
        showAlert('warning', 'Sign-in temporarily paused', data.message);
        if (data.delay.delay > 0) onDelay({ seconds: data.delay.delay, attempts: data.delay.attempts });
        return;
      }
      if (data.server_error) {
        showAlert('error', 'Something went wrong on our end.', data.message, submit);
        return;
      }
      setPassword('');
      passwordRef.current?.focus();
      showAlert('error', data.message || 'Invalid email or password.',
        'Double-check your email and password. Passwords are case-sensitive.');
    } catch (err) {
      setStatus('idle');
      showAlert('error', 'Connection problem', err.message || 'Could not reach the server.', submit);
    }
  };

  submitRef.current = submit;

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!busy) submit();
  };

  const forgotPassword = (e) => {
    e.preventDefault();
    toast.info('Forgot your password?', {
      message: 'Visit or message the shop — an admin can reset it for you from your customer profile.',
      duration: 8000,
    });
  };

  const emailErr = fieldError('email');
  const pwErr = fieldError('password');

  return (
    <Modal id="loginModal" labelId="loginModalLabel" onClose={onClose}>
      <button type="button" className="btn-close modal-close" aria-label="Close" onClick={onClose}></button>
      <div className="modal-body">
        <div className="auth-shell">
          <aside className="auth-visual" aria-label="Mindanao Eversure account benefits">
            <div className="auth-visual-brand">
              <span className="auth-visual-logo"><img src="eversure-logo.png" alt="Mindanao Eversure logo" /></span>
              <span className="auth-visual-name">Mindanao Eversure<small>Motorcycle Service</small></span>
            </div>
            <div className="auth-visual-copy">
              <span className="auth-kicker">Secure Access</span>
              <h3>Your maintenance, always in view.</h3>
              <p>Sign in to manage bookings, track repairs, and review every service record in one place.</p>
            </div>
            <div className="auth-visual-card">
              <span className="auth-card-label">Next Maintenance</span>
              <strong className="auth-card-value">Mar 28, 2027</strong>
              <small className="auth-card-sub">Oil Change · Honda Click 125</small>
            </div>
          </aside>

          <div className="auth-panel">
            <div className="auth-heading">
              <h4 id="loginModalLabel">Welcome <span className="text-brand">back</span></h4>
              <p>Enter your credentials to open your rider dashboard.</p>
            </div>

            {alert && (
              <Alert
                variant={alert.variant}
                title={alert.title}
                shakeKey={alert.variant === 'error' ? alert.n : undefined}
                action={alert.retry && !busy ? { label: 'Try again', onClick: () => submitRef.current() } : undefined}
                onDismiss={() => setAlert(null)}
                className="mb-3"
              >
                {alert.message}
              </Alert>
            )}

            <form onSubmit={handleSubmit} noValidate>
              <fieldset disabled={busy} className="ux-fieldset">
                <div className="auth-field">
                  <label htmlFor="loginEmail">Email Address</label>
                  <div className={`input-group${emailErr ? ' ux-invalid' : ''}`}>
                    <span className="input-group-text"><Mail className="input-icon" /></span>
                    <input
                      ref={emailRef}
                      type="email" className={`form-control${emailErr ? ' is-invalid' : ''}`} id="loginEmail" name="email" required
                      autoComplete="email" inputMode="email" placeholder="you@example.com"
                      aria-invalid={Boolean(emailErr)} aria-describedby={emailErr ? 'loginEmailErr' : undefined}
                      value={email} onChange={(e) => setEmail(e.target.value)} onBlur={onBlur('email')}
                    />
                  </div>
                  {emailErr && <div className="ux-field-error" id="loginEmailErr">{emailErr}</div>}
                </div>

                <div className="auth-field">
                  <label htmlFor="loginPassword">Password</label>
                  <div className={`input-group${pwErr ? ' ux-invalid' : ''}`}>
                    <span className="input-group-text"><Lock className="input-icon" /></span>
                    <input
                      ref={passwordRef}
                      type={showPw ? 'text' : 'password'} className={`form-control${pwErr ? ' is-invalid' : ''}`} id="loginPassword" name="password" required
                      autoComplete="current-password" placeholder="Enter your password"
                      aria-invalid={Boolean(pwErr)} aria-describedby={pwErr ? 'loginPwErr' : undefined}
                      value={password} onChange={(e) => setPassword(e.target.value)} onBlur={onBlur('password')}
                      onKeyUp={onKeyPw} onKeyDown={onKeyPw}
                    />
                    <span
                      className="input-group-text password-toggle" role="button" tabIndex="0"
                      onClick={() => setShowPw((s) => !s)}
                      onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && setShowPw((s) => !s)}
                      aria-label={showPw ? 'Hide password' : 'Show password'}
                    >
                      {showPw ? <EyeOff className="input-icon" /> : <Eye className="input-icon" />}
                    </span>
                  </div>
                  {pwErr && <div className="ux-field-error" id="loginPwErr">{pwErr}</div>}
                  {capsLock && !pwErr && <div className="ux-field-hint ux-field-hint--warning">Caps Lock is on.</div>}
                </div>

                <div className="d-flex justify-content-between align-items-center remember-row">
                  <div className="form-check">
                    <input className="form-check-input" type="checkbox" id="rememberMe" name="remember" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
                    <label className="form-check-label" htmlFor="rememberMe">Remember me</label>
                  </div>
                  <a href="#" className="forgot-link" onClick={forgotPassword}>Forgot password?</a>
                </div>

                <button
                  type="submit"
                  className={`btn btn-primary w-100 auth-submit${status === 'success' ? ' ux-btn-success' : ''}`}
                  aria-busy={status === 'loading'}
                >
                  {status === 'loading' && <><LoaderCircle className="ux-spin" /> Signing In…</>}
                  {status === 'success' && <><CircleCheck /> Signed in! Redirecting…</>}
                  {status === 'idle' && <>Sign In <ArrowRight /></>}
                </button>
              </fieldset>
            </form>

            <TipRotator tips={LOGIN_TIPS} />

            <p className="auth-switch">
              Don't have an account?{' '}
              <a href="#" onClick={(e) => { e.preventDefault(); onSwitchToRegister(); }}>Create one now</a>
            </p>
            <div className="auth-secure"><ShieldCheck />Protected by secure authentication</div>
          </div>
        </div>
      </div>
    </Modal>
  );
}
