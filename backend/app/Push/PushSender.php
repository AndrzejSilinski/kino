<?php

declare(strict_types=1);

namespace App\Push;

/**
 * Port wysyłki push (Etap 8, blok K) — jak PaymentGateway dla Stripe'a. Kanał powiadomień zna
 * tylko ten interfejs; testy podstawiają atrapę i nigdy nie łączą się z Google.
 */
interface PushSender
{
    public function send(PushMessage $message, string $deviceToken): PushResult;
}
