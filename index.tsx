import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { AuthProvider } from './AuthContext';
import App from './App';
import './index.css';

const rootElement = document.getElementById('root');
if (!rootElement) {
  throw new Error("Could not find root element to mount to");
}

const root = ReactDOM.createRoot(rootElement);
root.render(
  <React.StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <App />
      </AuthProvider>
    </BrowserRouter>
  </React.StrictMode>
);

// Register Service Worker for PWA
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js')
      .then((registration) => {
        console.log('SW registered:', registration);

        // Show "update available" banner when a new SW is waiting
        registration.addEventListener('updatefound', () => {
          const newWorker = registration.installing;
          if (!newWorker) return;

          newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              // New SW is waiting — show banner so user can refresh
              const banner = document.createElement('div');
              banner.id = 'sw-update-banner';
              banner.style.cssText = `
                position: fixed; bottom: 0; left: 0; right: 0;
                background: #4f46e5; color: white;
                padding: 12px 20px; text-align: center;
                font-family: 'Outfit', sans-serif; font-size: 14px; z-index: 99999;
                display: flex; align-items: center; justify-content: center; gap: 12px;
                box-shadow: 0 -2px 12px rgba(0,0,0,0.15);
              `;
              banner.innerHTML = `
                <span>New version available</span>
                <button id="sw-reload-btn" style="
                  background: white; color: #4f46e5; border: none;
                  padding: 6px 16px; border-radius: 6px;
                  font-weight: 600; cursor: pointer; font-family: inherit;
                ">Update & Reload</button>
              `;
              document.body.appendChild(banner);
              document.getElementById('sw-reload-btn')?.addEventListener('click', () => {
                newWorker.postMessage({ type: 'SKIP_WAITING' });
                window.location.reload();
              });
            }
          });
        });
      })
      .catch((err) => {
        console.error('SW registration failed:', err);
      });
  });
}