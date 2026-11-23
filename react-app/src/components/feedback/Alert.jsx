import { X } from 'lucide-react';
import { VARIANT_ICONS } from './icons';

// Inline, in-context message (form errors, success confirmations, tips).
export default function Alert({ variant = 'info', title, children, action, onDismiss, className = '', shakeKey }) {
  const Icon = VARIANT_ICONS[variant] || VARIANT_ICONS.info;
  return (
    <div
      key={shakeKey}
      className={`ux-alert ux-alert--${variant}${shakeKey ? ' ux-shake' : ''} ${className}`}
      role={variant === 'error' ? 'alert' : 'status'}
      data-no-toast
    >
      <span className="ux-alert__icon"><Icon /></span>
      <div className="ux-alert__body">
        {title && <strong className="ux-alert__title">{title}</strong>}
        {children && <div className="ux-alert__msg">{children}</div>}
        {action && (
          <button type="button" className="ux-alert__action" onClick={action.onClick}>{action.label}</button>
        )}
      </div>
      {onDismiss && (
        <button type="button" className="ux-alert__close" aria-label="Dismiss" onClick={onDismiss}><X /></button>
      )}
    </div>
  );
}
