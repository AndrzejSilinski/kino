<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Żądanie blokady miejsc.
 *
 * Walidacja jest tu CELOWO płytka — sprawdza wyłącznie kształt danych.
 * Nie ma reguły exists:seats,id i to nie jest przeoczenie:
 *
 *   1. exists potwierdziłoby istnienie miejsca, ale nie to, czy należy
 *      do sali TEGO seansu. Tę wiedzę ma SeatLockService.
 *   2. Nawet poprawne sprawdzenie w PHP nic nie gwarantuje: między
 *      walidacją a INSERT-em mija czas, w którym ktoś inny może to
 *      miejsce zająć. Wiążący jest wyłącznie częściowy indeks UNIQUE
 *      w bazie (decyzja #11 z Etapu 2).
 *
 * FormRequest odsiewa więc oczywiste śmieci i chroni przed żądaniem
 * z tysiącem identyfikatorów. Prawdę rozstrzyga baza.
 */
class LockSeatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Blokować może każdy, także niezalogowany — konto jest potrzebne
        // dopiero przy płatności (Etap 4).
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $max = (int) config('cinema.seat_lock.max_seats_per_session', 10);

        return [
            'seat_ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'seat_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'seat_ids.required' => 'Nie wybrano żadnego miejsca.',
            'seat_ids.min' => 'Nie wybrano żadnego miejsca.',
            'seat_ids.max' => 'W jednym koszyku można mieć najwyżej :max miejsc.',
            'seat_ids.*.integer' => 'Identyfikator miejsca musi być liczbą.',
            'seat_ids.*.distinct' => 'To samo miejsce zostało wybrane dwukrotnie.',
        ];
    }

    /**
     * @return array<int, int>
     */
    public function seatIds(): array
    {
        return array_map('intval', (array) $this->validated('seat_ids'));
    }
}
