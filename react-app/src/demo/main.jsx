import React from 'react';
import { createRoot } from 'react-dom/client';
import 'bootstrap/dist/css/bootstrap.min.css';
import '../styles/fonts.css';
import '../styles/app.css';
import '../styles/ui.css';
import '../styles/mobile.css';
import '../styles/landing.css';
import '../styles/feedback.css';
import './demo.css';
import App from '../App';
import { ErrorBoundary, ToastProvider } from '../components/feedback';
import ScenarioPanel from './ScenarioPanel';
import { installMockApi, settings } from './mockApi';

installMockApi();
const query = new URLSearchParams(window.location.search);
if (query.get('scenario') === 'server-down') settings.serverDown = true;

createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ToastProvider>
      <ErrorBoundary title="The page couldn’t load">
        <App />
      </ErrorBoundary>
      <ScenarioPanel signedIn={query.get('signedin') === '1'} />
    </ToastProvider>
  </React.StrictMode>
);
