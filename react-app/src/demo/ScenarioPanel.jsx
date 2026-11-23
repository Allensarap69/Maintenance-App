import { Suspense, useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { FlaskConical, X } from 'lucide-react';
import { ErrorBoundary, SectionSkeleton, lazyWithRetry, useToast } from '../components/feedback';
import { ACCOUNTS, DEMO_PASSWORD, resetMockState, settings } from './mockApi';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function waitFor(selector, tries = 60) {
  for (let i = 0; i < tries; i += 1) {
    const el = document.querySelector(selector);
    if (el) return el;
    await sleep(50);
  }
  return null;
}

// Sets a React-controlled input the same way typing would.
function type(selector, value) {
  const el = document.querySelector(selector);
  if (!el) return;
  Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, value);
  el.dispatchEvent(new Event('input', { bubbles: true }));
}

async function closeModal() {
  const btn = document.querySelector('.modal.show .modal-close');
  if (btn) { btn.click(); await sleep(300); }
}

async function runLogin(email, password, submit = true) {
  await closeModal();
  document.querySelector('.btn-mev-login')?.click();
  if (!(await waitFor('#loginEmail'))) return;
  type('#loginEmail', email);
  type('#loginPassword', password);
  if (submit) { await sleep(80); document.querySelector('#loginModal button[type=submit]')?.click(); }
}

const RIDER = {
  '#regUsername': 'Juan Dela Cruz',
  '#regPassword': DEMO_PASSWORD,
  '#regPhone': '0917 123 4567',
  '#regAddress': 'Purok 3, Brgy. Lapasan, Cagayan de Oro City',
};

async function runRegister(email, { submit = true, fill = true } = {}) {
  await closeModal();
  document.querySelector('.btn-mev-register')?.click();
  if (!(await waitFor('#regEmail'))) return;
  if (fill) {
    Object.entries(RIDER).forEach(([sel, v]) => type(sel, v));
    type('#regEmail', email);
  }
  if (submit) { await sleep(80); document.querySelector('#registerModal button[type=submit]')?.click(); }
}

// Swaps the "Health" section for a copy whose chunk is slow or fails once.
function SectionOverride({ mode, run }) {
  const [slot, setSlot] = useState(null);

  useEffect(() => {
    const anchor = document.getElementById('health');
    const wrapper = anchor?.closest('.ux-lazy-section') || anchor;
    if (!wrapper) return undefined;
    const holder = document.createElement('div');
    wrapper.before(holder);
    wrapper.style.display = 'none';
    setSlot(holder);
    requestAnimationFrame(() => holder.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    return () => {
      holder.remove();
      wrapper.style.display = '';
    };
  }, [run]);

  const Section = useMemo(() => {
    let attempts = 0;
    return lazyWithRetry(async () => {
      attempts += 1;
      await sleep(mode === 'slow' ? 3000 : 900);
      if (mode === 'fail' && attempts === 1) {
        throw new Error('Failed to fetch dynamically imported module: HealthSection.js (demo)');
      }
      return import('../components/HealthSection');
    });
  }, [mode, run]);

  if (!slot) return null;
  return createPortal(
    <ErrorBoundary>
      <Suspense fallback={<SectionSkeleton cards={2} minHeight={480} />}>
        <Section onLogin={() => document.querySelector('.btn-mev-login')?.click()} />
      </Suspense>
    </ErrorBoundary>,
    slot,
  );
}

let onlineOverride = true;
function setOnline(online) {
  if (!Object.getOwnPropertyDescriptor(navigator, 'onLine')) {
    Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => onlineOverride });
  }
  onlineOverride = online;
  window.dispatchEvent(new Event(online ? 'online' : 'offline'));
}

function Scenario({ label, hint, onClick, active }) {
  return (
    <button type="button" className={`mev-demo__item${active ? ' is-active' : ''}`} onClick={onClick}>
      <span>{label}</span>
      {hint && <small>{hint}</small>}
    </button>
  );
}

