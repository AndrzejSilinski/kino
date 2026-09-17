<?php

declare(strict_types=1);

namespace App\Push;

/** Źródło tokenu dostępu dla FCM (Etap 8, blok K); w testach atrapa bez kryptografii i sieci. */
interface AccessTokenSource
{
    /** @throws PushConfigurationException|PushTemporarilyUnavailableException */
    public function token(): string;

    public function invalidate(): void;
}
