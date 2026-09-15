<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ValidateTicketRequest;
use App\Http\Resources\V1\TicketValidationResource;
use App\Models\Screening;
use App\Services\TicketValidationService;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/tickets/validate — skan biletu przy wejściu na salę.
 *
 * Kontroler tłumaczy HTTP na wywołanie serwisu: pobiera seans, pyta policy,
 * czy ten pracownik może na nim skanować, i zwraca zasób. Odmowy (zły kod,
 * inny seans, drugi skan) to wyjątki domenowe z serwisu, które
 * ApiExceptionRenderer zamienia na 404/409/422 ze stałym polem code.
 */
final class TicketValidationController extends Controller
{
    public function __construct(private readonly TicketValidationService $validation) {}

    public function __invoke(ValidateTicketRequest $request): TicketValidationResource
    {
        $screening = Screening::query()->with('hall')->findOrFail($request->integer('screening_id'));

        Gate::authorize('validateTickets', $screening);

        $ticket = $this->validation->validate(
            (string) $request->validated('token'),
            $screening,
            $request->user(),
        );

        return new TicketValidationResource($ticket);
    }
}
