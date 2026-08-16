export interface BrowserPushSubscriptionPayload {
  endpoint: string;
  keys: {
    p256dh: string;
    auth: string;
  };
  contentEncoding?: 'aes128gcm' | 'aesgcm';
  device_id?: string;
  platform?: string;
  browser?: string;
}

const DEVICE_ID_KEY = 'solarnet.customer_push_device_id';

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
    device_id: customerPushDeviceId(),
    platform: browserPlatform(),
    browser: browserName(),
  };
};

const subscriptionUsesPublicKey = (subscription: PushSubscription, publicKey: string): boolean => {
  const existingKey = subscription.options.applicationServerKey;
  if (!existingKey) return false;

  const expected = urlBase64ToUint8Array(publicKey);
  const actual = new Uint8Array(existingKey);

  return actual.length === expected.length && actual.every((byte, index) => byte === expected[index]);
};

const customerPushDeviceId = (): string | undefined => {
  try {
    const existing = window.localStorage.getItem(DEVICE_ID_KEY);
    if (existing) return existing;
    const value = window.crypto?.randomUUID?.();
    if (!value) return undefined;
    window.localStorage.setItem(DEVICE_ID_KEY, value);
    return value;
  } catch {
    // Device labels are optional metadata; a browser subscription endpoint is
    // still sufficient for delivery when local storage is unavailable.
    return undefined;
  }
};

const browserPlatform = (): string | undefined => {
  const source = navigator.userAgent || '';
  if (/android/i.test(source)) return 'Android';
  if (/iphone|ipad|ipod/i.test(source)) return 'iOS';
  if (/windows/i.test(source)) return 'Windows';
  if (/mac os/i.test(source)) return 'macOS';
  if (/linux/i.test(source)) return 'Linux';
  return undefined;
};

const browserName = (): string | undefined => {
  const source = navigator.userAgent || '';
  if (/edg\//i.test(source)) return 'Microsoft Edge';
  if (/firefox\//i.test(source)) return 'Firefox';
  if (/opr\//i.test(source)) return 'Opera';
  if (/chrome\//i.test(source) && !/edg\//i.test(source)) return 'Chrome';
  if (/safari\//i.test(source) && !/chrome\//i.test(source)) return 'Safari';
  return undefined;
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

  // Never show a second browser prompt when the customer has already allowed
  // notifications. This also lets the portal reconnect a permitted device.
  const permission = Notification.permission === 'granted'
    ? 'granted'
    : await Notification.requestPermission();
  if (permission !== 'granted') {
    throw new Error('Notification permission was not granted. You can enable it later in your browser settings.');
  }

  const registration = await navigator.serviceWorker.ready;
  let subscription = await registration.pushManager.getSubscription();
  if (subscription && !subscriptionUsesPublicKey(subscription, publicKey)) {
    // A VAPID key rotation requires a new browser subscription. This changes
    // only the local browser endpoint; it does not affect billing or router
    // configuration. A browser that requires a click will use the visible
    // reconnect button and show no second permission prompt.
    await subscription.unsubscribe();
    subscription = null;
  }
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
