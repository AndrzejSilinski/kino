<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateNotificationSettingsRequest;
use App\Http\Resources\V1\NotificationSettingsResource;
use App\Services\Account\AccountService;
use Illuminate\Http\Request;

/** Ustawienia powiadomień zalogowanego klienta (Etap 8, blok I). */
final class NotificationSettingsController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function show(Request $request): NotificationSettingsResource
    {
        return new NotificationSettingsResource($request->user());
    }

    public function update(UpdateNotificationSettingsRequest $request): NotificationSettingsResource
    {
        return new NotificationSettingsResource($this->accounts->updateNotificationSettings($request->user(), $request->settings()));
    }
}
