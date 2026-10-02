<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Screening;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Kroki startu kontenera, które dotyczą aplikacji (Etap 10, blok D). Wywołuje je
 * docker/php/entrypoint.sh; sprawy systemowe (użytkownicy, pliki, composer) zostają w powłoce.
 *
 * Logika jest tutaj, a nie w skrypcie powłoki, bo to ona decyduje o danych: kiedy seedować,
 * czego nie robić w produkcji — i dlatego ma testy PHPUnit, których skrypt by nie miał.
 *
 *   --migrate        php: migracje z blokadą (--isolated), więc dwie repliki nie migrują naraz,
 *   --seed-if-empty  php w środowisku deweloperskim: dane demonstracyjne tylko do PUSTEJ bazy,
 *   --check-push     worker: czy ten proces może przeczytać plik konta serwisowego FCM.
 *
 * Ostrzeżenia nie przerywają startu (aplikacja działa bez push i ze starym repertuarem);
 * nieudana migracja — tak, bo kod nowszy niż schemat bazy psułby dane.
 */
final class BootCommand extends Command
{
    protected $signature = 'cinema:boot
        {--migrate : Migracje bazy z blokadą przed równoległym uruchomieniem}
        {--seed-if-empty : Dane demonstracyjne, gdy w bazie nie ma żadnego użytkownika (nigdy w produkcji)}
        {--check-push : Sprawdza, czy plik konta serwisowego FCM jest czytelny dla tego procesu}';

    protected $description = 'Kroki startu kontenera: migracje, dane demonstracyjne, kontrola konfiguracji';

    public function handle(): int
    {
        if ($this->option('migrate')) {
            if ($this->call('migrate', ['--force' => true, '--isolated' => true]) !== self::SUCCESS) {
                $this->error('cinema:boot: migracje nie powiodły się — kontener nie wystartuje z niezgodnym schematem.');

                return self::FAILURE;
            }
        }

        if ($this->option('seed-if-empty')) {
            $this->seedIfEmpty();
        }

        if ($this->option('migrate') && ! $this->laravel->isProduction()) {
            $this->warnAboutStaleRepertoire();
        }

        if ($this->option('check-push')) {
            $this->checkPushCredentials();
        }

        return self::SUCCESS;
    }

    private function seedIfEmpty(): void
    {
        // Dane demonstracyjne zawierają konta ze znanymi hasłami (README, "Konta testowe").
        if ($this->laravel->isProduction()) {
            $this->warn('cinema:boot: --seed-if-empty pominięte w produkcji (konta demonstracyjne mają jawne hasła).');

            return;
        }

        if (User::query()->exists()) {
            $this->line('cinema:boot: baza ma użytkowników — bez danych demonstracyjnych.');

            return;
        }

        $this->call('db:seed', ['--force' => true]);
        $this->info('cinema:boot: baza była pusta — wczytano dane demonstracyjne.');
    }

    /**
     * Seeder liczy repertuar od chwili uruchomienia (2 dni wstecz, 13 naprzód), więc baza
     * wypełniona dawno nie ma już przyszłych seansów. Nie odświeżamy jej sami: migrate:fresh
     * kasuje wszystko, także rezerwacje założone ręcznie — to decyzja człowieka.
     */
    private function warnAboutStaleRepertoire(): void
    {
        if (Screening::query()->doesntExist() || Screening::query()->where('starts_at', '>', now())->exists()) {
            return;
        }

        $this->warn('cinema:boot: brak przyszłych seansów — dane demonstracyjne się zestarzały. '
            .'Odświeżenie (KASUJE całą bazę): php artisan migrate:fresh --seed');
    }

    private function checkPushCredentials(): void
    {
        if (! config('push.enabled')) {
            $this->line('cinema:boot: push wyłączony (PUSH_ENABLED=false).');

            return;
        }

        $path = (string) config('push.fcm.credentials');
        $problem = match (true) {
            $path === '' => 'FCM_CREDENTIALS jest puste',
            ! is_file($path) => 'pliku nie ma w tym kontenerze',
            ! is_readable($path) => 'plik jest NIECZYTELNY dla uid '.(function_exists('posix_geteuid') ? posix_geteuid() : '?')
                .' (środowisko deweloperskie: sudo chgrp 82 i chmod 640 na pliku w docker/secrets/)',
            default => null,
        };

        if ($problem === null) {
            $this->info('cinema:boot: plik konta serwisowego FCM czytelny.');

            return;
        }

        // Ostrzeżenie zamiast błędu: worker wysyła też maile i PDF-y, a te działają bez push.
        $this->warn('cinema:boot: push włączony, ale '.$problem.' — powiadomienia push nie wyjdą.');
    }
}
