import { Suspense, useEffect, useRef, useState } from 'react';
import ErrorBoundary from './ErrorBoundary';
import { SectionSkeleton } from './Skeleton';

// Defers mounting (and downloading) a lazy section until it is about to
// scroll into view. Anchor ids stay in the DOM so nav links still work.
export default function LazySection({ id, children, skeleton, rootMargin = '400px 0px' }) {
  const ref = useRef(null);
  const [visible, setVisible] = useState(() => !('IntersectionObserver' in window));

  useEffect(() => {
    if (visible) return undefined;
    const el = ref.current;
    // Jumping straight to an anchor (e.g. /#track) should load it immediately.
    if (id && window.location.hash === `#${id}`) {
      setVisible(true);
      return undefined;
    }
    const io = new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) {
        setVisible(true);
        io.disconnect();
      }
    }, { rootMargin });
    io.observe(el);
    const onHash = () => window.location.hash === `#${id}` && setVisible(true);
    window.addEventListener('hashchange', onHash);
    return () => {
      io.disconnect();
      window.removeEventListener('hashchange', onHash);
    };
  }, [visible, id, rootMargin]);

  const fallback = skeleton || <SectionSkeleton />;
  return (
    <div ref={ref} id={visible ? undefined : id} className="ux-lazy-section">
      {visible ? (
        <ErrorBoundary>
          <Suspense fallback={fallback}>{children}</Suspense>
        </ErrorBoundary>
      ) : fallback}
    </div>
  );
}
