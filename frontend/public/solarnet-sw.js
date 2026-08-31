const CACHE_NAME = 'solarnet-application-shell-v5';
const APP_SHELL = [
  '/',
  '/login',
  '/customer/login',
  '/solarnet-mark.svg',
  '/solarnet-company-logo-192.png',
  '/solarnet-company-logo-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)),
    )),
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Billing and customer API responses must never be cached by the browser.
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
    return;
  }

  event.respondWith(
    fetch(request).catch(() => caches.match(request).then((cached) => cached || caches.match(url.pathname.startsWith('/customer/') ? '/customer/login' : '/login'))),
  );
});

// Push payloads originate from the SolarNet server. They contain only a
// concise billing/service notice and a same-origin portal path; no API token
// or payment credential is ever stored in the service worker.
self.addEventListener('push', (event) => {
  let payload = {
    title: 'SolarNet account alert',
    body: 'Open your SolarNet account to review this update.',
    url: '/customer/dashboard',
    tag: 'solarnet-account-alert',
    type: 'ACCOUNT_ALERT',
  };

  try {
    if (event.data) payload = { ...payload, ...event.data.json() };
  } catch {
    // A malformed push must still show a safe, generic account notice.
  }

  const isSuspension = payload.type === 'SERVICE_SUSPENDED' || payload.type === 'SUSPENSION_WARNING';
  const isRestored = payload.type === 'SERVICE_RESTORED';
  const notification = self.registration.showNotification(payload.title, {
    body: payload.body,
    icon: typeof payload.icon === 'string' && payload.icon.startsWith('/') ? payload.icon : '/solarnet-mark.svg',
    badge: '/solarnet-company-logo-192.png',
    tag: payload.tag,
    renotify: true,
    requireInteraction: isSuspension,
    vibrate: isSuspension ? [250, 120, 250] : [150],
    data: { url: payload.url, type: payload.type },
    actions: [{ action: 'open-account', title: isSuspension ? 'Review and pay' : 'Open account' }],
  });

  const badgeNavigator = self.navigator;
  const badge = isSuspension && typeof badgeNavigator.setAppBadge === 'function'
    ? badgeNavigator.setAppBadge(1)
    : isRestored && typeof badgeNavigator.clearAppBadge === 'function'
      ? badgeNavigator.clearAppBadge()
      : Promise.resolve();

  event.waitUntil(Promise.all([notification, badge]));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const requestedUrl = new URL(event.notification.data?.url || '/customer/dashboard', self.location.origin);
  const allowedPaths = ['/customer/dashboard', '/customer/billing'];
  // Never let a notification payload navigate a customer to another origin or
  // an unexpected page. The backend applies the same allow-list before send.
  const targetUrl = requestedUrl.origin === self.location.origin && allowedPaths.includes(requestedUrl.pathname)
    ? requestedUrl.href
    : new URL('/customer/dashboard', self.location.origin).href;

  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
    const existing = windows.find((client) => new URL(client.url).origin === self.location.origin);
    if (existing) {
      return existing.navigate(targetUrl).then(() => existing.focus());
    }
    return self.clients.openWindow(targetUrl);
  }));
});
