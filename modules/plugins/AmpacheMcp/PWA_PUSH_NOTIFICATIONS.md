# PWA Push Notifications

This plugin can notify a separate PWA music player when the MCP server updates the persistent `AI Queue` playlist.

The service worker must be registered by the PWA's own origin. The push sender does not need to live on that same origin. The PWA creates a browser `PushSubscription`, sends that subscription to the Ampache MCP plugin, and the plugin stores it in a JSON file. When `ampache-temporary-playlist` updates `AI Queue`, the plugin sends Web Push messages to the stored subscriptions.

## Ampache MCP Configuration

Configure these values in `modules/plugins/AmpacheMcp/config.php`:

```php
'push_subscription_file' => __DIR__ . '/data/push-subscriptions.json',
'vapid_subject' => 'mailto:you@example.com',
'vapid_public_key' => 'BASE64URL_PUBLIC_KEY',
'vapid_private_key' => 'BASE64URL_PRIVATE_KEY',
'push_click_url' => 'https://player.example.com/ai-queue',
```

The subscription file's parent directory must be writable by the PHP/web-server user. The plugin includes `data/.gitignore` so local subscription files are not committed.

If the VAPID keys are blank, the plugin still accepts subscriptions but skips sending push notifications. This is useful while wiring the PWA.

To verify the subscription JSON file is writable on the server, open:

```text
https://music.example.com/AmpacheMcp/push/check
```

The page reports only filesystem diagnostics. It does not print stored subscription contents.

## Generate VAPID Keys

Generate one P-256 key pair and keep it stable. Existing subscriptions are tied to the public key used when subscribing.

One simple Node.js script:

```js
const { createECDH } = require("node:crypto");

function base64url(buffer) {
  return Buffer.from(buffer)
    .toString("base64")
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/g, "");
}

const ecdh = createECDH("prime256v1");
ecdh.generateKeys();

console.log("vapid_public_key=" + base64url(ecdh.getPublicKey()));
console.log("vapid_private_key=" + base64url(ecdh.getPrivateKey()));
```

Run it once:

```bash
node generate-vapid.js
```

## PWA Client Flow

The PWA should:

1. Register its service worker.
2. Fetch the MCP VAPID public key.
3. Ask the browser for notification permission.
4. Create a `PushSubscription` using that public key.
5. Send the subscription JSON to the MCP plugin.
6. On notification click, open the PWA route that loads `AI Queue` through Subsonic.

Example client code:

```js
const MCP_BASE_URL = "https://music.example.com/AmpacheMcp";
const MCP_USER_TOKEN = "your-shared-user-token";

function base64urlToUint8Array(value) {
  const padding = "=".repeat((4 - (value.length % 4)) % 4);
  const base64 = (value + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(base64);
  return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

export async function enableAiQueueNotifications() {
  if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
    throw new Error("Push notifications are not supported by this browser.");
  }

  const registration = await navigator.serviceWorker.register("/service-worker.js");
  const permission = await Notification.requestPermission();
  if (permission !== "granted") {
    throw new Error("Notification permission was not granted.");
  }

  const keyResponse = await fetch(`${MCP_BASE_URL}/push/public-key`);
  if (!keyResponse.ok) {
    throw new Error("Unable to load the VAPID public key.");
  }
  const { publicKey, configured } = await keyResponse.json();
  if (!configured || !publicKey) {
    throw new Error("Ampache MCP push notifications are not configured.");
  }

  let subscription = await registration.pushManager.getSubscription();
  if (!subscription) {
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: base64urlToUint8Array(publicKey),
    });
  }

  const subscribeResponse = await fetch(`${MCP_BASE_URL}/push/subscribe`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "x-user-token": MCP_USER_TOKEN,
    },
    body: JSON.stringify(subscription),
  });

  if (!subscribeResponse.ok) {
    throw new Error("Unable to save the push subscription.");
  }

  return subscribeResponse.json();
}
```

## Service Worker

The service worker file must be served from the PWA origin, for example:

```text
https://player.example.com/service-worker.js
```

Example service worker:

```js
self.addEventListener("push", (event) => {
  let payload = {};
  if (event.data) {
    try {
      payload = event.data.json();
    } catch {
      payload = { body: event.data.text() };
    }
  }

  const title = payload.title || "AI Queue ready";
  const options = {
    body: payload.body || "Your AI Queue playlist has been updated.",
    tag: payload.tag || "ampache-ai-queue",
    data: {
      url: payload.url || "/",
      ...(payload.data || {}),
    },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();

  const targetUrl = new URL(event.notification.data?.url || "/", self.location.origin).href;

  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if ("focus" in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }

      return self.clients.openWindow(targetUrl);
    })
  );
});
```

## Unsubscribe

When the user disables notifications, unsubscribe in the browser and tell the MCP plugin to remove the stored endpoint:

```js
export async function disableAiQueueNotifications() {
  const registration = await navigator.serviceWorker.ready;
  const subscription = await registration.pushManager.getSubscription();
  if (!subscription) {
    return { status: "not_subscribed" };
  }

  const endpoint = subscription.endpoint;
  await subscription.unsubscribe();

  const response = await fetch(`${MCP_BASE_URL}/push/unsubscribe`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "x-user-token": MCP_USER_TOKEN,
    },
    body: JSON.stringify({ endpoint }),
  });

  return response.json();
}
```

## Test Notification

After subscribing, send a test notification:

```bash
curl -sS -X POST https://music.example.com/AmpacheMcp/push/test \
  -H 'Content-Type: application/json' \
  -H 'x-user-token: your-shared-user-token' \
  --data '{"title":"Ampache MCP test","body":"Push notifications are connected."}'
```

## Security Notes

Do not expose `user_token` in a public client if untrusted users can access the PWA. For a personal single-user PWA, embedding the token may be acceptable. For a shared PWA, put a small authenticated backend in front of `/push/subscribe` and `/push/unsubscribe`, or issue a narrower pairing token for notification setup.

The VAPID private key must stay on the MCP/plugin host. The PWA only receives the VAPID public key.
