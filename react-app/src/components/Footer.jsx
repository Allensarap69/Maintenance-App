import { FacebookIcon, InstagramIcon, YoutubeIcon } from './BrandIcons';
import { phpUrl } from '../config';

const FOOTER_LINKS = [
  { href: '#home', label: 'Home' },
  { href: '#features', label: 'Features' },
  { href: '#service-list', label: 'Services' },
  { href: '#how-it-works', label: 'How It Works' },
  { href: '#contact', label: 'Contact' },
];

export default function Footer() {
  return (
    <footer className="app-footer" id="contact">
      <div className="container">
        <div className="footer-top">
          <a className="navbar-brand" href="#home" aria-label="Mindanao Eversure home">
            <span className="brand-mark"><img src="eversure-logo.png" alt="Mindanao Eversure logo" /></span>
            <span>
              Mindanao Eversure
              <small className="brand-tagline">MOTORCYCLE SERVICE</small>
            </span>
          </a>
          <nav className="footer-nav" aria-label="Footer navigation">
            {FOOTER_LINKS.map(({ href, label }) => (
              <a href={href} key={label}>{label}</a>
            ))}
            <a href={phpUrl('download_app.php')}>Get the App</a>
          </nav>
          <div className="social-links">
            <a href="#" aria-label="Facebook"><FacebookIcon /></a>
            <a href="#" aria-label="Instagram"><InstagramIcon /></a>
            <a href="#" aria-label="YouTube"><YoutubeIcon /></a>
          </div>
        </div>
        <div className="footer-bottom">
          <p className="footer-copy">&copy; 2026 Mindanao Eversure Motorcycle Service. All rights reserved.</p>
          <p className="footer-tagline">Ride Safe. Service Always.</p>
        </div>
      </div>
    </footer>
  );
}
