import { ArrowRight, Bell, CalendarCheck, Motorbike, Settings, ShieldCheck, Zap } from 'lucide-react';

const REMINDER_CARDS = [
  { icon: CalendarCheck, color: 'blue', label: 'Next Maintenance', value: 'Mar 28, 2027', sub: 'Scheduled Visit' },
  { icon: ShieldCheck, color: 'yellow', label: 'Warranty', value: 'Valid until Dec 2027', sub: 'Active Coverage' },
];

const BENEFITS = [
  { icon: ShieldCheck, title: 'Safe Rides', sub: 'Well-maintained bikes mean safer journeys.', delay: 0 },
  { icon: Settings, title: 'Save Time', sub: 'Organize your maintenance in one place.', delay: 70 },
  { icon: Zap, title: 'Lower Costs', sub: 'Prevent major repairs with regular service.', delay: 140 },
  { icon: Motorbike, title: 'Your Bike. Our Priority.', sub: 'Keep it running. Keep exploring.', delay: 210 },
];

export default function Reminders({ onLogin }) {
  return (
    <>
      <section className="reminder-section">
        <div className="container">
          <div className="row g-4 align-items-stretch">
            <div className="col-lg-6">
              <div className="reminder-wrap" data-reveal>
                <div className="reminder-icon"><Bell /></div>
                <div>
                  <div className="reminder-title">Stay Ahead of Maintenance &amp; Warranty</div>
                  <p className="reminder-sub">Get notified before your service or warranty expires.</p>
                </div>
              </div>
              <div className="reminder-cards mt-3" data-reveal data-delay="100">
                {REMINDER_CARDS.map(({ icon: Icon, color, label, value, sub }) => (
                  <div className="reminder-card" key={label}>
                    <div className={`rc-icon ${color}`}><Icon /></div>
                    <div>
                      <div className="rc-label">{label}</div>
                      <div className="rc-value">{value}</div>
                      <div className="rc-sub">{sub}</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
            <div className="col-lg-6">
              <div className="cta-card" data-reveal data-delay="140">
                <img src="MOTOR.jpg" alt="" className="cta-img" aria-hidden="true" />
                <div className="cta-content">
                  <h3 className="cta-title">Ready to Take Better Care of Your Motorcycle?</h3>
                  <p className="cta-subtitle">Schedule your next service and keep your motorcycle running smoothly.</p>
                  <button className="btn btn-cta-primary" onClick={onLogin}>
                    Book a Service<ArrowRight />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="benefits-strip" aria-label="Service benefits">
        <div className="container">
          <div className="benefits-grid">
            {BENEFITS.map(({ icon: Icon, title, sub, delay }) => (
              <div className="benefit-item" data-reveal data-delay={delay || undefined} key={title}>
                <span className="benefit-icon"><Icon /></span>
                <span><strong>{title}</strong><small>{sub}</small></span>
              </div>
            ))}
          </div>
        </div>
      </section>
    </>
  );
}
