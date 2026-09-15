<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konto użytkownika — wspólne dla weba (Vue) i aplikacji mobilnej.
 *
 * Kontroler nie zawiera logiki biznesowej: przyjmuje zwalidowany
 * FormRequest, woła serwis, zwraca Resource. Cała wiedza o tym, jak
 * powstaje konto i jak weryfikuje się hasło, siedzi w AuthService.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
    ) {}

    /** Rejestracja. Zwraca 201 wraz z pierwszym tokenem. */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register(
            $request->validated(),
            $request->deviceName(),
        );

        return $this->tokenResponse($result, 201);
    }

    /** Logowanie. Błędne dane -> 401 INVALID_CREDENTIALS z serwisu. */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            $request->deviceName(),
        );

        return $this->tokenResponse($result, 200);
    }

    /** Wylogowanie: kasuje token TEGO urządzenia, inne zostają aktywne. */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return response()->json([
            'data' => ['message' => 'Wylogowano.'],
        ]);
    }

    /** Dane zalogowanego użytkownika — używane przy starcie aplikacji. */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * @param  array{user: \App\Models\User, token: string}  $result
     */
    private function tokenResponse(array $result, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
        ], $status);
    }
}
