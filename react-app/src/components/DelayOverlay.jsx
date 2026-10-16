import { useEffect, useState } from 'react';
import { ShieldAlert } from 'lucide-react';

function formatTime(seconds) {
  if (seconds < 60) return seconds + 's';
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return mins + 'm ' + (secs < 10 ? '0' : '') + secs + 's';
}

// React port of security.php's render_login_delay() countdown overlay.
export default function DelayOverlay({ seconds, attempts, onDone }) {
  const [timeLeft, setTimeLeft] = useState(seconds);
  const [fading, setFading] = useState(false);

  useEffect(() => {
    if (timeLeft <= 0) {
      setFading(true);
      const t = setTimeout(onDone, 500);
      return () => clearTimeout(t);
    }
    const t = setTimeout(() => setTimeLeft((s) => s - 1), 1000);
    return () => clearTimeout(t);
  }, [timeLeft, onDone]);

  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(0, 0, 0, 0.85)',
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      zIndex: 10000, backdropFilter: 'blur(10px)',
      opacity: fading ? 0 : 1, transition: 'opacity 0.5s ease',
    }}>
      <div style={{
        background: 'linear-gradient(135deg, #1e293b, #0f172a)', padding: '40px 60px',
        borderRadius: '20px', textAlign: 'center', border: '2px solid rgba(245, 158, 11, 0.3)',
        boxShadow: '0 25px 50px rgba(0,0,0,0.5)', maxWidth: '400px',
      }}>
        <ShieldAlert style={{ width: '3rem', height: '3rem', color: '#f59e0b', marginBottom: '20px' }} />
        <h3 style={{ color: 'white', marginBottom: '15px' }}>Security Delay</h3>
        <p style={{ color: 'rgba(255,255,255,0.7)', marginBottom: '25px' }}>
          Too many failed attempts.<br />Attempt {attempts} of 5
        </p>
        <div style={{ background: 'rgba(0,0,0,0.3)', padding: '20px', borderRadius: '12px', marginBottom: '20px' }}>
          <div style={{ fontSize: '2.5rem', fontWeight: 700, color: '#f59e0b' }}>{formatTime(timeLeft)}</div>
          <div style={{ color: 'rgba(255,255,255,0.5)', fontSize: '0.9rem', marginTop: '5px' }}>remaining</div>
        </div>
        <p style={{ color: 'rgba(255,255,255,0.5)', fontSize: '0.85rem', marginBottom: 0 }}>
          This helps protect your account from unauthorized access.
        </p>
      </div>
    </div>
  );
}
