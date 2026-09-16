<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AuthorizeChannelRequest;
use App\Models\User;
use App\Services\ChannelAuthorizationService;
use Illuminate\Http\JsonResponse;

/**
 * POST /admin/broadcasting/auth — podpis prywatnego kanału dla panelu (Etap 7, blok L).
 *
 * Bliźniak Api\V1\BroadcastingAuthController: ta sama walidacja i TA SAMA
 * ChannelAuthorizationService (mapa kanał -> zasób -> Policy). Różni się tylko
 * źródłem użytkownika — sesja panelu (guard web) z ochroną CSRF zamiast tokenu
 * Sanctum. Dzięki temu reguła "kto słucha feedu kina" jest zapisana raz.
 */
final class BroadcastingAuthController extends Controller
{
    public function __construct(private readonly ChannelAuthorizationService $channels) {}

    public function __invoke(AuthorizeChannelRequest $request): JsonResponse
    {
        $user = $request->user('web');

        return response()->json($this->channels->authorize(
            (string) $request->validated('channel_name'),
            (string) $request->validated('socket_id'),
            $user instanceof User ? $user : null,
        ));
    }
}
