<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Push\AccessTokenSource;
use App\Push\FcmPushSender;
use App\Push\PushConfigurationException;
use App\Push\PushMessage;
use App\Push\PushResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** FCM HTTP v1: kształt wiadomości i mapowanie odpowiedzi (Etap 8, blok K). Bez sieci — Http::fake. */
final class FcmPushSenderTest extends TestCase
{
    private const DEVICE = 'urzadzenie-testowe-0123456789abcdef';

    private const URL = 'https://fcm.googleapis.com/v1/projects/kino-test/messages:send';

    private function sender(string $appUrl = 'http://localhost:8080', ?AccessTokenSource $tokens = null): FcmPushSender
    {
        return new FcmPushSender($tokens ?? self::tokens(['ya29.atrapa']), 'kino-test', $appUrl);
    }

    /** Atrapa źródła tokenu: kolejne tokeny z listy, licznik unieważnień. */
    private static function tokens(array $tokens): AccessTokenSource
    {
        return new class($tokens) implements AccessTokenSource
        {
            public int $invalidated = 0;

            public int $issued = 0;

            /** @param  list<string>  $tokens */
            public function __construct(private array $tokens) {}

            public function token(): string
            {
                $this->issued++;

                return count($this->tokens) > 1 ? (string) array_shift($this->tokens) : (string) $this->tokens[0];
            }

            public function invalidate(): void
            {
                $this->invalidated++;
            }
        };
    }

    private function message(): PushMessage
    {
        return new PushMessage('Płatność przyjęta', 'Barbie · 17.09, godz. 11:00', 'booking.paid', '/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA');
    }

    /** @param  array<string, mixed>  $error */
    private static function fcmError(int $code, string $status, string $message, ?string $errorCode = null): array
    {
        $details = $errorCode === null ? [] : [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $errorCode]];

        return ['error' => ['code' => $code, 'message' => $message, 'status' => $status, 'details' => $details]];
    }

    public function test_wiadomosc_notification_z_danymi_tekstowymi_i_tokenem_bearer_bez_linku_dla_http(): void
    {
        Http::fake([self::URL => Http::response(['name' => 'projects/kino-test/messages/1'])]);

        $this->assertSame(PushResult::SENT, $this->sender()->send($this->message(), self::DEVICE)->status);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('Bearer ya29.atrapa', $request->header('Authorization')[0]);
            $this->assertSame(['message' => [
                'token' => self::DEVICE,
                'notification' => ['title' => 'Płatność przyjęta', 'body' => 'Barbie · 17.09, godz. 11:00'],
                'data' => ['type' => 'booking.paid', 'url' => '/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA'],
            ]], $request->data());

            return true;
        });
    }

    public function test_link_webpush_tylko_dla_adresu_https(): void
    {
        $payload = $this->sender('https://kino.example')->payload($this->message(), self::DEVICE);

        $this->assertSame(['fcm_options' => ['link' => 'https://kino.example/bookings/01M2QM60X2F0WC7SXBC2E7Q7VA']], $payload['message']['webpush']);
    }

    /** @return iterable<string, array{0: int, 1: array<string, mixed>, 2: string, 3: string|null}> */
    public static function responses(): iterable
    {
        yield 'UNREGISTERED' => [404, self::fcmError(404, 'NOT_FOUND', 'Requested entity was not found.', 'UNREGISTERED'), PushResult::INVALID_TOKEN, 'UNREGISTERED'];
        yield 'nieprawidłowy token' => [400, self::fcmError(400, 'INVALID_ARGUMENT', 'The registration token is not a valid FCM registration token', 'INVALID_ARGUMENT'), PushResult::INVALID_TOKEN, 'INVALID_ARGUMENT'];
        yield 'błąd treści, nie tokenu' => [400, self::fcmError(400, 'INVALID_ARGUMENT', "Invalid value at 'message.data[0].value' (TYPE_STRING), 12", 'INVALID_ARGUMENT'), PushResult::FAILED, 'INVALID_ARGUMENT'];
        yield 'SENDER_ID_MISMATCH' => [403, self::fcmError(403, 'PERMISSION_DENIED', 'SenderId mismatch', 'SENDER_ID_MISMATCH'), PushResult::INVALID_TOKEN, 'SENDER_ID_MISMATCH'];
        yield 'QUOTA_EXCEEDED' => [429, self::fcmError(429, 'RESOURCE_EXHAUSTED', 'Quota exceeded.', 'QUOTA_EXCEEDED'), PushResult::RETRYABLE, 'QUOTA_EXCEEDED'];
        yield 'UNAVAILABLE' => [503, self::fcmError(503, 'UNAVAILABLE', 'The service is currently unavailable.', 'UNAVAILABLE'), PushResult::RETRYABLE, 'UNAVAILABLE'];
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('responses')]
    public function test_mapowanie_bledow_fcm(int $status, array $body, string $expected, ?string $code): void
    {
        Http::fake([self::URL => Http::response($body, $status, ['Retry-After' => '120'])]);

        $result = $this->sender()->send($this->message(), self::DEVICE);

        $this->assertSame([$expected, $code], [$result->status, $result->errorCode]);
        if ($expected === PushResult::RETRYABLE) {
            $this->assertSame(120, $result->retryAfterSeconds);
        }
    }

    public function test_401_odnawia_token_dostepu_i_ponawia_raz(): void
    {
        $tokens = self::tokens(['stary', 'nowy']);
        Http::fakeSequence(self::URL)->push(self::fcmError(401, 'UNAUTHENTICATED', 'Request had invalid authentication credentials.'), 401)->push(['name' => 'x']);

        $this->assertSame(PushResult::SENT, $this->sender(tokens: $tokens)->send($this->message(), self::DEVICE)->status);
        Http::assertSentCount(2);
        $this->assertSame([1, 2], [$tokens->invalidated, $tokens->issued]);
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer nowy');
    }

    public function test_brak_projektu_to_blad_konfiguracji(): void
    {
        $this->expectException(PushConfigurationException::class);

        (new FcmPushSender(self::tokens(['x']), null, 'http://localhost'))->send($this->message(), self::DEVICE);
    }
}
