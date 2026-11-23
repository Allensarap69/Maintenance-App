// Shimmer placeholders shown while lazy chunks/sections load.
export function Skeleton({ width = '100%', height = 14, radius = 8, className = '', style }) {
  return (
    <span
      className={`ux-skeleton ${className}`}
      style={{ width, height, borderRadius: radius, ...style }}
      aria-hidden="true"
    />
  );
}

export function SectionSkeleton({ cards = 3, minHeight = 420 }) {
  return (
    <section className="ux-section-skeleton" style={{ minHeight }} aria-busy="true" aria-label="Loading section">
      <div className="container">
        <div className="ux-section-skeleton__head">
          <Skeleton width={120} height={12} />
          <Skeleton width="min(420px, 80%)" height={30} radius={10} />
          <Skeleton width="min(560px, 90%)" height={14} />
        </div>
        <div className="ux-section-skeleton__grid" style={{ '--cards': cards }}>
          {Array.from({ length: cards }, (_, i) => (
            <div className="ux-skeleton-card" key={i}>
              <Skeleton width={44} height={44} radius={12} />
              <Skeleton width="70%" height={16} />
              <Skeleton width="100%" height={12} />
              <Skeleton width="85%" height={12} />
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

export function ModalSkeleton() {
  return (
    <>
      <div className="modal fade show modal-blur-effect" style={{ display: 'block' }} aria-busy="true" aria-label="Loading">
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content ux-modal-skeleton">
            <div className="ux-modal-skeleton__visual" />
            <div className="ux-modal-skeleton__panel">
              <Skeleton width="60%" height={26} radius={10} />
              <Skeleton width="80%" height={12} />
              <Skeleton height={42} radius={10} style={{ marginTop: 18 }} />
              <Skeleton height={42} radius={10} />
              <Skeleton height={44} radius={10} style={{ marginTop: 12 }} />
            </div>
          </div>
        </div>
      </div>
      <div className="modal-backdrop fade show" />
    </>
  );
}
