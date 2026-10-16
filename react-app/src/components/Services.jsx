import { ArrowRight, Clock, Disc, Droplet, LifeBuoy, Wrench } from 'lucide-react';

const SERVICES = [
  { grad: 's-grad-1', icon: Droplet, name: 'Oil Change', desc: 'Keep your engine running smoothly.', price: '₱450', time: '30 mins', delay: 0 },
  { grad: 's-grad-2', icon: Disc, name: 'Brake Service', desc: 'Ensure your safety on every ride.', price: '₱800', time: '45 mins', delay: 60 },
  { grad: 's-grad-3', icon: LifeBuoy, name: 'Tire Service', desc: 'Better grip, safer every journey.', price: '₱700', time: '30 mins', delay: 120 },
  { grad: 's-grad-4', icon: Wrench, name: 'Full Maintenance', desc: 'Complete check and premium care.', price: '₱1,200', time: '90 mins', delay: 180 },
];

export default function Services({ onLogin }) {
  return (
    <section className="services-section" id="service-list">
      <div className="container">
        <div className="services-head" data-reveal>
          <span className="section-eyebrow">Our Services</span>
          <h2 className="section-title">Quality Care for Your Motorcycle</h2>
          <p className="section-subtitle">Choose from our range of professional motorcycle services and maintenance packages.</p>
        </div>

        <div className="row g-4 row-cols-1 row-cols-md-2 row-cols-lg-4">
          {SERVICES.map(({ grad, icon: Icon, name, desc, price, time, delay }) => (
            <div className="col" key={name}>
              <div className="service-card" data-reveal data-delay={delay || undefined}>
                <div className={`service-img ${grad}`}><div className="s-icon"><Icon /></div></div>
                <div className="service-body">
                  <h4 className="service-name">{name}</h4>
                  <p className="service-desc">{desc}</p>
                  <div className="service-meta">
                    <span className="service-price">{price}</span>
                    <span className="service-time"><Clock />{time}</span>
                  </div>
                  <button className="service-book" onClick={onLogin}>Book Now<ArrowRight /></button>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
