import { useCallback, useEffect, useState } from 'react';
import Navbar from './components/Navbar';
import Hero from './components/Hero';
import Features from './components/Features';
import Services from './components/Services';
import HowItWorks from './components/HowItWorks';
import HealthSection from './components/HealthSection';
import TrackSection from './components/TrackSection';
import Reminders from './components/Reminders';
import Footer from './components/Footer';
import ScrollTop from './components/ScrollTop';
import LoginModal from './components/LoginModal';
import RegisterModal from './components/RegisterModal';
import DelayOverlay from './components/DelayOverlay';
import { useRevealAnimations, useSmoothAnchors, useTheme } from './hooks/effects';
import { getAuthStatus, getCsrfToken } from './api';
import { phpUrl } from './config';

export default function App() {
  const { theme, toggleTheme } = useTheme();
  const [modal, setModal] = useState(null); // 'login' | 'register' | null
  const [delay, setDelay] = useState(null); // { seconds, attempts }

  useRevealAnimations();
  useSmoothAnchors();

  // Same as index.php: send already-logged-in users to their dashboard.
  useEffect(() => {
    getAuthStatus()
      .then((data) => {
        if (data.logged_in && data.redirect) {
          window.location.href = phpUrl(data.redirect);
        }
      })
      .catch(() => {});
  }, []);

  // Prefetch the session-bound CSRF token so first submit is instant.
  useEffect(() => {
    getCsrfToken().catch(() => {});
  }, []);

  const openLogin = useCallback(() => setModal('login'), []);
  const openRegister = useCallback(() => setModal('register'), []);
  const closeModal = useCallback(() => setModal(null), []);

  return (
    <>
      <Navbar theme={theme} onToggleTheme={toggleTheme} onLogin={openLogin} onRegister={openRegister} />
      <Hero onLogin={openLogin} onRegister={openRegister} />
      <Features />
      <Services onLogin={openLogin} />
      <HowItWorks onLogin={openLogin} />
      <HealthSection onLogin={openLogin} />
      <TrackSection onLogin={openLogin} />
      <Reminders onLogin={openLogin} />
      <Footer />
      <ScrollTop />

      {modal === 'login' && (
        <LoginModal
          onClose={closeModal}
          onSwitchToRegister={() => setModal('register')}
          onDelay={setDelay}
        />
      )}
      {modal === 'register' && (
        <RegisterModal
          onClose={closeModal}
          onSwitchToLogin={() => setModal('login')}
        />
      )}
      {delay && (
        <DelayOverlay
          seconds={delay.seconds}
          attempts={delay.attempts}
          onDone={() => setDelay(null)}
        />
      )}
    </>
  );
}
