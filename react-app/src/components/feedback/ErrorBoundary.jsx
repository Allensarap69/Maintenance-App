import { Component } from 'react';
import { RefreshCw, TriangleAlert } from 'lucide-react';
import { allowLazyRetry } from './lazyWithRetry';

// Catches render errors and failed lazy-chunk downloads so one broken
// section shows a retry card instead of blanking the whole page.
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null, attempt: 0 };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    console.error('[ErrorBoundary]', error, info?.componentStack);
  }

  retry = () => {
    allowLazyRetry();
    this.setState((s) => ({ error: null, attempt: s.attempt + 1 }));
  };

  render() {
    if (!this.state.error) {
      return <div key={this.state.attempt} style={{ display: 'contents' }}>{this.props.children}</div>;
    }
    if (this.props.fallback) return this.props.fallback(this.retry);
    const chunkFailed = /dynamically imported module|Loading chunk|Failed to fetch/i.test(String(this.state.error?.message));
    // Some browsers cache a failed module fetch, so a second chunk failure
    // offers a full reload instead of another in-place retry.
    const reload = chunkFailed && this.state.attempt > 0;
    const card = (
      <div className="ux-error-card" role="alert">
        <span className="ux-error-card__icon"><TriangleAlert /></span>
        <div>
          <strong>{this.props.title || 'This section couldn’t load'}</strong>
          <p>
            {chunkFailed
              ? 'Your connection may have dropped while loading. Check your internet and try again.'
              : 'Something went wrong on our side. Try again, or refresh the page if it keeps happening.'}
          </p>
        </div>
        <button
          type="button" className="btn btn-cta-outline ux-error-card__btn"
          onClick={reload ? () => window.location.reload() : this.retry}
        >
          <RefreshCw /> {reload ? 'Reload page' : 'Try again'}
        </button>
        {this.props.onClose && (
          <button type="button" className="btn-close ux-error-card__close" aria-label="Close" onClick={this.props.onClose} />
        )}
      </div>
    );
    if (!this.props.onClose) return card;
    return (
      <>
        <div className="modal fade show modal-blur-effect" style={{ display: 'block' }} role="dialog" aria-modal="true">
          <div className="modal-dialog modal-dialog-centered">
            <div className="modal-content ux-modal-error">{card}</div>
          </div>
        </div>
        <div className="modal-backdrop fade show" />
      </>
    );
  }
}
