import { useEffect, useState } from 'react';

// React replacement for Bootstrap's modal JS — same classes are kept so
// landing.css (.modal-blur-effect, .modal-backdrop.show) works unchanged.
export default function Modal({ id, labelId, onClose, children }) {
  const [shown, setShown] = useState(false);

  useEffect(() => {
    const raf = requestAnimationFrame(() => setShown(true));
    const onKey = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';
    return () => {
      cancelAnimationFrame(raf);
      document.removeEventListener('keydown', onKey);
      document.body.classList.remove('modal-open');
      document.body.style.overflow = '';
    };
  }, [onClose]);

  return (
    <>
      <div
        className={`modal fade modal-blur-effect${shown ? ' show' : ''}`}
        id={id}
        tabIndex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby={labelId}
        style={{ display: 'block' }}
        onClick={onClose}
      >
        <div className="modal-dialog modal-dialog-centered" onClick={(e) => e.stopPropagation()}>
          <div className="modal-content">
            {children}
          </div>
        </div>
      </div>
      <div className={`modal-backdrop fade${shown ? ' show' : ''}`}></div>
    </>
  );
}
