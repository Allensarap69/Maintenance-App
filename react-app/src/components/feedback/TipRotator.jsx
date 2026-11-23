import { useEffect, useState } from 'react';
import { ChevronRight, Lightbulb } from 'lucide-react';

// Small rotating "Did you know?" card for helpful hints.
export default function TipRotator({ tips, interval = 6000, label = 'Tip' }) {
  const [i, setI] = useState(0);

  useEffect(() => {
    if (tips.length < 2) return undefined;
    const t = setInterval(() => setI((n) => (n + 1) % tips.length), interval);
    return () => clearInterval(t);
  }, [tips.length, interval, i]);

  return (
    <div className="ux-tip" role="note" aria-live="polite">
      <span className="ux-tip__icon"><Lightbulb /></span>
      <p className="ux-tip__text" key={i}><strong>{label}:</strong> {tips[i]}</p>
      {tips.length > 1 && (
        <button
          type="button"
          className="ux-tip__next"
          aria-label="Next tip"
          onClick={() => setI((n) => (n + 1) % tips.length)}
        >
          <ChevronRight />
        </button>
      )}
    </div>
  );
}
