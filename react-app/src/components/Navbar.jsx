import { useEffect, useState } from 'react';
import { Moon, Sun, UserRound } from 'lucide-react';

const NAV_LINKS = [
  { id: 'home', label: 'Home' },
  { id: 'features', label: 'Features' },
  { id: 'service-list', label: 'Services' },
  { id: 'how-it-works', label: 'How It Works' },
  { id: 'contact', label: 'Contact' },
];

export default function Navbar({ theme, onToggleTheme, onLogin, onRegister }) {
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [active, setActive] = useState('home');

  useEffect(() => {
    const onScroll = () => {
      setScrolled(window.scrollY > 30);
      let current = 'home';
      ['features', 'service-list', 'how-it-works', 'contact'].forEach((id) => {
        const section = document.getElementById(id);
        if (section && section.getBoundingClientRect().top <= 120) current = id;
      });
      setActive(current);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  const closeMenu = () => setOpen(false);

  return (
    <nav className={`navbar navbar-expand-lg fixed-top navbar-custom${scrolled ? ' scrolled' : ''}`}>
      <div className="container">
        <a className="navbar-brand" href="#home" aria-label="Mindanao Eversure home" onClick={closeMenu}>
          <span className="brand-mark"><img src="eversure-logo.png" alt="Mindanao Eversure logo" /></span>
          <span>
            Mindanao Eversure
            <small className="brand-tagline">MOTORCYCLE SERVICE</small>
          </span>
        </a>
        <button
          className="navbar-toggler border-0"
          type="button"
          aria-controls="navbarNav"
          aria-expanded={open}
          aria-label="Toggle navigation"
          onClick={() => setOpen((o) => !o)}
        >
          <span className="navbar-toggler-icon"></span>
        </button>
        <div className={`collapse navbar-collapse${open ? ' show' : ''}`} id="navbarNav">
          <ul className="navbar-nav ms-auto align-items-lg-center">
            {NAV_LINKS.map((link) => (
              <li className="nav-item" key={link.id}>
                <a
                  className={`nav-link${active === link.id ? ' active' : ''}`}
                  href={`#${link.id}`}
                  onClick={closeMenu}
                >
                  {link.label}
                </a>
              </li>
            ))}
            <li className="nav-item ms-lg-2 mt-2 mt-lg-0 d-flex align-items-center justify-content-center">
              <button
                type="button"
                className="theme-toggle"
                id="themeToggle"
                aria-label="Toggle dark mode"
                aria-pressed={theme === 'dark'}
                title="Toggle dark mode"
                onClick={onToggleTheme}
              >
                <Moon className="theme-icon theme-icon-moon" />
                <Sun className="theme-icon theme-icon-sun" />
              </button>
            </li>
            <li className="nav-item ms-lg-3 mt-2 mt-lg-0 d-flex flex-column flex-lg-row gap-2">
              <a href="#" className="btn btn-mev-login" onClick={(e) => { e.preventDefault(); closeMenu(); onLogin(); }}>
                <UserRound className="me-1" style={{ width: 14, height: 14 }} />Login
              </a>
              <a href="#" className="btn btn-mev-register" onClick={(e) => { e.preventDefault(); closeMenu(); onRegister(); }}>
                Get Started
              </a>
            </li>
          </ul>
        </div>
      </div>
    </nav>
  );
}
