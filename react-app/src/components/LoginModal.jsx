import { useState } from 'react';
import { ArrowRight, Eye, Lock, Mail, ShieldCheck } from 'lucide-react';
import Modal from './Modal';
import { getCsrfToken, postForm, resetCsrfToken } from '../api';
import { phpUrl } from '../config';

export default function LoginModal({ onClose, onSwitchToRegister, onDelay }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  const [showPw, setShowPw] = useState(false);
  const [msg, setMsg] = useState('');
  const [busy, setBusy] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setMsg('');
    try {
      const csrf_token = await getCsrfToken();
      const data = await postForm('login.php', {
        email,
        password,
        csrf_token,
        ...(remember ? { remember: '1' } : {}),
      });
      if (data.success) {
        window.location.href = phpUrl(data.redirect || 'dashboard_customer.php');
        return;
      }
      if (data.csrf_error) resetCsrfToken();
      setMsg(data.message || 'Invalid email or password.');
      if (data.delay && data.delay.delay > 0) {
        onDelay({ seconds: data.delay.delay, attempts: data.delay.attempts });
      }
    } catch {
      setMsg('Could not reach the server. Make sure Apache is running, then try again.');
    } finally {
      setBusy(false);
    }
  };

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

            {msg && (
              <div className="alert alert-danger alert-dismissible fade show mb-3 py-2 px-2" role="alert">
                {msg}
                <button type="button" className="btn-close" aria-label="Close" style={{ padding: '2px 6px', fontSize: '0.7rem' }} onClick={() => setMsg('')}></button>
              </div>
            )}

            <form onSubmit={handleSubmit}>
              <div className="auth-field">
                <label htmlFor="loginEmail">Email Address</label>
                <div className="input-group">
                  <span className="input-group-text"><Mail className="input-icon" /></span>
                  <input
                    type="email" className="form-control" id="loginEmail" name="email" required
                    autoComplete="email" inputMode="email" placeholder="you@example.com"
                    value={email} onChange={(e) => setEmail(e.target.value)}
                  />
                </div>
              </div>

              <div className="auth-field">
                <label htmlFor="loginPassword">Password</label>
                <div className="input-group">
                  <span className="input-group-text"><Lock className="input-icon" /></span>
                  <input
                    type={showPw ? 'text' : 'password'} className="form-control" id="loginPassword" name="password" required
                    autoComplete="current-password" placeholder="Enter your password"
                    value={password} onChange={(e) => setPassword(e.target.value)}
                  />
                  <span
                    className="input-group-text password-toggle" role="button" tabIndex="0"
                    onClick={() => setShowPw((s) => !s)}
                    onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && setShowPw((s) => !s)}
                    aria-label="Show password"
                  >
                    <Eye className="input-icon" />
                  </span>
                </div>
              </div>

              <div className="d-flex justify-content-between align-items-center remember-row">
                <div className="form-check">
                  <input className="form-check-input" type="checkbox" id="rememberMe" name="remember" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
                  <label className="form-check-label" htmlFor="rememberMe">Remember me</label>
                </div>
                <a href="#" className="forgot-link" onClick={(e) => e.preventDefault()}>Forgot password?</a>
              </div>

              <button type="submit" className="btn btn-primary w-100 auth-submit" disabled={busy}>
                {busy ? 'Signing In…' : 'Sign In'} <ArrowRight />
              </button>
            </form>

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
