@php($money = fn (int $grosze) => \App\Support\Money::minor($grosze, $booking->currency)->toArray()['formatted'])
@php($at = fn ($moment) => $moment?->setTimezone($timezone)->format('Y-m-d H:i'))
<section>
    <hgroup>
        <h1>Rezerwacja <code>{{ $booking->reference }}</code></h1>
        <p><a href="{{ route('admin.bookings.index') }}">&larr; Rezerwacje</a> · godziny w strefie {{ $timezone }}</p>
    </hgroup>

    <div class="grid">
        <article>
            <header><strong>Status: {{ $statuses[$booking->status->value] ?? $booking->status->value }}</strong></header>
            <p>Kwota: <strong>{{ $money($booking->total_amount) }}</strong></p>
            <p><small>
                Utworzona {{ $at($booking->created_at) }}
                @if ($booking->expires_at && $booking->status->value === 'pending') · płatność do {{ $at($booking->expires_at) }} @endif
                @if ($booking->paid_at) · opłacona {{ $at($booking->paid_at) }} @endif
                @if ($booking->cancelled_at) · anulowana {{ $at($booking->cancelled_at) }} @endif
            </small></p>
            @if ($booking->cancellation_reason && $isAdmin)
                <p><small>Powód anulowania: {{ $booking->cancellation_reason }} ({{ $booking->cancelledBy?->name ?? 'system' }})</small></p>
            @endif
            @if ($booking->stripe_payment_intent_id)
                <p><small>Płatność Stripe: …{{ substr($booking->stripe_payment_intent_id, -6) }}</small></p>
            @endif
        </article>
        <article>
            <header><strong>Klient</strong></header>
            <p>{{ $booking->user->name }}<br>
                <small>{{ $isAdmin ? $booking->user->email : \App\Support\PersonalData::maskEmail($booking->user->email) }}</small></p>
        </article>
        <article>
            <header><strong>Seans</strong></header>
            <p>{{ $booking->screening->movie->title }}<br>
                <small>{{ $booking->screening->hall->cinema->name }}, {{ $booking->screening->hall->name }}, {{ $at($booking->screening->starts_at) }}</small></p>
            <p><a href="{{ route('admin.screenings.seats', $booking->screening) }}">Plan sali seansu</a></p>
        </article>
    </div>

    <table>
        <thead>
            <tr><th scope="col">Miejsce</th><th scope="col">Kategoria</th><th scope="col">Cena</th><th scope="col">Bilet</th><th scope="col">Wejście</th></tr>
        </thead>
        <tbody>
            @forelse ($booking->tickets as $ticket)
                <tr wire:key="ticket-{{ $ticket->id }}">
                    <td>{{ $ticket->seat->row_label }}{{ $ticket->seat->seat_number }}</td>
                    <td>{{ $ticket->seat->priceCategory->name }}</td>
                    <td>{{ $money($ticket->price) }}</td>
                    <td>{{ ['valid' => 'ważny', 'used' => 'wykorzystany', 'cancelled' => 'anulowany'][$ticket->status->value] ?? $ticket->status->value }}</td>
                    <td>{{ $at($ticket->validated_at) ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Bilety powstają po opłaceniu rezerwacji.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
