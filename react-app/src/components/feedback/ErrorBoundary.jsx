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
    return (
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
        <button type="button" className="btn btn-cta-outline ux-error-card__btn" onClick={this.retry}>
          <RefreshCw /> Try again
        </button>
      </div>
    );
  }
}
