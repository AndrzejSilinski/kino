<?php

declare(strict_types=1);

namespace App\Push;

use RuntimeException;

/**
 * Żadne urządzenie nie dostało powiadomienia z powodu chwilowego błędu FCM albo sieci.
 * Rzucony z kanału oddaje zadanie kolejce do ponowienia (UsesRetryPolicy) — bez ryzyka
 * duplikatu, bo nikt jeszcze niczego nie dostał.
 */
final class PushTemporarilyUnavailableException extends RuntimeException {}
