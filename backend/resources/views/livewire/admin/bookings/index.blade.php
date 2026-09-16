<section>
    <hgroup>
        <h1>Rezerwacje</h1>
        <p>{{ $isAdmin ? 'Wszystkie kina sieci.' : 'Rezerwacje Twojego kina. Adresy e-mail klientów są zamaskowane.' }} Godziny w strefie kina.</p>
    </hgroup>

    <div class="grid">
        @if ($isAdmin)
            <label>Kino
                <select wire:model.live="cinemaId">
                    <option value="">wszystkie</option>
                    @foreach ($cinemas as $cinema)
                        <option value="{{ $cinema->id }}">{{ $cinema->city }} — {{ $cinema->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label>Dzień seansu
            <input type="date" wire:model.live="date">
        </label>
        <label>Film
            <select wire:model.live="movieId">
                <option value="">wszystkie</option>
                @foreach ($movies as $movie)
                    <option value="{{ $movie->id }}">{{ $movie->title }}</option>
                @endforeach
            </select>
        </label>
        <label>Status
            <select wire:model.live="status">
                <option value="">wszystkie</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>Numer rezerwacji
            <input type="search" wire:model.live.debounce.400ms="reference" placeholder="np. 01K5X…" maxlength="26" autocomplete="off">
        </label>
    </div>
    <p><a href="#" wire:click.prevent="clearFilters">Wyczyść filtry</a></p>

    <div class="overflow-auto">
        <table>
            <thead>
                <tr>
                    <th scope="col">Numer</th>
                    <th scope="col">Utworzona</th>
                    <th scope="col">Seans</th>
                    <th scope="col">Klient</th>
                    <th scope="col">Bilety</th>
                    <th scope="col">Kwota</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($bookings as $booking)
                    @php($cinema = $booking->screening->hall->cinema)
                    <tr wire:key="booking-{{ $booking->id }}">
                        <td><a href="{{ route('admin.bookings.show', $booking) }}"><code>{{ $booking->reference }}</code></a></td>
                        <td>{{ $booking->created_at->setTimezone($cinema->timezone)->format('Y-m-d H:i') }}</td>
                        <td>
                            {{ $booking->screening->movie->title }}<br>
                            <small>{{ $cinema->name }}, {{ $booking->screening->hall->name }},
                                {{ $booking->screening->starts_at->setTimezone($cinema->timezone)->format('Y-m-d H:i') }}</small>
                        </td>
                        <td>
                            {{ $booking->user->name }}<br>
                            <small>{{ $isAdmin ? $booking->user->email : \App\Support\PersonalData::maskEmail($booking->user->email) }}</small>
                        </td>
                        <td>{{ $booking->active_tickets_count }}</td>
                        <td>{{ \App\Support\Money::minor($booking->total_amount, $booking->currency)->toArray()['formatted'] }}</td>
                        <td>{{ $statuses[$booking->status->value] ?? $booking->status->value }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">Brak rezerwacji spełniających kryteria.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $bookings->links() }}
</section>
