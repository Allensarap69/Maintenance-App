import { useCallback, useEffect, useState } from 'react';

// Dark-mode toggle mirroring index.php: theme persists in localStorage,
// 'themeManual' marks an explicit user choice, and View Transitions are
// used when available.
export function useTheme() {
  const [theme, setTheme] = useState(() => document.documentElement.getAttribute('data-theme') || 'light');

  const applyTheme = useCallback((next) => {
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    localStorage.setItem('themeManual', '1');
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', next === 'dark' ? '#0b1220' : '#ffffff');
    setTheme(next);
  }, []);

  const toggleTheme = useCallback(() => {
    const next = (document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduceMotion && document.startViewTransition) {
      document.startViewTransition(() => applyTheme(next));
    } else {
      applyTheme(next);
    }
  }, [applyTheme]);

  return { theme, toggleTheme };
}

// IntersectionObserver-based reveal animations + animated counters,
// equivalent to the inline script in index.php.
export function useRevealAnimations() {
  useEffect(() => {
    const animateCount = (el) => {
      const target = parseInt(el.dataset.count, 10);
      if (isNaN(target)) return;
      const duration = 1400;
      const start = performance.now();
      const tick = (now) => {
        const p = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(target * eased) + '%';
        if (p < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    };

    const revealEl = (el) => {
      const delay = parseInt(el.dataset.delay || '0', 10);
      if (delay) el.style.transitionDelay = delay + 'ms';
      el.classList.add('revealed');
      el.querySelectorAll('[data-count]').forEach(animateCount);
    };

    const els = document.querySelectorAll('[data-reveal]');
    if (!('IntersectionObserver' in window)) {
      els.forEach(revealEl);
      return;
    }
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          revealEl(entry.target);
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15 });
    els.forEach((el) => io.observe(el));
    return () => io.disconnect();
  }, []);
}

// Smooth scroll with 80px fixed-navbar offset for in-page anchors.
export function useSmoothAnchors() {
  useEffect(() => {
    const onClick = (e) => {
      const anchor = e.target.closest('a[href^="#"]');
      if (!anchor) return;
      const id = anchor.getAttribute('href').split('#')[1];
      if (!id) {
        e.preventDefault();
        return;
      }
      const target = document.getElementById(id);
      if (target) {
        e.preventDefault();
        const top = target.getBoundingClientRect().top + window.pageYOffset - 80;
        window.scrollTo({ top, behavior: 'smooth' });
      }
    };
    document.addEventListener('click', onClick);
    return () => document.removeEventListener('click', onClick);
  }, []);
}
