export interface BrowserPushSubscriptionPayload {
  endpoint: string;
  keys: {
    p256dh: string;
    auth: string;
  };
  contentEncoding?: 'aes128gcm' | 'aesgcm';
}

export const supportsWebPush = (): boolean => (
  'serviceWorker' in navigator
  && 'PushManager' in window
  && 'Notification' in window
);

const urlBase64ToUint8Array = (value: string): Uint8Array<ArrayBuffer> => {
  const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
  const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=');
  const raw = window.atob(padded);
  // Explicit ArrayBuffer keeps this compatible with TypeScript's newer
  // BufferSource generic, which does not accept SharedArrayBuffer here.
  const bytes = new Uint8Array(new ArrayBuffer(raw.length));

  for (let index = 0; index < raw.length; index += 1) {
    bytes[index] = raw.charCodeAt(index);
  }

  return bytes;
};

const payloadFromSubscription = (subscription: PushSubscription): BrowserPushSubscriptionPayload => {
  const json = subscription.toJSON();
  const p256dh = json.keys?.p256dh;
  const auth = json.keys?.auth;

  if (!subscription.endpoint || !p256dh || !auth) {
    throw new Error('The browser did not provide a complete notification subscription.');
  }

  return {
    endpoint: subscription.endpoint,
    keys: { p256dh, auth },
    contentEncoding: 'aes128gcm',
  };
};

export const currentWebPushSubscription = async (): Promise<BrowserPushSubscriptionPayload | null> => {
  if (!supportsWebPush()) return null;

  const registration = await navigator.serviceWorker.ready;
  const subscription = await registration.pushManager.getSubscription();
  return subscription ? payloadFromSubscription(subscription) : null;
};

/** This must be called from a customer button click so the browser can ask permission. */
export const subscribeToWebPush = async (publicKey: string): Promise<BrowserPushSubscriptionPayload> => {
  if (!supportsWebPush()) {
    throw new Error('Notifications are not supported by this browser.');
  }

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    throw new Error('Notification permission was not granted. You can enable it later in your browser settings.');
  }

  const registration = await navigator.serviceWorker.ready;
  let subscription = await registration.pushManager.getSubscription();
  if (!subscription) {
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(publicKey),
    });
  }

  return payloadFromSubscription(subscription);
};

export const unsubscribeFromWebPush = async (): Promise<string | null> => {
  if (!supportsWebPush()) return null;

  const registration = await navigator.serviceWorker.ready;
  const subscription = await registration.pushManager.getSubscription();
  if (!subscription) return null;

  const endpoint = subscription.endpoint;
  await subscription.unsubscribe();
  return endpoint;
};
