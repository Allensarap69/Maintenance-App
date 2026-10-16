import { useState } from 'react';
import { ArrowRight, Eye, Lock, Mail, MapPin, Phone, ShieldCheck, User } from 'lucide-react';
import Modal from './Modal';
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

export default function RegisterModal({ onClose, onSwitchToLogin }) {
  const [form, setForm] = useState(EMPTY_FORM);
  const [showPw, setShowPw] = useState(false);
  const [msg, setMsg] = useState('');
  const [success, setSuccess] = useState(false);
  const [busy, setBusy] = useState(false);

  const set = (field) => (e) => {
    const value = field === 'reg_phone' ? formatPhoneNumber(e.target.value) : e.target.value;
    setForm((f) => ({ ...f, [field]: value }));
  };

  const strength = passwordStrength(form.reg_password);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setMsg('');
    setSuccess(false);
    try {
      const csrf_token = await getCsrfToken();
      const data = await postForm('register.php', { ...form, csrf_token });
      if (data.success) {
        setSuccess(true);
        setMsg(data.message || 'Registration successful! You can now login.');
        setForm(EMPTY_FORM);
      } else {
        if (data.csrf_error) resetCsrfToken();
        setMsg(data.message || 'Registration failed. Please try again.');
      }
    } catch {
      setMsg('Could not reach the server. Make sure Apache is running, then try again.');
    } finally {
      setBusy(false);
    }
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
            <div className="auth-heading">
              <h4 id="registerModalLabel">Create your <span className="text-brand">account</span></h4>
              <p>Tell us a few details to get started.</p>
            </div>

            {msg && (
              <div className={`alert alert-${success ? 'success' : 'danger'} alert-dismissible fade show mb-3 py-2 px-2`} role="alert">
                {msg}{' '}
                {success && <a href="#" onClick={(e) => { e.preventDefault(); onSwitchToLogin(); }}>Login →</a>}
                <button type="button" className="btn-close" aria-label="Close" style={{ padding: '2px 6px', fontSize: '0.7rem' }} onClick={() => setMsg('')}></button>
              </div>
            )}

            <form onSubmit={handleSubmit}>
              <div className="auth-form-grid">
                <div className="auth-field">
                  <label htmlFor="regUsername">Full Name</label>
                  <div className="input-group">
                    <span className="input-group-text"><User className="input-icon" /></span>
                    <input
                      type="text" className="form-control" id="regUsername" name="reg_username" required
                      autoComplete="name" placeholder="Juan Dela Cruz"
                      value={form.reg_username} onChange={set('reg_username')}
                    />
                  </div>
                </div>

                <div className="auth-field">
                  <label htmlFor="regEmail">Email Address</label>
                  <div className="input-group">
                    <span className="input-group-text"><Mail className="input-icon" /></span>
                    <input
                      type="email" className="form-control" id="regEmail" name="reg_email" required
                      autoComplete="email" inputMode="email" placeholder="you@example.com"
                      value={form.reg_email} onChange={set('reg_email')}
                    />
                  </div>
                </div>

                <div className="auth-field">
                  <label htmlFor="regPassword">Password</label>
                  <div className="input-group">
                    <span className="input-group-text"><Lock className="input-icon" /></span>
                    <input
                      type={showPw ? 'text' : 'password'} className="form-control" id="regPassword" name="reg_password" required
                      autoComplete="new-password" placeholder="Create a password"
                      value={form.reg_password} onChange={set('reg_password')}
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
                  <div className="pw-meter" id="pwMeter" style={{ display: strength ? 'block' : 'none' }}>
                    <span style={strength ? { width: strength.pct, background: strength.color } : {}}></span>
                  </div>
                  <div className="pw-hint" id="pwHint" style={{ display: strength ? 'block' : 'none' }}>
                    Password strength: <strong id="pwLabel" style={strength ? { color: strength.color } : {}}>{strength?.text}</strong>
                  </div>
                </div>

                <div className="auth-field">
                  <label htmlFor="regPhone">Phone Number</label>
                  <div className="input-group">
                    <span className="input-group-text"><Phone className="input-icon" /></span>
                    <input
                      type="tel" className="form-control" id="regPhone" name="reg_phone" required
                      autoComplete="tel" inputMode="numeric" maxLength="13" pattern="09[0-9]{2} [0-9]{3} [0-9]{4}"
                      title="11-digit mobile number starting with 09 (e.g., 0917 123 4567)" placeholder="09XX XXX XXXX"
                      value={form.reg_phone} onChange={set('reg_phone')}
                    />
                  </div>
                </div>

                <div className="auth-field full">
                  <label htmlFor="regAddress">Address</label>
                  <div className="input-group">
                    <span className="input-group-text"><MapPin className="input-icon" /></span>
                    <input
                      type="text" className="form-control" id="regAddress" name="reg_address" required
                      autoComplete="street-address" placeholder="Street, Barangay, City"
                      value={form.reg_address} onChange={set('reg_address')}
                    />
                  </div>
                </div>
              </div>

              <button type="submit" className="btn btn-primary w-100 auth-submit" disabled={busy}>
                {busy ? 'Creating Account…' : 'Create Account'} <ArrowRight />
              </button>
            </form>

            <p className="auth-switch">
              Already have an account?{' '}
              <a href="#" onClick={(e) => { e.preventDefault(); onSwitchToLogin(); }}>Login here</a>
            </p>
            <div className="auth-secure"><ShieldCheck />Protected by secure authentication</div>
          </div>
        </div>
      </div>
    </Modal>
  );
}
