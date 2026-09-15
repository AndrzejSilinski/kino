<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AuthorizeChannelRequest;
use App\Models\User;
use App\Services\ChannelAuthorizationService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/broadcasting/auth — podpis subskrypcji prywatnego kanału.
 *
 * Trasa NIE ma middleware auth:sanctum: ten odrzuciłby kupującego bez
 * konta, a kanał seansu ma działać także dla niego. Token bearer jest
 * opcjonalny i czytamy go strażnikiem 'sanctum' wprost. Czy anonim
 * dostanie dany kanał, rozstrzyga Policy, nie middleware.
 *
 * Odpowiedź sukcesu to SUROWE {"auth": "..."}, bez opakowania "data".
 * To świadomy wyjątek od konwencji API (decyzja 20): tego kształtu
 * wymaga protokół Pushera, a więc laravel-echo, pusher-js i klient
 * Fluttera. Błędy mają zwykły kształt z polem code.
 */
final class BroadcastingAuthController extends Controller
{
    public function __construct(private readonly ChannelAuthorizationService $channels) {}

    public function __invoke(AuthorizeChannelRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');

        return response()->json($this->channels->authorize(
            (string) $request->validated('channel_name'),
            (string) $request->validated('socket_id'),
            $user instanceof User ? $user : null,
        ));
    }
}
