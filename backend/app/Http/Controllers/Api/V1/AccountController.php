<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\Account\AccountService;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Profil i hasło zalogowanego klienta (Etap 8, blok I). Odczyt profilu to GET /auth/me.
 * Każda trasa działa wyłącznie na $request->user() — nie ma identyfikatora konta w adresie,
 * więc nie ma czego podmienić (brak IDOR z definicji, Policy niepotrzebna).
 */
final class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        return new UserResource($this->accounts->updateProfile($request->user(), (string) $request->validated('name')));
    }

    /** 200 z liczbą wylogowanych urządzeń — SPA może powiedzieć klientowi, co się stało. */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $revoked = $this->accounts->changePassword(
            $request->user(),
            (string) $request->validated('password'),
            $token instanceof PersonalAccessToken ? (int) $token->getKey() : null,
        );

        return response()->json([
            'data' => [
                'message' => 'Hasło zostało zmienione.',
                'revoked_tokens' => $revoked,
            ],
        ]);
    }
}
