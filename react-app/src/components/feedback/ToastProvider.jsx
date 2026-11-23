import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { X } from 'lucide-react';
import { VARIANT_ICONS } from './icons';

const ToastContext = createContext(null);

const DEFAULT_DURATION = { success: 4000, info: 5000, tip: 7000, warning: 6000, error: 8000, loading: 0 };
const MAX_TOASTS = 4;
let nextId = 1;

function Toast({ toast, onDismiss }) {
  const { id, variant, title, message, action, duration } = toast;
  const Icon = VARIANT_ICONS[variant] || VARIANT_ICONS.info;
  const [leaving, setLeaving] = useState(false);
  const [paused, setPaused] = useState(false);
  const remaining = useRef(duration);
  const startedAt = useRef(0);

  const close = useCallback(() => {
    setLeaving(true);
    setTimeout(() => onDismiss(id), 220);
  }, [id, onDismiss]);

  useEffect(() => {
    remaining.current = duration;
  }, [duration, variant]);

  useEffect(() => {
    if (!duration || paused) return undefined;
    startedAt.current = Date.now();
    const t = setTimeout(close, remaining.current);
    return () => {
      clearTimeout(t);
      remaining.current -= Date.now() - startedAt.current;
    };
  }, [duration, paused, close, variant]);

  return (
    <div
      className={`ux-toast ux-toast--${variant}${leaving ? ' is-leaving' : ''}`}
      role={variant === 'error' ? 'alert' : 'status'}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
    >
      <span className="ux-toast__icon"><Icon className={variant === 'loading' ? 'ux-spin' : undefined} /></span>
      <div className="ux-toast__body">
        {title && <strong className="ux-toast__title">{title}</strong>}
        {message && <p className="ux-toast__msg">{message}</p>}
        {action && (
          <button
            type="button"
            className="ux-toast__action"
            onClick={() => { action.onClick(); close(); }}
          >
            {action.label}
          </button>
        )}
      </div>
      {variant !== 'loading' && (
        <button type="button" className="ux-toast__close" aria-label="Dismiss notification" onClick={close}>
          <X />
        </button>
      )}
      {duration > 0 && (
        <span
          key={variant}
          className="ux-toast__progress"
          style={{ animationDuration: `${duration}ms`, animationPlayState: paused ? 'paused' : 'running' }}
        />
      )}
    </div>
  );
}

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);

  const dismiss = useCallback((id) => {
    setToasts((list) => (id == null ? [] : list.filter((t) => t.id !== id)));
  }, []);

  const show = useCallback((variant, title, opts = {}) => {
    const id = opts.id ?? nextId++;
    const toast = {
      id,
      variant,
      title,
      message: opts.message,
      action: opts.action,
      duration: opts.duration ?? DEFAULT_DURATION[variant] ?? 5000,
    };
    setToasts((list) => {
      const rest = list.filter((t) => t.id !== id);
      return [...rest, toast].slice(-MAX_TOASTS);
    });
    return id;
  }, []);

  const update = useCallback((id, variant, title, opts = {}) => show(variant, title, { ...opts, id }), [show]);

  const api = useMemo(() => ({
    show,
    update,
    dismiss,
    success: (title, opts) => show('success', title, opts),
    error: (title, opts) => show('error', title, opts),
    warning: (title, opts) => show('warning', title, opts),
    info: (title, opts) => show('info', title, opts),
    tip: (title, opts) => show('tip', title, opts),
    loading: (title, opts) => show('loading', title, opts),
  }), [show, update, dismiss]);

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="ux-toast-stack" aria-live="polite" aria-relevant="additions">
        {toasts.map((t) => <Toast key={t.id} toast={t} onDismiss={dismiss} />)}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>');
  return ctx;
}
