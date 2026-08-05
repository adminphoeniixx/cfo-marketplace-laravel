/**
 * Service worker for admin push notifications.
 *
 * Kept deliberately small: it shows what the server sent and opens the linked
 * screen when clicked. It caches nothing — the panel is a normal server-driven
 * app and an out-of-date cached shell would be worse than no worker at all.
 */

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) =>
    event.waitUntil(self.clients.claim()),
);

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;

    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'CFO', body: event.data.text(), url: '/admin' };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title ?? 'CFO', {
            body: payload.body ?? '',
            // Same tag replaces the previous alert of that kind rather than
            // stacking twenty "new order" bubbles.
            tag: payload.tag ?? 'cfo',
            renotify: true,
            data: { url: payload.url ?? '/admin' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = event.notification.data?.url ?? '/admin';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                // Reuse a tab that already has the panel open.
                for (const client of clients) {
                    if (client.url.includes('/admin') && 'focus' in client) {
                        client.navigate(target);

                        return client.focus();
                    }
                }

                return self.clients.openWindow(target);
            }),
    );
});
