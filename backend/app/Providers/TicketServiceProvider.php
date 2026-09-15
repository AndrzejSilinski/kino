<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\TicketValidationService;
use App\Tickets\BookingTicketsPresenter;
use App\Tickets\TicketPdfRenderer;
use App\Tickets\TicketPdfStore;
use App\Tickets\TicketQrRenderer;
use App\Tickets\TicketTokenSigner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Usługi związane z biletami: podpis i obraz kodu QR, teksty biletów,
 * PDF, jego zapis, walidacja przy wejściu oraz limitery tych endpointów.
 *
 * singleton, bo wszystkie klasy są bezstanowe, a konfigurację wystarczy
 * odczytać raz na proces. Obiekt Dompdf (stanowy) powstaje osobno przy
 * każdym renderowaniu, wewnątrz TicketPdfRenderer.
 *
 * Skutek uboczny do zapamiętania: długo działający worker zobaczy zmianę
 * TICKET_QR_KEY, logo albo szablonu dopiero po restarcie (pułapka N).
 */
final class TicketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            TicketTokenSigner::class,
            static fn (): TicketTokenSigner => new TicketTokenSigner((string) config('tickets.qr.key')),
        );

        $this->app->singleton(
            TicketQrRenderer::class,
            static fn (Application $app): TicketQrRenderer => new TicketQrRenderer(
                $app->make(TicketTokenSigner::class),
                (int) config('tickets.qr.size'),
                (string) config('tickets.qr.logo_path'),
            ),
        );

        $this->app->singleton(
            BookingTicketsPresenter::class,
            static fn (): BookingTicketsPresenter => new BookingTicketsPresenter(
                (int) config('cinema.screening.ads_minutes'),
            ),
        );

        $this->app->singleton(
            TicketPdfRenderer::class,
            static fn (Application $app): TicketPdfRenderer => new TicketPdfRenderer(
                $app->make(BookingTicketsPresenter::class),
                $app->make(TicketQrRenderer::class),
                $app->make(ViewFactory::class),
                (string) config('tickets.qr.logo_path'),
                storage_path('fonts'),
            ),
        );

        $this->app->singleton(
            TicketPdfStore::class,
            static fn (Application $app): TicketPdfStore => new TicketPdfStore(
                $app->make(TicketPdfRenderer::class),
                $app->make(FilesystemFactory::class),
                (string) config('tickets.pdf.disk'),
                (string) config('tickets.pdf.directory'),
            ),
        );

        $this->app->singleton(
            TicketValidationService::class,
            static fn (Application $app): TicketValidationService => new TicketValidationService(
                $app->make(TicketTokenSigner::class),
                (int) config('tickets.validation.opens_minutes_before'),
            ),
        );
    }

    /**
     * Limitery endpointów biletów (decyzja 82). Kluczem jest użytkownik,
     * nie IP: obie trasy wymagają zalogowania, a kilka bramek w jednym
     * kinie wychodzi do internetu z jednego adresu.
     */
    public function boot(): void
    {
        // Bramka w szczycie przepuszcza ok. jednego widza na sekundę.
        RateLimiter::for('ticket-validation', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by('ticket-validation:'.($request->user()?->id ?? $request->ip())));

        // Brakujący PDF generuje się na miejscu, więc każde pobranie
        // może kosztować kilkaset milisekund pracy procesora.
        RateLimiter::for('ticket-downloads', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('ticket-downloads:'.($request->user()?->id ?? $request->ip())));
    }
}
