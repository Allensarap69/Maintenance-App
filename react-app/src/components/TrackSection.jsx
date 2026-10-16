import { ArrowRight, Check, Motorbike, Wrench } from 'lucide-react';

const TRACK_STEPS = [
  { state: 'done', icon: Check, label: <>Booking<br />Confirmed</> },
  { state: 'done', icon: Check, label: <>Motorcycle<br />Received</> },
  { state: 'current', icon: Wrench, label: <>Under<br />Maintenance</> },
  { state: 'todo', num: '4', label: 'Inspection' },
  { state: 'todo', num: '5', label: <>Service<br />Complete</> },
];

export default function TrackSection({ onLogin }) {
  return (
    <section className="track-section" id="track">
      <div className="container">
        <div className="row align-items-center g-5">
          <div className="col-lg-5" data-reveal>
            <span className="section-eyebrow">Track Your Service</span>
            <h2 className="section-title">Follow Your Motorcycle's Journey</h2>
            <p className="section-subtitle">
              Stay updated on the progress of your service from start to finish.
            </p>
            <button className="btn btn-cta-primary mt-3" onClick={onLogin}>
              Check Service Status<ArrowRight />
            </button>
          </div>
          <div className="col-lg-7">
            <div className="track-card" data-reveal data-delay="120">
              <div className="track-card-head">
                <div className="t-icon"><Motorbike /></div>
                <div>
                  <div className="t-name">Service Booking</div>
                  <div className="t-sub">Preventive Maintenance</div>
                </div>
                <span className="track-pill">In Progress</span>
              </div>
              <div className="track-steps">
                {TRACK_STEPS.map(({ state, icon: Icon, num, label }, i) => (
                  <div className={`track-step ${state}`} key={i}>
                    <div className="track-node">{Icon ? <Icon /> : num}</div>
                    <div className="track-label">{label}</div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
