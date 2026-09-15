<?php

namespace App\Http\Resources\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dane konta widoczne dla właściciela.
 *
 * Resource istnieje po to, żeby NIGDY nie zwracać modelu wprost.
 * Model ma dziś kolumny password i remember_token, a jutro dostanie
 * numer telefonu albo adres — i wszystkie wyciekłyby do API bez
 * jednej linii zmiany w kontrolerze. Tu lista pól jest jawna.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Zgodnie z kontraktem: surowa wartość enuma dla maszyny
            // plus etykieta po polsku, żeby klient nie trzymał
            // własnego słownika tłumaczeń.
            'role' => $this->role->value,
            'role_label' => $this->roleLabel(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function roleLabel(): string
    {
        return match ($this->role) {
            UserRole::Admin => 'Administrator',
            UserRole::Staff => 'Obsługa kina',
            UserRole::Customer => 'Klient',
        };
    }
}
