<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Ticket;
use App\Services\BookingTicketsService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Bilety właściciela rezerwacji: PDF i obraz kodu QR (decyzje 78 i 79).
 *
 * Oba adresy wymagają tokenu bearer. Aplikacje pobierają pliki przez fetch
 * z nagłówkiem Authorization (Vue: blob, Flutter: Image.network z headers).
 * Podpisany adres w <img src> trafiłby do logów nginx razem z podpisem.
 *
 * Cache-Control: private, no-store — bilet to przepustka na salę; nie może
 * zostać w pamięci podręcznej przeglądarki na wspólnym komputerze ani
 * w pośredniczącym proxy.
 */
final class BookingTicketsController extends Controller
{
    public function __construct(private readonly BookingTicketsService $tickets) {}

    public function pdf(Booking $booking): Response
    {
        Gate::authorize('view', $booking);

        ['content' => $content, 'filename' => $filename] = $this->tickets->pdf($booking);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** {ticket} jest już zawężony do biletów tej rezerwacji przez scopeBindings(). */
    public function qr(Booking $booking, Ticket $ticket): Response
    {
        Gate::authorize('view', $booking);

        return response($this->tickets->qrPng($booking, $ticket), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
