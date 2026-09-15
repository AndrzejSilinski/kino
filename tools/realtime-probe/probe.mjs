// Sonda WebSocket dla Etapu 6: prawdziwy klient pusher-js przeciw Reverbowi.
//
// pusher-js to ta sama biblioteka, na której stoi laravel-echo we froncie Vue,
// więc sonda sprawdza protokół dokładnie tak, jak zobaczy go przeglądarka:
// połączenie przez nginx /app/, podpis kanału z naszego endpointu, zdarzenia.
//
// Przebieg (steruje nim skrypt powłoki, patrz README, Etap 6):
//   1. snapshot planu sali przez REST, potem subskrypcje wszystkich klientów,
//   2. wypisuje "PROBE READY"          -> powłoka blokuje miejsce (curl),
//   3. klient B traci połączenie, pobiera snapshot, wraca -> "PROBE RECONNECTED",
//   4. powłoka zwalnia miejsce i wysyła próbne zdarzenie rezerwacji (tinker),
//   5. sonda czeka na zdarzenia, wypisuje PASS/FAIL i kończy kodem 0 albo 1.
//
// Tokeny czyta z pliku (PROBE_FILE) i NIGDY ich nie wypisuje.

import { readFileSync } from 'node:fs';
import Pusher from 'pusher-js';

const env = (name, fallback) => process.env[name] ?? fallback;

const KEY = env('REVERB_APP_KEY');
const WS_HOST = env('WS_HOST', 'nginx');
const WS_PORT = Number(env('WS_PORT', '80'));
const API_URL = env('API_URL', 'http://nginx/api/v1');
const SEAT_ID = Number(env('SEAT_ID'));
const WAIT_MS = Number(env('WAIT_MS', '20000'));
const probe = JSON.parse(readFileSync(env('PROBE_FILE', '/secrets/probe.json'), 'utf8'));

if (!KEY || !SEAT_ID) {
    console.error('Brak REVERB_APP_KEY albo SEAT_ID.');
    process.exit(2);
}

const started = Date.now();
const results = [];
const auth = {};
const clients = [];

const log = (label, message) => console.log(`[${((Date.now() - started) / 1000).toFixed(2)} s] ${label.padEnd(12)} ${message}`);
const check = (name, ok, detail = '') => results.push({ name, ok: Boolean(ok), detail });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitFor(predicate, ms = WAIT_MS) {
    const deadline = Date.now() + ms;

    while (Date.now() < deadline) {
        if (predicate()) {
            return true;
        }
        await sleep(50);
    }

    return predicate();
}

// ─── REST ─────────────────────────────────────────────────────────────────

async function seatMap() {
    const response = await fetch(`${API_URL}/screenings/${probe.screening_id}/seat-map`, {
        headers: { Accept: 'application/json' },
    });
    const body = await response.json();
    const seat = body.data.seats.find((item) => item.id === SEAT_ID);

    return { version: body.data.seat_state_version, seatStatus: seat?.status ?? null };
}

// ─── Klient Pushera ───────────────────────────────────────────────────────

// Własny handler autoryzacji zamiast wbudowanego: zapamiętuje kod HTTP i pole
// "code" odpowiedzi, żeby sonda mogła sprawdzić, że odmowa to 403 CHANNEL_FORBIDDEN.
// Wysyła formularz, dokładnie jak domyślny authorizer pusher-js.
function authorizer(label, token) {
    return async ({ socketId, channelName }, callback) => {
        try {
            const response = await fetch(`${API_URL}/broadcasting/auth`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    ...(token ? { Authorization: `Bearer ${token}` } : {}),
                },
                body: new URLSearchParams({ socket_id: socketId, channel_name: channelName }),
            });
            const body = await response.json().catch(() => ({}));

            auth[`${label} ${channelName}`] = { status: response.status, code: body.code ?? null };
            log(label, `autoryzacja ${channelName}: HTTP ${response.status}${body.code ? ` ${body.code}` : ''}`);

            response.ok ? callback(null, body) : callback(new Error(`HTTP ${response.status}`), null);
        } catch (error) {
            auth[`${label} ${channelName}`] = { status: 0, code: error.name };
            callback(error, null);
        }
    };
}

