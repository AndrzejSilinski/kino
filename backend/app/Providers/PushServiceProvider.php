<?php

declare(strict_types=1);

namespace App\Providers;

use App\Push\FcmPushSender;
use App\Push\GoogleAccessTokenProvider;
use App\Push\PushSender;
use App\Push\ServiceAccountCredentials;
use Illuminate\Support\ServiceProvider;

/**
 * Powiadomienia push (Etap 8, blok K). Singletony: token dostępu żyje w pamięci procesu workera
 * i jest odnawiany przed wygaśnięciem. Plik konta serwisowego czytany dopiero przy pierwszej wysyłce.
 */
final class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoogleAccessTokenProvider::class, fn (): GoogleAccessTokenProvider => new GoogleAccessTokenProvider(
            static fn (): ServiceAccountCredentials => ServiceAccountCredentials::fromFile(config('push.fcm.credentials')),
            (int) config('push.fcm.timeout_seconds'),
        ));

        $this->app->singleton(PushSender::class, fn ($app): PushSender => new FcmPushSender(
            $app->make(GoogleAccessTokenProvider::class),
            config('push.fcm.project_id'),
            (string) config('app.url'),
            (int) config('push.fcm.timeout_seconds'),
        ));
    }
}
