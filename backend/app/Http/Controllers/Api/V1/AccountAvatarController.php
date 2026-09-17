<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UploadAvatarRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\Account\AvatarService;
use Illuminate\Http\Request;

/** Avatar zalogowanego klienta (Etap 8, blok I). Odpowiedź: profil z nowym avatar_url. */
final class AccountAvatarController extends Controller
{
    public function __construct(private readonly AvatarService $avatars) {}

    public function store(UploadAvatarRequest $request): UserResource
    {
        return new UserResource($this->avatars->store($request->user(), (string) $request->file('avatar')?->getRealPath()));
    }

    /** Idempotentne: usunięcie braku avatara to nadal 200 z avatar_url = null. */
    public function destroy(Request $request): UserResource
    {
        return new UserResource($this->avatars->remove($request->user()));
    }
}
