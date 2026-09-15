{{--
    Bilety w PDF (dompdf). Widok dostaje wyłącznie gotowe teksty
    z TicketPdfRenderer — żadnych modeli i żadnych zapytań.

    CSS pisany pod dompdf, nie pod przeglądarkę: dompdf obsługuje CSS 2.1
    i część CSS3, ale NIE flexbox ani grid, dlatego układ stoi na tabelach.
    Czcionka DejaVu Sans jest dołączona do dompdf i ma polskie znaki.
    Domyślna czcionka PDF (Helvetica) zamieniłaby "ąęłńśźż" na znaki zapytania.
--}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Bilety - rezerwacja {{ $reference }}</title>
    <style>
        @page { margin: 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #111827; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        td { vertical-align: top; }
        .ticket { border: 0.6mm solid #111827; }
        .header td { background-color: #111827; color: #ffffff; padding: 4mm 5mm; vertical-align: middle; }
        .brand { font-size: 13pt; font-weight: bold; }
        .ticket-number { text-align: right; font-size: 9pt; }
        .content { padding: 6mm 5mm 4mm 5mm; }
        .title { font-size: 20pt; font-weight: bold; }
        .muted { color: #4b5563; font-size: 9pt; }
        .details td { padding: 2.2mm 0; border-bottom: 0.2mm solid #e5e7eb; }
        .label { color: #6b7280; font-size: 7.5pt; text-transform: uppercase; width: 28mm; padding-top: 3mm; }
        .value { font-size: 11pt; }
        .seat { font-size: 24pt; font-weight: bold; }
        .qr { text-align: center; width: 70mm; padding-left: 5mm; }
        .qr img { width: 64mm; height: 64mm; }
        .status { color: #b91c1c; font-size: 12pt; font-weight: bold; }
        .footer td { border-top: 0.3mm dashed #9ca3af; padding: 3mm 5mm; font-size: 8pt; color: #4b5563; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
@foreach ($tickets as $ticket)
    <div class="ticket">
        <table class="header">
            <tr>
                <td style="width: 12mm;"><img src="{{ $logo }}" alt="" style="width: 10mm; height: 10mm;"></td>
                <td class="brand">{{ $cinema['name'] }}</td>
                <td class="ticket-number">Bilet {{ $ticket['number'] }} z {{ $ticket['count'] }}</td>
            </tr>
        </table>

        <div class="content">
            <div class="title">{{ $movie['title'] }}</div>
            <div class="muted">{{ $screening['version'] }} · {{ $movie['duration'] }} · od {{ $movie['age_rating'] }}</div>

            <table style="margin-top: 5mm;">
                <tr>
                    <td>
                        <table class="details">
                            <tr>
                                <td class="label">Data</td>
                                <td class="value">{{ $screening['date'] }}</td>
                            </tr>
                            <tr>
                                <td class="label">Godzina</td>
                                <td class="value"><strong>{{ $screening['time'] }}</strong></td>
                            </tr>
                            <tr>
                                <td class="label">Kino</td>
                                <td class="value">{{ $cinema['name'] }}<br><span class="muted">{{ $cinema['address'] }}</span></td>
                            </tr>
                            <tr>
                                <td class="label">Sala</td>
                                <td class="value">{{ $cinema['hall'] }}</td>
                            </tr>
                            <tr>
                                <td class="label">Miejsce</td>
                                <td class="value">
                                    <span class="seat">Rząd {{ $ticket['row'] }}, miejsce {{ $ticket['seat'] }}</span>
                                    @if ($ticket['seat_type'] !== null)
                                        <br><span class="muted">{{ $ticket['seat_type'] }}</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="label">Cena</td>
                                <td class="value">{{ $ticket['price'] }}</td>
                            </tr>
                        </table>
                    </td>
                    <td class="qr">
                        <img src="{{ $ticket['qr'] }}" alt="Kod QR biletu">
                        @if ($ticket['status'] !== null)
                            <div class="status">{{ $ticket['status'] }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        <table class="footer">
            <tr>
                <td>
                    Pokaż kod QR przy wejściu na salę. Bilet jest ważny wyłącznie na ten seans.
                    Seans poprzedza blok reklam (ok. {{ $screening['ads_minutes'] }} min).<br>
                    Rezerwacja {{ $reference }} · łącznie {{ $total }}
                </td>
            </tr>
        </table>
    </div>

    @unless ($loop->last)
        <div class="page-break"></div>
    @endunless
@endforeach
</body>
</html>
