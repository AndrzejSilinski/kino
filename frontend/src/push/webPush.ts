/*
 * Web Push przez Firebase Cloud Messaging w przeglądarce (Etap 8, blok L).
 *
 * Z Firebase bierzemy tylko to, czego nie da się zrobić bez niego: token FCM. SDK zakłada
 * subskrypcję push przeglądarki kluczem VAPID i rejestruje ją w FCM (usługi Firebase Installations
 * i FCM Registrations — stąd dwie domeny w CSP). Samo wyświetlenie powiadomienia i kliknięcie
 * obsługuje NASZ service worker (public/firebase-messaging-sw.js) bez importu Firebase.
 *
 * Pakiety @firebase/app i @firebase/messaging zamiast zbiorczego "firebase": 9 pakietów zamiast 86
 * (bez gRPC i protobuf z Firestore), a to te same wersje, które przypina firebase 12.19.0.
 * Import dynamiczny: kod Firebase pobiera się dopiero na ekranie powiadomień, jak Stripe na płatności.
 *
 * Interfejs WebPushClient pozwala testom podstawić atrapę — jsdom nie ma service workerów ani PushManagera.
 */
import type { FirebaseWebConfig } from '@/api/clientConfig';

export interface WebPushClient {
  /** Czy ta przeglądarka w tym kontekście w ogóle obsłuży push (service worker, PushManager, IndexedDB, bezpieczny kontekst). */
  supported(): Promise<boolean>;
  permission(): NotificationPermission;
  requestPermission(): Promise<NotificationPermission>;
  /** Token FCM tej instalacji; SDK sam rejestruje /firebase-messaging-sw.js i odnawia subskrypcję. */
  token(config: FirebaseWebConfig): Promise<string>;
  deleteToken(config: FirebaseWebConfig): Promise<void>;
}

const APP_NAME = 'cinema-web-push';

async function messagingFor(config: FirebaseWebConfig) {
  const [{ getApps, initializeApp }, messaging] = await Promise.all([import('@firebase/app'), import('@firebase/messaging')]);
  const app = getApps().find((candidate) => candidate.name === APP_NAME) ?? initializeApp({
    apiKey: config.api_key,
    appId: config.app_id,
    projectId: config.project_id,
    messagingSenderId: config.messaging_sender_id,
  }, APP_NAME);
  return { module: messaging, instance: messaging.getMessaging(app) };
}

export const browserWebPush: WebPushClient = {
  async supported() {
    if (!window.isSecureContext || !('serviceWorker' in navigator) || !('Notification' in window)) {
      return false;
    }
    try {
      const { isSupported } = await import('@firebase/messaging');
      return await isSupported();
    } catch {
      return false;
    }
  },
  permission() {
    return Notification.permission;
  },
  requestPermission() {
    return Notification.requestPermission();
  },
  async token(config) {
    const { module, instance } = await messagingFor(config);
    return module.getToken(instance, { vapidKey: config.vapid_public_key });
  },
  async deleteToken(config) {
    const { module, instance } = await messagingFor(config);
    await module.deleteToken(instance);
  },
};
