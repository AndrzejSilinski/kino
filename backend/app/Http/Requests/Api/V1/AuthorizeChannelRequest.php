<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Żądanie autoryzacji kanału wysyłane przez klienta Pushera (Echo, Flutter).
 *
 * Klient wysyła je sam, formularzem (application/x-www-form-urlencoded),
 * zaraz po nawiązaniu połączenia WebSocket. Walidujemy wyłącznie KSZTAŁT;
 * o dostępie rozstrzyga ChannelAuthorizationService i Policies.
 *
 * Formaty według protokołu Pushera, zgodne z walidacją w pusher-php-server:
 *   socket_id    — dwie liczby rozdzielone kropką, np. 123456.7890123
 *   channel_name — do 164 znaków; akceptujemy wyłącznie kanały private-*,
 *                  bo tylko takie wymagają podpisu (publiczne go nie
 *                  potrzebują, a presence-* w projekcie nie istnieją).
 */
final class AuthorizeChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'max:64', 'regex:/\A\d+\.\d+\z/'],
            'channel_name' => ['required', 'string', 'max:164', 'regex:/\Aprivate-[A-Za-z0-9_\-=@,.;]+\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'socket_id.required' => 'Brak identyfikatora połączenia WebSocket.',
            'socket_id.regex' => 'Nieprawidłowy identyfikator połączenia WebSocket.',
            'channel_name.required' => 'Brak nazwy kanału.',
            'channel_name.max' => 'Nazwa kanału jest za długa.',
            'channel_name.regex' => 'Autoryzacji wymagają wyłącznie kanały prywatne (private-*).',
        ];
    }
}
