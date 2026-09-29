<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Powiadomienia push (Etap 8, blok K)
|--------------------------------------------------------------------------
|
| Wysyłka przez Firebase Cloud Messaging HTTP v1 z tokenem OAuth konta
| serwisowego (własny podpis JWT, bez google/auth — decyzja z bloku K).
|
| Plik konta serwisowego to SEKRET (klucz prywatny RSA): leży poza gitem,
| montowany do kontenerów tylko do odczytu, a tu jest wyłącznie jego ścieżka.
| Bez PUSH_ENABLED=true kanał push nie jest w ogóle wybierany — aplikacja
| działa jak przed Etapem 8, a testy nigdy nie łączą się z Google.
|
*/

return [

    'enabled' => (bool) env('PUSH_ENABLED', false),

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),

        // Ścieżka WEWNĄTRZ kontenera do pliku JSON konta serwisowego Firebase.
        'credentials' => env('FCM_CREDENTIALS'),

        'timeout_seconds' => (int) env('FCM_TIMEOUT_SECONDS', 10),
    ],

    // Konfiguracja aplikacji WEB Firebase i publiczny klucz VAPID (Etap 8, blok L). Wartości JAWNE
    // z natury — trafiają do przeglądarki przez GET /api/v1/client-config. Bez kompletu klient
    // widzi push jako wyłączony, nawet przy PUSH_ENABLED=true.
    'web' => [
        'api_key' => env('FIREBASE_WEB_API_KEY'),
        'app_id' => env('FIREBASE_WEB_APP_ID'),
        'messaging_sender_id' => env('FIREBASE_MESSAGING_SENDER_ID'),
        'vapid_public_key' => env('FIREBASE_VAPID_PUBLIC_KEY'),
    ],

    // Konfiguracja aplikacji ANDROID Firebase (Etap 9, blok K). Aplikacja mobilna bierze swoją
    // konfigurację z google-services.json wkompilowanego w APK i NIE potrzebuje jej z serwera —
    // te wartości służą do SPRAWDZENIA, czy telefon i serwer mówią o tym samym projekcie.
    // Bez tego niezgodność kończy się ciszą: token zarejestrowany przez aplikację jest poprawny,
    // serwer wysyła bez błędu, a powiadomienie nie dochodzi do nikogo.
    'android' => [
        'app_id' => env('FIREBASE_ANDROID_APP_ID'),
        'package_name' => env('FIREBASE_ANDROID_PACKAGE_NAME', 'pl.silinski.cinema'),
    ],

    // Ile urządzeń (tokenów) może mieć jedno konto — najstarsze ponad limit są usuwane.
    'max_devices_per_user' => (int) env('PUSH_MAX_DEVICES_PER_USER', 20),

];
