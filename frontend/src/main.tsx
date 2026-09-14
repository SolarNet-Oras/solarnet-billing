import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import AppErrorBoundary from './components/AppErrorBoundary.tsx'

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    void navigator.serviceWorker.register('/solarnet-sw.js', { updateViaCache: 'none' }).then((registration) => registration.update());
  });
}

const attendance = window.location.hostname.startsWith('attendance.')
  || window.location.pathname === '/attendance-app'
  || window.location.pathname.startsWith('/attendance-app/');

async function boot(): Promise<void> {
  const App = attendance
    ? (await import('./AttendanceRoot.tsx')).default
    : (await import('./App.tsx')).default;
  createRoot(document.getElementById('root')!).render(
    <StrictMode><AppErrorBoundary><App /></AppErrorBoundary></StrictMode>,
  );
}

void boot();
