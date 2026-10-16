import { ArrowRight, Bell, ClipboardList, History, ShieldCheck, Wrench } from 'lucide-react';

const FEATURES = [
  { icon: Wrench, title: 'Service Scheduling', desc: 'Never miss a service with automated reminders.', delay: 0 },
  { icon: ClipboardList, title: 'Repair Tracking', desc: 'Log and monitor repairs for each vehicle.', delay: 60 },
  { icon: History, title: 'Service History', desc: 'View complete maintenance records anytime.', delay: 120 },
  { icon: Bell, title: 'Smart Reminders', desc: 'Get notified before your next service is due.', delay: 180 },
  { icon: ShieldCheck, title: 'Keep Your Ride Reliable', desc: 'Less downtime, more rides.', delay: 240 },
];

export default function Features() {
  return (
    <section className="features-section" id="features">
      <div className="container">
        <div className="feature-showcase">
          <div className="feature-intro" data-reveal>
            <span className="section-eyebrow">Why Choose Mindanao Eversure?</span>
            <h2 className="section-title">Simple. <span>Reliable.</span> Efficient.</h2>
            <p className="section-subtitle">Everything you need to maintain your motorcycle, reduce downtime, and ride with confidence — anytime, anywhere.</p>
            <a href="#how-it-works" className="btn btn-cta-primary">Learn More<ArrowRight /></a>
          </div>
          <div className="feature-cards">
            {FEATURES.map(({ icon: Icon, title, desc, delay }) => (
              <div className="feature-card" data-reveal data-delay={delay || undefined} key={title}>
                <span className="feature-card-icon"><Icon /></span>
                <h4>{title}</h4>
                <p>{desc}</p>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}
