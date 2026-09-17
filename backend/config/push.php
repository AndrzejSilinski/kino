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

    // Ile urządzeń (tokenów) może mieć jedno konto — najstarsze ponad limit są usuwane.
    'max_devices_per_user' => (int) env('PUSH_MAX_DEVICES_PER_USER', 20),

];
