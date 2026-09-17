<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\PushDevice;
use App\Models\User;
use App\Push\PushMessage;
use App\Push\PushResult;
use App\Push\PushSender;
use App\Push\PushTemporarilyUnavailableException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Kanał powiadomień push (Etap 8, blok K): jedna wiadomość na każde urządzenie użytkownika.
 *
 * Warunki wysyłki sprawdzane TUTAJ, w chwili wysyłki z kolejki, a nie przy wstawianiu zadania:
 * klient mógł w międzyczasie cofnąć zgodę albo wylogować urządzenie.
 *
 * Ponowienie: rzucamy wyjątek (kolejka ponowi całe powiadomienie) tylko wtedy, gdy ŻADNE urządzenie
 * go nie dostało, a przynajmniej jedno odmówiło chwilowo. Częściowy sukces kończy się ostrzeżeniem
 * w logu — ponowienie wysłałoby duplikat na urządzenia, które już je pokazały.
 * Nieważne tokeny usuwamy od razu (sprzątanie wymagane przez FCM).
 * Logi: identyfikator urządzenia (public_id) i kod błędu — nigdy token ani treść powiadomienia.
 */
final class PushChannel
{
    public function __construct(private readonly PushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notifiable->wantsPush() || ! method_exists($notification, 'toPush')) {
            return;
        }

        $message = $notification->toPush($notifiable);

        if (! $message instanceof PushMessage) {
            return;
        }

        $sent = 0;
        $retryable = 0;

        foreach ($notifiable->pushDevices()->orderBy('id')->get() as $device) {
            $result = $this->sender->send($message, $device->token);

            match ($result->status) {
                PushResult::SENT => $sent++,
                PushResult::RETRYABLE => $retryable++,
                PushResult::INVALID_TOKEN => $this->forget($device, $result),
                default => Log::warning('Powiadomienie push odrzucone przez FCM.', ['device' => $device->public_id, 'code' => $result->errorCode, 'type' => $message->type]),
            };
        }

        if ($sent === 0 && $retryable > 0) {
            throw new PushTemporarilyUnavailableException('FCM chwilowo niedostępny dla wszystkich urządzeń użytkownika.');
        }

        if ($retryable > 0) {
            Log::warning('Powiadomienie push nie dotarło do części urządzeń (chwilowy błąd FCM).', ['user' => $notifiable->id, 'sent' => $sent, 'failed' => $retryable, 'type' => $message->type]);
        }
    }

    private function forget(PushDevice $device, PushResult $result): void
    {
        Log::info('Usunięto urządzenie z nieważnym tokenem push.', ['device' => $device->public_id, 'code' => $result->errorCode]);
        $device->delete();
    }
}
