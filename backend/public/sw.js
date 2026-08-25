/*
 * MUWASCO HR - Service Worker
 *
 * Handles Web Push attendance reminders and notification clicks.
 * Registered from main.jsx via utils/pushNotifications.js; served from
 * the app root so its scope covers every SPA route (works both at a
 * domain root and under /hrdemo sub-path hosting).
 */

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

/**
 * Push payload contract (sent by backend ReminderTemplateService):
 * {
 *   "title": "🔔 Attendance Reminder",
 *   "body":  "Good morning John. Please remember to clock in for today.",
 *   "tag":   "attendance-clock-in-2026-08-24",
 *   "data":  { "url": "/attendance" }
 * }
 */
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (_) {
    data = { title: 'Notification', body: event.data ? event.data.text() : '' };
  }

  const iconUrl = new URL('favicon.ico', self.registration.scope).href;

  event.waitUntil(
    self.registration.showNotification(data.title || 'MUWASCO HR', {
      body: data.body || '',
      tag: data.tag, // replaces older same-day reminders instead of stacking
      icon: iconUrl,
      badge: iconUrl,
      vibrate: [200, 100, 200],
      requireInteraction: false,
      data: { url: (data.data && data.data.url) || 'attendance' },
    })
  );
});

/**
 * Clicking the notification focuses an existing app window (navigating
 * it to the Attendance page) or opens one. URL is resolved relative to
 * the SW scope so /hrdemo hosting keeps working.
 */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const targetUrl = new URL(
    (event.notification.data && event.notification.data.url) || 'attendance',
    self.registration.scope
  ).href;

  event.waitUntil(
    (async () => {
      const windowClients = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
      });

      // Prefer a window already inside the app scope.
      const appWindow = windowClients.find((client) =>
        client.url.startsWith(self.registration.scope)
      );

      if (appWindow) {
        await appWindow.focus();
        try {
          if ('navigate' in appWindow && appWindow.url !== targetUrl) {
            await appWindow.navigate(targetUrl);
          }
        } catch (_) {
          // Navigation can be rejected on some browsers after focus();
          // the focused window is still usable by the employee.
        }
        return;
      }

      return self.clients.openWindow(targetUrl);
    })()
  );
});
