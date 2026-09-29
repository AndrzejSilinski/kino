<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Wynik odwołania seansu razem z rezerwacjami (Etap 9, blok L).
 *
 * Raport, a nie „udało się / nie udało": przy kilkudziesięciu rezerwacjach wynik z natury bywa
 * częściowy, a administrator musi wiedzieć DOKŁADNIE, co się stało — ile osób dostało
 * powiadomienie, ile zwrotów czeka na rozliczenie i które rezerwacje trzeba obejrzeć ręcznie.
 */
final readonly class ScreeningCancellationReport
{
    /** @param  list<string>  $failed  numery rezerwacji, których nie udało się anulować */
    public function __construct(
        public int $cancelled,
        public int $refundsPending,
        public array $failed,
        public bool $screeningCancelled,
    ) {}

    /**
     * Komunikat dla panelu.
     *
     * O zwrotach mówimy wprost, że rozliczy je zadanie w tle — administrator, który tego nie wie,
     * po chwili sprawdzi rezerwację, zobaczy „zwrot w toku" i uzna, że coś się zacięło.
     */
    public function message(): string
    {
        if (! $this->screeningCancelled) {
            return 'Nie odwołano seansu: '.count($this->failed).' z '.($this->cancelled + count($this->failed))
                .' rezerwacji nie dało się anulować ('.implode(', ', $this->failed)
                .'). Pozostałe zostały anulowane — obejrzyj wymienione i spróbuj ponownie.';
        }

        $parts = ['Odwołano seans.'];

        $parts[] = match ($this->cancelled) {
            0 => 'Nie było rezerwacji do anulowania.',
            1 => 'Anulowano 1 rezerwację, klient dostał powiadomienie.',
            default => 'Anulowano '.$this->cancelled.' rezerwacji, klienci dostali powiadomienia.',
        };

        if ($this->refundsPending > 0) {
            $parts[] = $this->refundsPending === 1
                ? 'Jeden zwrot rozliczy zadanie w tle (przebiega co pięć minut).'
                : $this->refundsPending.' zwroty rozliczy zadanie w tle (przebiega co pięć minut).';
        }

        return implode(' ', $parts);
    }
}
