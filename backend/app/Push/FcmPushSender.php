<?php

declare(strict_types=1);

namespace App\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Wysyłka przez FCM HTTP v1 (Etap 8, blok K): POST /v1/projects/{projekt}/messages:send.
 *
 * Wiadomość "notification" (tytuł i treść) + data z typem i ścieżką do otwarcia. Wartości data
 * muszą być tekstem (liczba daje 400 INVALID_ARGUMENT). webpush.fcm_options.link dokładamy tylko
 * dla adresu HTTPS — FCM obsługuje kliknięcie wyłącznie dla bezpiecznych adresów; na
 * http://localhost kliknięcie obsługuje nasz service worker z data.url (blok L).
 *
 * MAPOWANIE BŁĘDÓW (dokumentacja FCM "Error codes" i "Manage tokens"):
 *   404 / UNREGISTERED           -> token nieważny, usuwamy urządzenie,
 *   403 / SENDER_ID_MISMATCH     -> token z innego projektu Firebase, nigdy nie zadziała — usuwamy,
 *   400 / INVALID_ARGUMENT       -> token nieważny TYLKO gdy błąd dotyczy tokenu; dokumentacja
 *                                   zastrzega, że ten sam kod oznacza też błąd treści wiadomości,
 *   401                          -> token dostępu unieważniony: nowy i JEDNA ponowna próba,
 *   429 / 500 / 503, brak sieci  -> do ponowienia (Retry-After, jeśli jest).
 */
final class FcmPushSender implements PushSender
{
    public function __construct(
        private readonly AccessTokenSource $tokens,
        private readonly ?string $projectId,
        private readonly string $appUrl,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function send(PushMessage $message, string $deviceToken): PushResult
    {
        if ($this->projectId === null || $this->projectId === '') {
            throw new PushConfigurationException('Brak identyfikatora projektu Firebase (FCM_PROJECT_ID).');
        }

        $payload = $this->payload($message, $deviceToken);

        try {
            $response = $this->post($payload);

            if ($response->status() === 401) {
                $this->tokens->invalidate();
                $response = $this->post($payload);
            }
        } catch (ConnectionException) {
            return PushResult::retryable('NETWORK_ERROR');
        }

        return $this->resultOf($response);
    }

    /** @return array<string, mixed> */
    public function payload(PushMessage $message, string $deviceToken): array
    {
        $body = [
            'token' => $deviceToken,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            'data' => array_map('strval', [...$message->data, 'type' => $message->type, 'url' => $message->url]),
        ];

        $link = rtrim($this->appUrl, '/').$message->url;
        if (str_starts_with($link, 'https://')) {
            $body['webpush'] = ['fcm_options' => ['link' => $link]];
        }

        return ['message' => $body];
    }

    /** @param  array<string, mixed>  $payload */
    private function post(array $payload): Response
    {
        return Http::withToken($this->tokens->token())
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode((string) $this->projectId).'/messages:send', $payload);
    }

    private function resultOf(Response $response): PushResult
    {
        if ($response->successful()) {
            return PushResult::sent();
        }

        $code = $this->errorCode($response);
        $status = $response->status();

        if ($status === 404 || $code === 'UNREGISTERED' || $code === 'SENDER_ID_MISMATCH') {
            return PushResult::invalidToken($code ?? 'UNREGISTERED');
        }

        if ($code === 'INVALID_ARGUMENT' && str_contains(mb_strtolower((string) $response->json('error.message')), 'registration token')) {
            return PushResult::invalidToken($code);
        }

        if (in_array($status, [429, 500, 503], true)) {
            $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);

            return PushResult::retryable($code, $retryAfter === false ? null : $retryAfter);
        }

        return PushResult::failed($code ?? 'HTTP_'.$status);
    }

    /** errorCode z details[] typu FcmError, a gdy go brak — error.status. */
    private function errorCode(Response $response): ?string
    {
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && str_ends_with((string) ($detail['@type'] ?? ''), 'google.firebase.fcm.v1.FcmError') && is_string($detail['errorCode'] ?? null)) {
                return $detail['errorCode'];
            }
        }

        $status = $response->json('error.status');

        return is_string($status) ? $status : null;
    }
}