function connect(label, token = null) {
    const pusher = new Pusher(KEY, {
        // cluster jest wymagany przez pusher-js 8, ale przy wsHost nie ma znaczenia.
        cluster: 'reverb',
        wsHost: WS_HOST,
        wsPort: WS_PORT,
        wssPort: WS_PORT,
        forceTLS: false,
        enabledTransports: ['ws'],
        channelAuthorization: { customHandler: authorizer(label, token) },
    });

    clients.push(pusher);

    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error(`${label}: brak połączenia`)), 10000);

        pusher.connection.bind('connected', () => {
            clearTimeout(timer);
            log(label, `połączony, socket_id ${pusher.connection.socket_id}`);
            resolve(pusher);
        });
    });
}

// Zwraca kanał po udanej subskrypcji albo null po odmowie.
function subscribe(pusher, label, channelName) {
    const channel = pusher.subscribe(channelName);

    return new Promise((resolve) => {
        channel.bind('pusher:subscription_succeeded', () => {
            log(label, `subskrypcja ${channelName}: OK`);
            resolve(channel);
        });
        channel.bind('pusher:subscription_error', () => {
            log(label, `subskrypcja ${channelName}: ODMOWA`);
            pusher.unsubscribe(channelName);
            resolve(null);
        });
    });
}

function collect(channel, label, eventName, bucket) {
    channel?.bind(eventName, (data) => {
        bucket.push(data);
        log(label, `${eventName} ${JSON.stringify(data)}`);
    });
}

const seatEvent = (events, status) => events.find((event) => event.seats?.[status]?.includes(SEAT_ID));
const keys = (object) => JSON.stringify(Object.keys(object ?? {}));
const forbidden = (label, channelName) => {
    const entry = auth[`${label} ${channelName}`];

    return entry?.status === 403 && entry?.code === 'CHANNEL_FORBIDDEN';
};

// ─── Przebieg ─────────────────────────────────────────────────────────────

const screeningChannel = `private-screenings.${probe.screening_id}`;
const bookingChannel = `private-bookings.${probe.booking_reference}`;
const cinemaSales = `private-cinemas.${probe.cinema_id}.sales`;

const eventsA = [];
const eventsB = [];
const eventsB2 = [];
const ownerEvents = [];
const salesEvents = { [cinemaSales]: [], 'private-sales': [] };

