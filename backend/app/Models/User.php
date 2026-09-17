<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * Konto klienta, pracownika obsługi kina lub administratora.
 *
 * UWAGA: kolumny 'role' i 'cinema_id' celowo NIE są w #[Fillable]. Gdyby
 * tam były, żądanie rejestracji z polem "role":"admin" albo "cinema_id"
 * mogłoby nadać sobie uprawnienia (mass assignment / privilege escalation).
 * Rolę ustawia jawnie AuthService, zawsze na UserRole::Customer.
 * Konta administratorów i obsługi powstają wyłącznie przez seeder albo
 * przez panel admina chroniony Policy.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            // Etap 8, blok I: ustawienia powiadomień. avatar_path poza $fillable —
            // zmienia go wyłącznie AvatarService, nigdy masowe przypisanie z żądania.
            'push_consent_at' => 'immutable_datetime',
            'screening_reminders' => 'boolean',
        ];
    }

    /** Zgoda na powiadomienia push (Etap 8, blok I). Uprawnienie przeglądarki to osobna sprawa. */
    public function wantsPush(): bool
    {
        return $this->push_consent_at !== null;
    }

    /** Publiczny adres avatara albo null (Etap 8, blok I, decyzja: losowa nazwa w /storage). */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path !== null
            ? Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    /** Rezerwacje tego użytkownika — potrzebne w kroku 3.7 i w Policy. */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Kino, w którym pracuje obsługa. Dla klienta i administratora null —
     * pilnuje tego constraint users_staff_has_cinema w bazie.
     */
    public function cinema(): BelongsTo
    {
        return $this->belongsTo(Cinema::class);
    }

    /** Skrót czytelny w Policy i w Gate. */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Pracownik obsługi kina (skanowanie biletów w przypisanym kinie). */
    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }
}
