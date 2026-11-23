import { Suspense, useCallback, useEffect, useRef, useState } from 'react';
import Navbar from './components/Navbar';
import Hero from './components/Hero';
import ScrollTop from './components/ScrollTop';
import DelayOverlay from './components/DelayOverlay';
import { ErrorBoundary, LazySection, lazyWithRetry, ModalSkeleton, NetworkStatus, SectionSkeleton, useToast } from './components/feedback';
import { useRevealAnimations, useSmoothAnchors, useTheme } from './hooks/effects';
import { ApiError, getAuthStatus, getCsrfToken } from './api';
import { phpUrl } from './config';

// Below-the-fold sections and the auth modals are code-split so the hero
// paints first; each chunk downloads when it is about to be needed.
const Features = lazyWithRetry(() => import('./components/Features'));
const Services = lazyWithRetry(() => import('./components/Services'));
const HowItWorks = lazyWithRetry(() => import('./components/HowItWorks'));
const HealthSection = lazyWithRetry(() => import('./components/HealthSection'));
const TrackSection = lazyWithRetry(() => import('./components/TrackSection'));
const Reminders = lazyWithRetry(() => import('./components/Reminders'));
const Footer = lazyWithRetry(() => import('./components/Footer'));
const LoginModal = lazyWithRetry(() => import('./components/LoginModal'));
const RegisterModal = lazyWithRetry(() => import('./components/RegisterModal'));

const WELCOME_TIP_KEY = 'mevWelcomeTipSeen';
const SERVER_TOAST_ID = 'server-status';

export default function App() {
  const { theme, toggleTheme } = useTheme();
  const toast = useToast();
  const [modal, setModal] = useState(null); // 'login' | 'register' | null
  const [delay, setDelay] = useState(null); // { seconds, attempts }
  const [loginEmail, setLoginEmail] = useState('');
  const redirecting = useRef(false);

  useRevealAnimations();
  useSmoothAnchors();

  // Same as index.php: send already-logged-in users to their dashboard.
  // If the PHP backend can't be reached, warn up front instead of letting
  // the first sign-in attempt fail silently.
  const checkServer = useCallback(() => {
    getAuthStatus()
      .then((data) => {
        toast.dismiss(SERVER_TOAST_ID);
        if (data.logged_in && data.redirect) {
          redirecting.current = true;
          toast.loading('Welcome back! Opening your dashboard…', { id: SERVER_TOAST_ID });
          window.location.href = phpUrl(data.redirect);
        } else if (data.server_error) {
          toast.warning('Server is having trouble', {
            id: SERVER_TOAST_ID,
            message: 'Sign-in may not work right now.',
            action: { label: 'Retry', onClick: checkServer },
          });
        }
      })
      .catch((err) => {
        if (err instanceof ApiError && err.kind === 'offline') return; // NetworkStatus banner covers this
        toast.warning('Can’t reach the server', {
          id: SERVER_TOAST_ID,
          message: err.message,
          action: { label: 'Retry', onClick: checkServer },
          duration: 10000,
        });
      });
  }, [toast]);

  useEffect(() => {
    checkServer();
    // Prefetch the session-bound CSRF token so first submit is instant.
    getCsrfToken().catch(() => {});
  }, [checkServer]);

  // Warm the modal chunks once the page is idle so opening them is instant.
  useEffect(() => {
    const idle = window.requestIdleCallback || ((cb) => setTimeout(cb, 1500));
    idle(() => { LoginModal.preload().catch(() => {}); RegisterModal.preload().catch(() => {}); });
  }, []);

  // One-time onboarding tip for first-time visitors.
  useEffect(() => {
    try {
      if (localStorage.getItem(WELCOME_TIP_KEY)) return undefined;
    } catch {
      return undefined;
    }
    const t = setTimeout(() => {
      if (redirecting.current) return;
      try { localStorage.setItem(WELCOME_TIP_KEY, '1'); } catch { /* storage blocked */ }
      toast.tip('New to Mindanao Eversure?', {
        message: 'Create a free account to book services and get maintenance reminders.',
        action: { label: 'Create account', onClick: () => setModal('register') },
      });
    }, 3500);
    return () => clearTimeout(t);
  }, [toast]);

  const openLogin = useCallback(() => setModal('login'), []);
  const openRegister = useCallback(() => setModal('register'), []);
  const closeModal = useCallback(() => setModal(null), []);

  const handleRegistered = useCallback((email) => {
    setLoginEmail(email);
    setModal('login');
    toast.success('Account created!', { message: 'Sign in with your new account to continue.' });
  }, [toast]);

  const handleDelay = useCallback((d) => {
    setDelay(d);
    toast.warning('Too many failed attempts', {
      message: `Please wait before trying again (attempt ${d.attempts} of 5).`,
    });
  }, [toast]);

  return (
    <>
      <NetworkStatus />
      <Navbar theme={theme} onToggleTheme={toggleTheme} onLogin={openLogin} onRegister={openRegister} />
      <Hero onLogin={openLogin} onRegister={openRegister} />
      <LazySection id="features" skeleton={<SectionSkeleton cards={3} minHeight={560} />}>
        <Features />
      </LazySection>
      <LazySection id="service-list" skeleton={<SectionSkeleton cards={3} minHeight={520} />}>
        <Services onLogin={openLogin} />
      </LazySection>
      <LazySection id="how-it-works" skeleton={<SectionSkeleton cards={4} minHeight={460} />}>
        <HowItWorks onLogin={openLogin} />
      </LazySection>
      <LazySection id="health" skeleton={<SectionSkeleton cards={2} minHeight={520} />}>
        <HealthSection onLogin={openLogin} />
      </LazySection>
      <LazySection id="track" skeleton={<SectionSkeleton cards={2} minHeight={420} />}>
        <TrackSection onLogin={openLogin} />
      </LazySection>
      <LazySection skeleton={<SectionSkeleton cards={2} minHeight={480} />}>
        <Reminders onLogin={openLogin} />
      </LazySection>
      <LazySection id="contact" skeleton={<SectionSkeleton cards={4} minHeight={320} />}>
        <Footer />
      </LazySection>
      <ScrollTop />

      {modal && (
        <ErrorBoundary key={modal} title="Couldn’t open this form" onClose={closeModal}>
          <Suspense fallback={<ModalSkeleton />}>
            {modal === 'login' && (
              <LoginModal
                initialEmail={loginEmail}
                onClose={closeModal}
                onSwitchToRegister={openRegister}
                onDelay={handleDelay}
              />
            )}
            {modal === 'register' && (
              <RegisterModal
                onClose={closeModal}
                onSwitchToLogin={openLogin}
                onRegistered={handleRegistered}
              />
            )}
          </Suspense>
        </ErrorBoundary>
      )}
      {delay && (
        <DelayOverlay
          seconds={delay.seconds}
          attempts={delay.attempts}
          onDone={() => {
            setDelay(null);
            toast.info('You can try signing in again', { duration: 4000 });
          }}
        />
      )}
    </>
  );
}