try {
    // 1. Wymóg 1.3: najpierw pełny stan przez REST, potem subskrypcja.
    const snapshot = await seatMap();
    log('anonim-A', `snapshot: wersja ${snapshot.version}, miejsce ${SEAT_ID} = ${snapshot.seatStatus}`);

    const [anonA, anonB, owner, stranger, admin] = await Promise.all([
        connect('anonim-A'),
        connect('anonim-B'),
        connect('właściciel', probe.tokens.owner),
        connect('obcy', probe.tokens.stranger),
        connect('admin', probe.tokens.admin),
    ]);

    collect(await subscribe(anonA, 'anonim-A', screeningChannel), 'anonim-A', 'seats.changed', eventsA);
    collect(await subscribe(anonB, 'anonim-B', screeningChannel), 'anonim-B', 'seats.changed', eventsB);
    const ownChannel = await subscribe(owner, 'właściciel', bookingChannel);
    collect(ownChannel, 'właściciel', 'booking.status-changed', ownerEvents);

    const anonSales = await subscribe(anonA, 'anonim-A', 'private-sales');
    const strangerBooking = await subscribe(stranger, 'obcy', bookingChannel);
    const strangerSales = await subscribe(stranger, 'obcy', 'private-sales');
    const strangerCinema = await subscribe(stranger, 'obcy', cinemaSales);

    const adminSales = await subscribe(admin, 'admin', 'private-sales');
    const adminCinema = await subscribe(admin, 'admin', cinemaSales);
    collect(adminSales, 'admin', 'sales.activity', salesEvents['private-sales']);
    collect(adminCinema, 'admin', 'sales.activity', salesEvents[cinemaSales]);

    check('właściciel subskrybuje kanał własnej rezerwacji', ownChannel !== null);
    check('anonim NIE subskrybuje private-sales (403 CHANNEL_FORBIDDEN)', anonSales === null && forbidden('anonim-A', 'private-sales'));
    check('obcy klient NIE subskrybuje cudzej rezerwacji (403)', strangerBooking === null && forbidden('obcy', bookingChannel));
    check('obcy klient NIE subskrybuje private-sales (403)', strangerSales === null && forbidden('obcy', 'private-sales'));
    check('obcy klient NIE subskrybuje feedu kina (403)', strangerCinema === null && forbidden('obcy', cinemaSales));
    check('admin subskrybuje private-sales i feed kina', adminSales !== null && adminCinema !== null);

    // 2. Powłoka blokuje miejsce.
    console.log('PROBE READY');

    const held = await waitFor(() => seatEvent(eventsA, 'held') && seatEvent(eventsB, 'held'));
    const heldA = seatEvent(eventsA, 'held');
    const heldB = seatEvent(eventsB, 'held');

    check('A i B dostali seats.changed "held" dla miejsca', held);
    check('A i B widzą tę samą wersję, wyższą niż snapshot', heldA && heldB && heldA.version === heldB.version && heldA.version > snapshot.version,
        `snapshot ${snapshot.version}, A ${heldA?.version}, B ${heldB?.version}`);
    check('payload seats.changed bez danych osobowych', heldA && keys(heldA) === '["screening_id","version","seats"]', keys(heldA));

    // 3. Klient B traci połączenie i wraca według procedury z wymogu 1.3.
    anonB.disconnect();
    log('anonim-B', 'rozłączony');

    const afterLoss = await seatMap();
    log('anonim-B', `snapshot po utracie: wersja ${afterLoss.version}, miejsce ${SEAT_ID} = ${afterLoss.seatStatus}`);

    const anonB2 = await connect('anonim-B2');
    collect(await subscribe(anonB2, 'anonim-B2', screeningChannel), 'anonim-B2', 'seats.changed', eventsB2);

    // Kolejność "REST, potem subskrypcja" ma okno: zmiana między snapshotem
    // a potwierdzeniem subskrypcji by przepadła. Numer wersji je zamyka —
    // drugi odczyt po subskrypcji pokazuje, czy coś się w tym oknie zmieniło.
    const verified = await seatMap();
    const gap = verified.version > afterLoss.version;
    log('anonim-B2', gap ? `luka: wersja ${afterLoss.version} -> ${verified.version}, stan odświeżony` : `bez luki (wersja ${verified.version})`);

    check('snapshot po utracie pokazuje miejsce jako zajęte i wersję >= "held"',
        afterLoss.seatStatus !== 'free' && heldA && afterLoss.version >= heldA.version,
        `status ${afterLoss.seatStatus}, wersja ${afterLoss.version}`);

    console.log('PROBE RECONNECTED');

    // 4. Powłoka zwalnia miejsce i wysyła próbne zdarzenie rezerwacji.
    await waitFor(() => seatEvent(eventsA, 'free') && seatEvent(eventsB2, 'free')
        && ownerEvents.length > 0 && salesEvents['private-sales'].length > 0 && salesEvents[cinemaSales].length > 0);

    const freeA = seatEvent(eventsA, 'free');
    const freeB2 = seatEvent(eventsB2, 'free');

    check('A i B po reconnect dostali "free" z tą samą wersją, wyższą niż snapshot B',
        freeA && freeB2 && freeA.version === freeB2.version && freeB2.version > verified.version,
        `A ${freeA?.version}, B2 ${freeB2?.version}, snapshot B ${verified.version}`);
    check('B nie dostał zdarzeń na starym, rozłączonym kliencie', !seatEvent(eventsB, 'free'));

    const ownEvent = ownerEvents[0];
    check('właściciel dostał booking.status-changed swojej rezerwacji',
        ownEvent?.reference === probe.booking_reference && keys(ownEvent) === '["reference","status","status_label","occurred_at"]', keys(ownEvent));

    const sale = salesEvents['private-sales'][0];
    const saleCinema = salesEvents[cinemaSales][0];
    check('admin dostał sales.activity na obu kanałach (jedno zdarzenie)',
        sale?.reference === probe.booking_reference && JSON.stringify(sale) === JSON.stringify(saleCinema));
    check('feed sprzedaży bez danych osobowych',
        keys(sale) === '["type","reference","status","status_label","cinema","screening","seats_count","total","occurred_at"]', keys(sale));
} catch (error) {
    check('przebieg bez wyjątku', false, error.message);
}

clients.forEach((client) => client.disconnect());

console.log('\n===== WYNIK SONDY =====');
results.forEach(({ name, ok, detail }) => console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`));

const failed = results.filter((result) => !result.ok).length;
console.log(`\n${results.length - failed}/${results.length} PASS`);
process.exit(failed === 0 ? 0 : 1);
