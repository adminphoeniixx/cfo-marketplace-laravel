import { onMounted, ref } from 'vue';

/**
 * Browser push, end to end: register the service worker, ask permission, hand
 * the resulting subscription to the server.
 *
 * Push only exists on HTTPS (localhost excepted) and in browsers that ship a
 * service worker, so every path here has to survive not being available.
 */
export function useWebPush(publicKey: string | null) {
    const supported = ref(false);
    const permission = ref<NotificationPermission>('default');
    const subscribed = ref(false);
    const busy = ref(false);
    const error = ref<string | null>(null);

    const decodeKey = (key: string) => {
        const padded = (key + '='.repeat((4 - (key.length % 4)) % 4))
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        const raw = atob(padded);

        return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
    };

    const csrf = () =>
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content.trim() ?? '';

    const registration = async () =>
        navigator.serviceWorker.register('/sw.js', { scope: '/' });

    onMounted(async () => {
        supported.value =
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window &&
            Boolean(publicKey);

        if (!supported.value) {
            return;
        }

        permission.value = Notification.permission;

        try {
            const existing = await (
                await registration()
            ).pushManager.getSubscription();

            subscribed.value = existing !== null;
        } catch {
            // A blocked or unregistered worker just means "not subscribed".
            subscribed.value = false;
        }
    });

    const subscribe = async () => {
        if (!supported.value || !publicKey) {
            return;
        }

        busy.value = true;
        error.value = null;

        try {
            permission.value = await Notification.requestPermission();

            if (permission.value !== 'granted') {
                error.value =
                    'Your browser blocked notifications. Allow them in the site settings and try again.';

                return;
            }

            const subscription = await (
                await registration()
            ).pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: decodeKey(publicKey),
            });

            const payload = subscription.toJSON();

            const response = await fetch('/admin/notifications/push', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    endpoint: payload.endpoint,
                    keys: payload.keys,
                    content_encoding:
                        (
                            PushManager as unknown as {
                                supportedContentEncodings?: string[];
                            }
                        ).supportedContentEncodings?.[0] ?? 'aesgcm',
                }),
            });

            if (!response.ok) {
                throw new Error('The server refused the subscription.');
            }

            subscribed.value = true;
        } catch (e) {
            error.value =
                e instanceof Error
                    ? e.message
                    : 'Could not turn on browser notifications.';
        } finally {
            busy.value = false;
        }
    };

    const unsubscribe = async () => {
        busy.value = true;
        error.value = null;

        try {
            const subscription = await (
                await registration()
            ).pushManager.getSubscription();

            if (subscription) {
                await fetch('/admin/notifications/push', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });

                await subscription.unsubscribe();
            }

            subscribed.value = false;
        } catch (e) {
            error.value =
                e instanceof Error
                    ? e.message
                    : 'Could not turn off browser notifications.';
        } finally {
            busy.value = false;
        }
    };

    return {
        supported,
        permission,
        subscribed,
        busy,
        error,
        subscribe,
        unsubscribe,
    };
}
