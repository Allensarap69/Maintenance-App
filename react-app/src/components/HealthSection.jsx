import { ArrowRight, BatteryCharging, Cog, Disc, LifeBuoy } from 'lucide-react';

const HEALTH_BARS = [
  { icon: Cog, label: 'Engine', value: 95 },
  { icon: Disc, label: 'Brakes', value: 90 },
  { icon: LifeBuoy, label: 'Tires', value: 88, warn: true },
  { icon: BatteryCharging, label: 'Battery', value: 94 },
];

export default function HealthSection({ onLogin }) {
  return (
    <section className="health-section" id="health">
      <div className="container">
        <div className="row align-items-center g-5">
          <div className="col-lg-5">
            <div className="health-bike-wrap" data-reveal>
              <img src="moto_hero.jpg" alt="Motorcycle health monitoring" className="health-bike" />
            </div>
          </div>
          <div className="col-lg-7">
            <div data-reveal>
              <span className="section-eyebrow">Motorcycle Health</span>
              <h2 className="section-title">Know Your Motorcycle's Health</h2>
              <p className="section-subtitle">
                Monitor your motorcycle's condition using maintenance history, inspection results, mileage, and overdue services.
              </p>
            </div>
            <div className="health-score-row" data-reveal data-delay="120">
              <div className="health-donut">
                <div className="health-donut-inner">
                  <span className="health-donut-num" data-count="92">92%</span>
                  <span className="health-donut-label">Health Score</span>
                </div>
              </div>
              <div className="health-bars">
                {HEALTH_BARS.map(({ icon: Icon, label, value, warn }) => (
                  <div className="h-bar" key={label}>
                    <div className="h-bar-icon"><Icon /></div>
                    <div className="h-bar-content">
                      <div className="h-bar-top">
                        <span className="h-bar-label">{label}</span>
                        <span className="h-bar-value">{value}%</span>
                      </div>
                      <div className="h-bar-track"><div className={`bar-fill${warn ? ' warn' : ''}`} style={{ '--w': `${value}%` }}></div></div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
            <button className="btn btn-cta-primary" data-reveal data-delay="180" onClick={onLogin}>
              View Health Details<ArrowRight />
            </button>
          </div>
        </div>
      </div>
    </section>
  );
}
