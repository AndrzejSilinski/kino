<?php

namespace App\Http;

use App\Exceptions\CinemaException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Zamienia dowolny wyjątek na jednolity JSON kontraktu API.
 *
 * Kształt odpowiedzi błędu:
 *   message  - tekst dla użytkownika, po polsku
 *   code     - stały identyfikator maszynowy, frontend reaguje na niego
 *   errors   - tylko przy 422, format Laravela: {pole: [komunikat]}
 *   context  - dane pomocnicze dla UI, np. które miejsca są zajęte
 *
 * Zwrócenie null znaczy "nie moja sprawa" — Laravel obsłuży wyjątek
 * domyślnie. Dzięki temu panel Livewire z Etapu 7 dalej dostanie
 * normalne strony błędów HTML.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            // Wyjątki domenowe same wiedzą, jakim kodem HTTP się objawić.
            $e instanceof CinemaException => $this->fromDomain($e),

            $e instanceof ValidationException => $this->fromValidation($e),

            $e instanceof AuthenticationException => $this->make(
                401, 'UNAUTHENTICATED', 'Wymagane jest zalogowanie.'
            ),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => $this->make(
                403, 'FORBIDDEN', 'Nie masz uprawnień do tego zasobu.'
            ),

            $e instanceof ModelNotFoundException => $this->make(
                404, 'RESOURCE_NOT_FOUND', 'Nie znaleziono zasobu.'
            ),

            $e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException => $this->make(
                404, 'RESOURCE_NOT_FOUND', 'Nie znaleziono zasobu.'
            ),

            $e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException => $this->make(
                404, 'RESOURCE_NOT_FOUND', 'Nie znaleziono zasobu.'
            ),

            $e instanceof NotFoundHttpException => $this->make(
                404, 'ENDPOINT_NOT_FOUND', 'Nie znaleziono takiego adresu.'
            ),

            $e instanceof MethodNotAllowedHttpException => $this->make(
                405, 'METHOD_NOT_ALLOWED', 'Niedozwolona metoda HTTP.'
            ),

            $e instanceof TooManyRequestsHttpException => $this->fromThrottle($e),

            default => $this->fromUnexpected($e),
        };
    }

    /** Wyjątek domenowy niesie komplet informacji — tylko go przepisujemy. */
    private function fromDomain(CinemaException $e): JsonResponse
    {
        return $this->make(
            $e->status(),
            $e->errorCode(),
            $e->getMessage(),
            $e->context(),
        );
    }

    /** 422: format errors zgodny z Laravelem, żeby biblioteki formularzy go rozumiały. */
    private function fromValidation(ValidationException $e): JsonResponse
    {
        return $this->make(
            422,
            'VALIDATION_FAILED',
            'Podane dane są nieprawidłowe.',
            [],
            $e->errors(),
        );
    }

    /** 429: Retry-After trafia i do nagłówka, i do ciała — klient mobilny czyta ciało. */
    private function fromThrottle(TooManyRequestsHttpException $e): JsonResponse
    {
        $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

        return $this->make(
            429,
            'TOO_MANY_REQUESTS',
            'Zbyt wiele żądań. Spróbuj ponownie za chwilę.',
            ['retry_after_seconds' => $retryAfter],
        )->header('Retry-After', (string) $retryAfter);
    }

    /** Wszystko pozostałe. Szczegóły techniczne tylko przy APP_DEBUG=true. */
    private function fromUnexpected(Throwable $e): JsonResponse
    {
        if ($e instanceof HttpExceptionInterface) {
            return $this->make(
                $e->getStatusCode(),
                'HTTP_ERROR',
                $e->getMessage() !== '' ? $e->getMessage() : 'Wystąpił błąd.',
            );
        }

        $context = config('app.debug') ? [
            'exception' => $e::class,
            'at' => $e->getFile().':'.$e->getLine(),
        ] : [];

        return $this->make(
            500,
            'SERVER_ERROR',
            'Wystąpił nieoczekiwany błąd serwera.',
            $context,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, array<int, string>>  $errors
     */
    private function make(
        int $status,
        string $code,
        string $message,
        array $context = [],
        array $errors = [],
    ): JsonResponse {
        $payload = ['message' => $message, 'code' => $code];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($context !== []) {
            $payload['context'] = $context;
        }

        return response()->json($payload, $status);
    }
}
