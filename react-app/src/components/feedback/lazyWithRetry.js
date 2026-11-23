import { createElement, lazy } from 'react';

// React.lazy caches a rejected import forever, so an ErrorBoundary
// "Try again" would keep failing. A failed lazy component is replaced
// with a fresh one only after an explicit retry (allowLazyRetry), so the
// failure still reaches the boundary instead of looping in Suspense.
let retryGeneration = 0;

export function allowLazyRetry() {
  retryGeneration += 1;
}

export default function lazyWithRetry(factory) {
  let failedAt = null;
  const make = () => lazy(() => factory().catch((err) => {
    failedAt = retryGeneration;
    throw err;
  }));
  let Lazy = make();

  const Retryable = (props) => {
    if (failedAt !== null && failedAt !== retryGeneration) {
      failedAt = null;
      Lazy = make();
    }
    return createElement(Lazy, props);
  };
  Retryable.preload = factory;
  return Retryable;
}