export default function ScenarioPanel({ signedIn }) {
  const toast = useToast();
  const [open, setOpen] = useState(() => window.innerWidth > 768);
  const [serverDown, setServerDown] = useState(settings.serverDown);
  const [offline, setOffline] = useState(false);
  const [section, setSection] = useState(null); // { mode, run }

  useEffect(() => {
    if (!signedIn) return;
    window.history.replaceState(null, '', window.location.pathname);
    toast.success('Signed in as Juan (demo)', {
      id: 'demo-signed-in',
      message: 'On the live site this opens the customer dashboard.',
      duration: 6000,
    });
  }, [signedIn, toast]);

  const pick = (fn) => () => {
    if (window.innerWidth <= 768) setOpen(false);
    fn();
  };

  const groups = [
    {
      title: 'Sign in',
      items: [
        { label: 'Success', hint: 'Green button, toast, redirect', run: () => runLogin(ACCOUNTS.ok, DEMO_PASSWORD) },
        { label: 'Validation errors', hint: 'Empty / malformed fields', run: () => runLogin('rider@', '') },
        { label: 'Wrong password', hint: 'Third try starts a delay', run: () => runLogin(ACCOUNTS.ok, 'wrongpass') },
        { label: 'Server error (500)', hint: 'Inline error + Try again', run: () => runLogin(ACCOUNTS.crash, DEMO_PASSWORD) },
        { label: 'Session expired', hint: 'CSRF refresh warning', run: () => { resetMockState(); runLogin(ACCOUNTS.expired, DEMO_PASSWORD); } },
        { label: 'Slow server', hint: 'Loading state for 3s', run: () => runLogin(ACCOUNTS.slow, DEMO_PASSWORD) },
        { label: 'Security delay', hint: 'Countdown overlay', run: () => runLogin(ACCOUNTS.locked, DEMO_PASSWORD) },
      ],
    },
    {
      title: 'Create account',
      items: [
        { label: 'Success', hint: 'Success panel → sign in', run: () => runRegister('juan.delacruz@eversure.ph') },
        { label: 'Validation & password rules', hint: 'Empty form submitted', run: () => runRegister('', { fill: false }) },
        { label: 'Email already registered', hint: '“Sign in instead” action', run: () => runRegister(ACCOUNTS.taken) },
        { label: 'Server error (500)', hint: 'Retry keeps your input', run: () => runRegister(ACCOUNTS.crash) },
      ],
    },
    {
      title: 'Page & network',
      items: [
        serverDown
          ? { label: 'Bring server back', hint: 'Then press Retry on the toast', active: true, run: () => { settings.serverDown = false; setServerDown(false); } }
          : { label: 'Server unreachable', hint: 'Reloads with the API down', run: () => { window.location.search = '?scenario=server-down'; } },
        { label: offline ? 'Go back online' : 'Go offline', hint: 'Banner + “back online” toast', active: offline, run: () => { setOnline(offline); setOffline(!offline); } },
        { label: 'Lazy loading (slow)', hint: 'Skeleton for 3s', run: () => setSection((s) => ({ mode: 'slow', run: (s?.run || 0) + 1 })) },
        { label: 'Section fails to load', hint: 'Error card + Try again', run: () => setSection((s) => ({ mode: 'fail', run: (s?.run || 0) + 1 })) },
        { label: 'Welcome tip', hint: 'First-visit tip after reload', run: () => { try { localStorage.removeItem('mevWelcomeTipSeen'); } catch { /* blocked */ } window.location.href = window.location.pathname; } },
      ],
    },
    {
      title: 'Toasts',
      items: [
        { label: 'Success', run: () => toast.success('Booking confirmed', { message: 'Oil change for your Honda Click 125 on Mar 28, 9:00 AM.' }) },
        { label: 'Error', run: () => toast.error('Payment not received', { message: 'GCash didn’t confirm the payment. You weren’t charged.', action: { label: 'Retry', onClick: () => toast.info('Retrying payment…') } }) },
        { label: 'Warning', run: () => toast.warning('Tire check due', { message: 'Rear tire on your Yamaha Sniper 150 is at 88%. Schedule an inspection.' }) },
        { label: 'Info', run: () => toast.info('Mechanic assigned', { message: 'Mark R. will handle your repair at the Lapasan branch.' }) },
        { label: 'Tip', run: () => toast.tip('Skip the queue', { message: 'Weekday mornings have the shortest wait at the shop.' }) },
        { label: 'Loading → success', run: () => {
          const id = toast.loading('Uploading receipt…', { message: 'Checking your GCash screenshot.' });
          setTimeout(() => toast.update(id, 'success', 'Receipt verified', { message: 'Payment of ₱850.00 confirmed.' }), 2200);
        } },
      ],
    },
  ];

  return (
    <>
      {section && <SectionOverride key={section.run} mode={section.mode} run={section.run} />}
      <div className={`mev-demo${open ? ' is-open' : ''}`}>
        {open ? (
          <div className="mev-demo__panel" role="dialog" aria-label="UX scenarios">
            <div className="mev-demo__head">
              <strong><FlaskConical /> UX Scenarios</strong>
              <button type="button" className="mev-demo__close" aria-label="Hide scenarios" onClick={() => setOpen(false)}><X /></button>
            </div>
            <p className="mev-demo__note">
              Mock data — no PHP needed. Demo password: <code>{DEMO_PASSWORD}</code>
            </p>
            <div className="mev-demo__body">
              {groups.map((g) => (
                <section key={g.title}>
                  <h6>{g.title}</h6>
                  <div className={`mev-demo__grid${g.title === 'Toasts' ? ' is-compact' : ''}`}>
                    {g.items.map((it) => (
                      <Scenario key={it.label} label={it.label} hint={it.hint} active={it.active} onClick={pick(it.run)} />
                    ))}
                  </div>
                </section>
              ))}
              {section && (
                <button type="button" className="mev-demo__reset" onClick={() => setSection(null)}>Restore Health section</button>
              )}
            </div>
          </div>
        ) : (
          <button type="button" className="mev-demo__fab" onClick={() => setOpen(true)}>
            <FlaskConical /> Scenarios
          </button>
        )}
      </div>
    </>
  );
}
