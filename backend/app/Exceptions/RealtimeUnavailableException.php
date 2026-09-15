<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Aktywny broadcaster nie potrafi podpisać subskrypcji kanału.
 *
 * Dzieje się tak przy BROADCAST_CONNECTION=log albo null. NullBroadcaster
 * zwróciłby pustą odpowiedź, którą klient Pushera wziąłby za sukces
 * i utknął na nieudanej subskrypcji. 503 mówi wprost: funkcja czasu
 * rzeczywistego jest wyłączona, plan sali trzeba odświeżać przez REST.
 */
final class RealtimeUnavailableException extends CinemaException
{
    public function __construct()
    {
        parent::__construct('Aktualizacje na żywo są chwilowo niedostępne.');
    }

    public function status(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return 'REALTIME_UNAVAILABLE';
    }
}
