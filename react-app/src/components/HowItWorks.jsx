import { ArrowRight, CalendarCheck, CircleCheck, Flag, Wrench } from 'lucide-react';

const STEPS = [
  { icon: CalendarCheck, color: 'blue', num: '01.', title: 'Book', desc: 'Choose your service, date and time.', delay: 0 },
  { icon: CircleCheck, color: 'blue', num: '02.', title: 'Confirm', desc: 'Wait for the dealership to confirm your appointment.', delay: 80 },
  { icon: Wrench, color: 'yellow', num: '03.', title: 'Service', desc: 'Bring your motorcycle to the dealership.', delay: 160 },
  { icon: Flag, color: 'yellow', num: '04.', title: 'Complete', desc: 'View your service record and updated status.', delay: 240 },
];

export default function HowItWorks({ onLogin }) {
  return (
    <section className="how-section" id="how-it-works">
      <div className="container">
        <div className="row align-items-center g-4">
          <div className="col-lg-3 how-left" data-reveal>
            <h2 className="section-title">How It Works</h2>
            <p className="section-subtitle">Getting your motorcycle service is easy in just 4 simple steps.</p>
            <button className="btn btn-cta-primary" onClick={onLogin}>
              Book a Service<ArrowRight />
            </button>
          </div>
          <div className="col-lg-9">
            <div className="row steps-row">
              {STEPS.map(({ icon: Icon, color, num, title, desc, delay }) => (
                <div className="col-lg-3 col-md-6 step-item" data-reveal data-delay={delay || undefined} key={num}>
                  <div className={`step-icon ${color}`}><Icon /></div>
                  <h4 className="step-title"><span className="s-num">{num}</span>{title}</h4>
                  <p className="step-desc">{desc}</p>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
