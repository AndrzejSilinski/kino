<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Kwota pieniężna wyrażona w groszach.
 *
 * Pieniądze w tym projekcie są ZAWSZE liczbą całkowitą w najmniejszej
 * jednostce (decyzja #4 z Etapu 1). Ta klasa pilnuje, żeby wychodziły
 * do API w jednym kształcie i żeby formatowanie robił serwer.
 *
 * Dlaczego serwer, a nie klient: Intl.NumberFormat w przeglądarce
 * i NumberFormat w Dartcie dają dla locale pl_PL różne wyniki — inna
 * spacja przed "zł", inne grupowanie tysięcy. Użytkownik zobaczyłby
 * dwie różne ceny tego samego biletu w webie i w aplikacji mobilnej.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Money implements Arrayable
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    /** Buduje kwotę z groszy. Waluta domyślnie z config/cinema.php. */
    public static function minor(int $amount, ?string $currency = null): self
    {
        return new self($amount, $currency ?? (string) config('cinema.booking.currency'));
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    /**
     * Formatowanie BEZ liczb zmiennoprzecinkowych.
     *
     * Kuszące byłoby $this->amount / 100, ale to wprowadza dokładnie ten
     * błąd zaokrąglenia, przed którym cały ten typ ma chronić. Rozbijamy
     * na złote i grosze operacjami całkowitoliczbowymi.
     */
    private function format(): string
    {
        $absolute = abs($this->amount);
        $major = intdiv($absolute, 100);
        $minor = $absolute % 100;
        $sign = $this->amount < 0 ? '-' : '';

        // U+00A0 to spacja nierozdzielająca — polska typografia nie
        // pozwala przenieść "zł" do następnego wiersza bez kwoty.
        $formatted = $sign
            . number_format($major, 0, ',', "\u{00A0}")
            . ','
            . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);

        return $this->currency === 'PLN'
            ? $formatted."\u{00A0}zł"
            : $formatted.' '.$this->currency;
    }
}
