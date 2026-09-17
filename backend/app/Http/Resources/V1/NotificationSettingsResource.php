<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ustawienia powiadomień (Etap 8, blok I).
 *
 * push_enabled to ZGODA klienta zapisana na serwerze — nie uprawnienie przeglądarki
 * (Notification.permission), które zna tylko dane urządzenie. Push dostanie klient,
 * który ma zgodę ORAZ zarejestrowane urządzenie (blok K).
 * screening_reminders dotyczy przypomnienia przed seansem: e-mail zawsze, push przy zgodzie.
 *
 * @mixin User
 */
final class NotificationSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'push_enabled' => $this->wantsPush(),
            'push_consent_at' => $this->push_consent_at?->toIso8601String(),
            'screening_reminders' => (bool) $this->screening_reminders,
        ];
    }
}
