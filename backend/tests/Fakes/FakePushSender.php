<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Push\PushMessage;
use App\Push\PushResult;
use App\Push\PushSender;

/** Atrapa wysyłki push (Etap 8, blok K): zapisuje wiadomości, wynik per token ustawia test. */
final class FakePushSender implements PushSender
{
    /** @var list<array{token: string, message: PushMessage}> */
    public array $sent = [];

    /** @var array<string, PushResult> */
    private array $results = [];

    public function respond(string $token, PushResult $result): void
    {
        $this->results[$token] = $result;
    }

    public function send(PushMessage $message, string $deviceToken): PushResult
    {
        $this->sent[] = ['token' => $deviceToken, 'message' => $message];

        return $this->results[$deviceToken] ?? PushResult::sent();
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return array_column($this->sent, 'token');
    }
}
