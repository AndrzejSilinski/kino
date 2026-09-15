<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Models\Booking;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Zapisany PDF z biletami: generowanie, zapis i odczyt.
 *
 * ZAPIS ATOMOWY: PDF trafia najpierw do pliku tymczasowego, a potem jest
 * przenoszony pod docelową nazwę. Przeniesienie w obrębie jednego systemu
 * plików to rename(), które podmienia plik w jednym kroku — pobranie
 * z historii zakupów w trakcie generowania dostanie stary, pełny plik
 * albo nowy, pełny plik, nigdy połowę.
 *
 * SPRAWDZAMY WYNIK KAŻDEJ OPERACJI: dysk 'local' ma w konfiguracji
 * 'throw' => false, więc put() i move() przy błędzie zwracają false
 * zamiast rzucić wyjątek. Bez sprawdzenia job uznałby nieudany zapis
 * za sukces i wysłałby mail bez PDF-a (pułapka AC).
 *
 * UPRAWNIENIA: visibility 'public' daje plik 0644 zamiast domyślnego 0600.
 * Worker i php-fpm działają jako www-data, ale narzędzia deweloperskie
 * (tinker, pdftotext) jako Twój użytkownik — przy 0600 nie odczytałyby
 * pliku. Katalog tickets/ zakładamy z góry z prawami 777 (decyzja 77).
 */
final class TicketPdfStore
{
    public function __construct(
        private readonly TicketPdfRenderer $renderer,
        private readonly FilesystemFactory $filesystems,
        private readonly string $disk,
        private readonly string $directory,
    ) {}

    /** Ścieżka pliku na dysku, np. tickets/01M2JA3H44TJSRGQCZCQM3CPYW.pdf. */
    public function path(Booking $booking): string
    {
        return $this->directory.'/'.$booking->reference.'.pdf';
    }

    /** Nazwa pliku dla klienta: załącznik maila i pobieranie z historii. */
    public function fileName(Booking $booking): string
    {
        return 'bilety-'.$booking->reference.'.pdf';
    }

    /** Generuje PDF i zapisuje go atomowo. Zwraca ścieżkę na dysku. */
    public function store(Booking $booking): string
    {
        $pdf = $this->renderer->render($booking);
        $disk = $this->disk();
        $path = $this->path($booking);
        $temporary = $path.'.'.Str::random(16).'.tmp';
        $options = ['visibility' => 'public', 'directory_visibility' => 'public'];

        if (! $disk->put($temporary, $pdf, $options)) {
            throw new RuntimeException('Nie udało się zapisać PDF-a z biletami rezerwacji '.$booking->reference.'.');
        }

        if (! $disk->move($temporary, $path)) {
            $disk->delete($temporary);

            throw new RuntimeException('Nie udało się przenieść PDF-a z biletami rezerwacji '.$booking->reference.'.');
        }

        return $path;
    }

    /**
     * Treść PDF-a. Gdy pliku nie ma (job jeszcze nie skończył albo storage
     * został wyczyszczony), generuje go na miejscu — klient nigdy nie dostaje
     * błędu tylko dlatego, że plik nie powstał wcześniej.
     */
    public function contents(Booking $booking): string
    {
        $disk = $this->disk();
        $path = $this->path($booking);

        if (! $disk->exists($path)) {
            $this->store($booking);
        }

        $contents = $disk->get($path);

        if ($contents === null) {
            throw new RuntimeException('Nie udało się odczytać PDF-a z biletami rezerwacji '.$booking->reference.'.');
        }

        return $contents;
    }

    /** Dysk pobierany przy każdym użyciu, żeby Storage::fake() w testach działało. */
    private function disk(): Filesystem
    {
        return $this->filesystems->disk($this->disk);
    }
}
