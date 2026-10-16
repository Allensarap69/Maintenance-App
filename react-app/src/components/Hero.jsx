import {
  ArrowRight, Bell, CalendarDays, CalendarPlus, ChevronsRight, CircleCheck,
  CircleUserRound, ClipboardList, Download, Gauge, History, House, Motorbike,
  Plus, Settings, Wrench,
} from 'lucide-react';
import { phpUrl } from '../config';

const HERO_FEATURES = [
  { icon: CalendarDays, strong: 'Service', small: 'Scheduling' },
  { icon: Wrench, strong: 'Track', small: 'Maintenance' },
  { icon: ClipboardList, strong: 'Repair', small: 'Records' },
  { icon: Bell, strong: 'Get', small: 'Reminders' },
];

const DASH_NAV = [
  { icon: House, label: 'Dashboard', active: true },
  { icon: Gauge, label: 'Maintenance' },
  { icon: Wrench, label: 'Repairs' },
  { icon: History, label: 'Service History' },
  { icon: Bell, label: 'Reminders' },
  { icon: Settings, label: 'Settings' },
];

const STATS = [
  { icon: Motorbike, label: 'Total Bikes', value: '3' },
  { icon: CalendarDays, label: 'Scheduled', value: '2' },
  { icon: Wrench, label: 'In Service', value: '1' },
  { icon: CircleCheck, label: 'Completed', value: '12' },
];

const UPCOMING = [
  { img: 'click125.png', alt: 'Honda Click 125', name: 'Honda Click 125', meta: 'Oil Change · 1,200 km', status: 'scheduled', statusLabel: 'Scheduled' },
  { img: 'snip.png', alt: 'Yamaha Sniper 150', name: 'Yamaha Sniper 150', meta: 'Tire Check · 4,000 km', status: 'scheduled', statusLabel: 'Scheduled' },
  { img: 'rai.png', alt: 'Suzuki Raider 150', name: 'Suzuki Raider 150', meta: 'Brake Inspection · 6,000 km', status: 'upcoming', statusLabel: 'Upcoming' },
];

const QUICK_ACTIONS = [
  { icon: CalendarPlus, title: 'Add Maintenance', sub: 'Schedule a service' },
  { icon: History, title: 'View History', sub: 'Check past records' },
  { icon: Wrench, title: 'Manage Repairs', sub: 'Track repair status' },
];

export default function Hero({ onLogin, onRegister }) {
  return (
    <section className="hero-section" id="home">
      <div className="container">
        <div className="row align-items-center g-5">
          <div className="col-lg-6">
            <div className="hero-copy" data-reveal>
              <span className="hero-eyebrow">Smarter maintenance. Longer journeys.</span>
              <h1 className="hero-title">
                Motor Maintenance
                <span>System</span>
              </h1>
              <p className="hero-subtitle">
                Easily manage your motorcycle maintenance, track service schedules, and keep your ride in top shape — all in one place.
              </p>
              <div className="hero-feature-grid">
                {HERO_FEATURES.map(({ icon: Icon, strong, small }) => (
                  <div className="hero-feature" key={small}>
                    <span className="hero-feature-icon"><Icon /></span>
                    <span><strong>{strong}</strong><small>{small}</small></span>
                  </div>
                ))}
              </div>
              <div className="hero-cta">
                <button className="btn btn-cta-primary" onClick={onLogin}>
                  Get Started<ArrowRight />
                </button>
                <button className="btn btn-cta-outline" onClick={onRegister}>
                  Create Account<ChevronsRight />
                </button>
                <a href={phpUrl('download_app.php')} className="btn btn-cta-outline">
                  Download App<Download />
                </a>
              </div>
            </div>
          </div>
          <div className="col-lg-6">
            <div className="hero-visual">
              <div className="dashboard-preview" data-reveal data-delay="160">
                <aside className="dashboard-sidebar" aria-label="Dashboard preview navigation">
                  <div className="dash-brand">
                    <span className="dash-brand-mark"><img src="eversure-logo.png" alt="Eversure logo" /></span>
                    <span>Eversure</span>
                  </div>
                  <nav className="dash-nav">
                    {DASH_NAV.map(({ icon: Icon, label, active }) => (
                      <span className={active ? 'active' : ''} key={label}><Icon />{label}</span>
                    ))}
                  </nav>
                </aside>
                <div className="dashboard-main">
                  <div className="dash-top">
                    <div className="dash-greeting">
                      Good Morning, Rider!
                      <p>Keep your bike in top shape and ready for the road.</p>
                    </div>
                    <div className="d-flex align-items-center gap-2">
                      <span className="dash-user"><CircleUserRound />John Doe</span>
                      <span className="dash-add"><Plus />Add Service</span>
                    </div>
                  </div>
                  <div className="stats-grid">
                    {STATS.map(({ icon: Icon, label, value }) => (
                      <div className="stat-card" key={label}>
                        <span className="stat-icon"><Icon /></span>
                        <span><span className="stat-label">{label}</span><span className="stat-value">{value}</span></span>
                      </div>
                    ))}
                  </div>
                  <div className="dash-content-grid">
                    <div className="dash-panel">
                      <div className="dash-panel-head">Upcoming Maintenance <a href="#service-list">View All</a></div>
                      <div className="upcoming-list">
                        {UPCOMING.map((item) => (
                          <div className="upcoming-item" key={item.name}>
                            <span className="upcoming-icon"><img src={item.img} alt={item.alt} /></span>
                            <span><span className="upcoming-name">{item.name}</span><span className="upcoming-meta">{item.meta}</span></span>
                            <span className={`status-pill ${item.status}`}>{item.statusLabel}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                    <div className="dash-panel">
                      <div className="dash-panel-head">Quick Actions</div>
                      <div className="quick-actions">
                        {QUICK_ACTIONS.map(({ icon: Icon, title, sub }) => (
                          <div className="quick-action" key={title}>
                            <Icon /><span><strong>{title}</strong>{sub}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
