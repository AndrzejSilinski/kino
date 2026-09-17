<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload avatara (Etap 8, blok I): multipart, pole "avatar".
 *
 * Walidator odrzuca oczywiste przypadki z czytelnym 422 przy polu (typ, rozmiar pliku).
 * Wymiary i format sprawdza jeszcze raz AvatarImageProcessor na nagłówku pliku — to on
 * jest ostatnią linią obrony (mimes patrzy na zawartość, ale nie chroni przed bombą pikselową).
 * 5 MB mieści zdjęcie z telefonu, a jest poniżej limitów PHP (upload_max_filesize 8M).
 */
final class UploadAvatarRequest extends FormRequest
{
    public const MAX_KILOBYTES = 5120;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Wybierz plik z avatarem.',
            'avatar.mimes' => 'Avatar musi być plikiem JPG albo PNG.',
            'avatar.max' => 'Avatar nie może być większy niż 5 MB.',
            'avatar.uploaded' => 'Nie udało się wgrać pliku — najczęściej jest za duży (limit 5 MB).',
        ];
    }
}
