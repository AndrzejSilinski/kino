<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterPushDeviceRequest;
use App\Http\Resources\V1\PushDeviceResource;
use App\Models\PushDevice;
use App\Services\Account\PushDeviceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Urządzenia push zalogowanego klienta (Etap 8, blok K).
 *
 * Urządzenie wiążemy z tokenem Sanctum bieżącej sesji: wylogowanie (także wylogowanie innych
 * urządzeń po zmianie hasła i sprzątanie wygasłych tokenów) usuwa je w bazie przez ON DELETE CASCADE.
 * Jawne DELETE jest dla przypadku "wyłącz powiadomienia na tym urządzeniu" bez wylogowania.
 */
final class PushDeviceController extends Controller
{
    public function __construct(private readonly PushDeviceService $devices) {}

    /** 201 — nowe urządzenie, 200 — to samo co wcześniej (odświeżone). */
    public function store(RegisterPushDeviceRequest $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        [$device, $created] = $this->devices->register(
            $request->user(),
            (string) $request->validated('token'),
            (string) $request->validated('platform'),
            $token instanceof PersonalAccessToken ? (int) $token->getKey() : null,
            $request->validated('replaces'),
        );

        return (new PushDeviceResource($device))->response()->setStatusCode($created ? 201 : 200);
    }

    /** 204; cudze albo nieistniejące urządzenie — 404, bez rozróżnienia (jak rezerwacje). */
    public function destroy(Request $request, string $device): Response
    {
        if (! $this->devices->unregister($request->user(), $device)) {
            throw (new ModelNotFoundException)->setModel(PushDevice::class);
        }

        return response()->noContent();
    }
}
