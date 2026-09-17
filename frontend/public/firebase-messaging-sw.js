/*
 * Service worker powiadomień push (Etap 8, blok L) — własny, BEZ importu Firebase.
 *
 * Firebase w stronie zakłada subskrypcję i wydaje token; tutaj tylko wyświetlamy powiadomienie
 * i obsługujemy kliknięcie. Plik musi leżeć pod /firebase-messaging-sw.js (domyślna ścieżka SDK).
 * Bez importScripts z gstatic.com: brak zależności od cudzej domeny i od CSP w service workerze.
 *
 * Wiadomość z FCM (HTTP v1, blok K) przychodzi jako JSON: { notification: {title, body}, data: {type, url}, … }.
 * Treść nie ma danych osobowych (film i godzina) — i tak widać ją na zablokowanym ekranie.
 */
const FALLBACK_TITLE = 'Kino';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch {
    payload = {};
  }
  const notification = payload.notification || {};
  const data = payload.data || {};

  // userVisibleOnly: każde push MUSI pokazać powiadomienie — inaczej przeglądarka pokaże własne ogólne.
  event.waitUntil(self.registration.showNotification(notification.title || FALLBACK_TITLE, {
    body: notification.body || '',
    // tag = typ + adres: kolejne powiadomienie o tej samej rezerwacji zastępuje poprzednie zamiast się mnożyć.
    tag: data.type && data.url ? `${data.type}:${data.url}` : undefined,
    data: { url: safePath(data.url) },
    lang: 'pl',
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(safePath(event.notification.data && event.notification.data.url), self.location.origin);

  // Karta z tym adresem już otwarta -> na wierzch; w przeciwnym razie nowa karta. Bez navigate():
  // ten worker ma własny zakres (SDK Firebase) i nie kontroluje stron SPA, a navigate() działa tylko
  // dla kontrolowanych klientów.
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const existing = windows.find((client) => client.url === target.href && 'focus' in client);
    return existing ? existing.focus() : self.clients.openWindow(target.href);
  })());
});

/** Tylko ścieżka wewnątrz aplikacji: adres z wiadomości nie może otworzyć obcej strony. */
function safePath(url) {
  return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') && !url.includes('\\') ? url : '/account';
}
