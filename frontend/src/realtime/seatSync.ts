/*
 * Synchronizacja planu sali na żywo (Etap 8, blok G) — czysty moduł bez Vue i bez sieci.
 *
 * ALGORYTM (decyzja 125, wymóg 1.3):
 *   1. migawka REST (wersja V) — plan jest poprawny, zanim cokolwiek przyjdzie z WebSocketu,
 *   2. subskrypcja private-screenings.{id},
 *   3. po pusher:subscription_succeeded ponowna migawka: zmiany z okna między krokiem 1 a 2
 *      nie przyszły zdarzeniem, więc gdyby ich nie pobrać, przepadłyby.
 *   Po zerwaniu połączenia pusher-js sam subskrybuje ponownie — krok 3 wykonuje się znowu.
 *
 * ZDARZENIA (stan absolutny, wersja rośnie o 1 na każdy COMMIT):
 *   version <= znana        -> pomijamy (już jest w migawce albo przyszło dwa razy),
 *   version == znana + 1    -> nakładamy,
 *   version >  znana + 1    -> luka (zgubione zdarzenie) -> migawka,
 *   seats.resync            -> migawka (zmiana za duża na jedno zdarzenie).
 * Migawki nie nakładają się: kolejne żądanie w trakcie trwającego tylko ją powtórzy po zakończeniu.
 *
 * Bez odpytywania co sekundę: przy braku połączenia status "offline" i baner z przyciskiem odświeżenia.
 */
import type { ConnectionState, RealtimeConnection } from './connection';

export type SeatChanges = Partial<Record<'free' | 'held' | 'sold', number[]>>;
export type SyncStatus = 'connecting' | 'live' | 'offline' | 'unavailable';

export interface SeatSyncTarget {
  /** Wersja stanu miejsc, którą zna plan (z migawki albo ostatniego zdarzenia). */
  version(): number;
  /** Pobiera migawkę i nakłada ją, jeśli nie jest starsza od znanej wersji. */
  loadSnapshot(): Promise<void>;
  applyChanges(changes: SeatChanges, version: number): void;
}

export interface SeatSyncOptions {
  screeningId: number;
  connection: RealtimeConnection;
  target: SeatSyncTarget;
  onStatus?(status: SyncStatus): void;
  /** Ponowienie subskrypcji po odmowie autoryzacji (403/503), w ms. */
  retryDelays?: number[];
  setTimer?(callback: () => void, ms: number): unknown;
  clearTimer?(handle: unknown): void;
}

export interface SeatSync {
  start(): Promise<void>;
  stop(): void;
  refresh(): Promise<void>;
  readonly status: SyncStatus;
}

interface SeatsChangedPayload {
  screening_id: number;
  version: number;
  seats: SeatChanges;
}

function isSeatsChanged(data: unknown): data is SeatsChangedPayload {
  const payload = data as SeatsChangedPayload;
  return typeof payload?.version === 'number' && typeof payload.screening_id === 'number' && typeof payload.seats === 'object' && payload.seats !== null;
}

export function createSeatSync(options: SeatSyncOptions): SeatSync {
  const { screeningId, connection, target } = options;
  const channelName = `private-screenings.${screeningId}`;
  const retryDelays = options.retryDelays ?? [5_000, 15_000, 30_000];
  const setTimer = options.setTimer ?? ((callback, ms) => setTimeout(callback, ms));
  const clearTimer = options.clearTimer ?? ((handle) => clearTimeout(handle as ReturnType<typeof setTimeout>));

  let status: SyncStatus = 'connecting';
  let unsubscribe: (() => void) | null = null;
  let stopState: (() => void) | null = null;
  let snapshotRunning: Promise<void> | null = null;
  let snapshotAgain = false;
  let retryIndex = 0;
  let retryHandle: unknown = null;
  let stopped = false;

  function setStatus(next: SyncStatus): void {
    if (status !== next) {
      status = next;
      options.onStatus?.(next);
    }
  }

  function snapshot(): Promise<void> {
    if (snapshotRunning) {
      snapshotAgain = true;
      return snapshotRunning;
    }
    const run = async (): Promise<void> => {
      // Zawsze asynchronicznie: przypisanie snapshotRunning = run() musi nastąpić przed blokiem finally,
      // także gdy loadSnapshot rzuci wyjątek synchronicznie.
      await Promise.resolve();
      try {
        do {
          snapshotAgain = false;
          try {
            await target.loadSnapshot();
          } catch {
            // Błąd pokazuje store planu; zdarzenia i kolejne migawki spróbują ponownie.
          }
        } while (snapshotAgain && !stopped);
      } finally {
        // Czyścimy flagę w TYM SAMYM takcie, w którym pętla sprawdziła snapshotAgain. Przy .finally()
        // na obietnicy powstawała luka o jeden mikrotakt: prośba o migawkę z tej luki przepadała
        // (wyłapał to test "luka w numeracji ... i seats.resync").
        snapshotRunning = null;
      }
    };
    snapshotRunning = run();
    return snapshotRunning;
  }

  function onSeatsChanged(data: unknown): void {
    if (!isSeatsChanged(data) || data.screening_id !== screeningId) {
      return;
    }
    const known = target.version();
    if (data.version <= known) {
      return;
    }
    if (data.version === known + 1 && !snapshotRunning) {
      target.applyChanges(data.seats, data.version);
      return;
    }
    // Luka w numeracji albo migawka w toku (jej wynik mógłby cofnąć zdarzenie) -> migawka po niej.
    void snapshot();
  }

  function onResync(data: unknown): void {
    const payload = data as { screening_id?: number; version?: number };
    if (payload?.screening_id !== screeningId || (typeof payload.version === 'number' && payload.version <= target.version())) {
      return;
    }
    void snapshot();
  }

  function subscribe(): void {
    unsubscribe?.();
    unsubscribe = connection.subscribe(channelName, {
      onSubscribed: () => {
        retryIndex = 0;
        setStatus('live');
        void snapshot();
      },
      onError: () => {
        // 403 CHANNEL_FORBIDDEN (seans się zaczął) albo 503 REALTIME_UNAVAILABLE: plan działa dalej przez REST.
        setStatus('unavailable');
        const delay = retryDelays[Math.min(retryIndex, retryDelays.length - 1)];
        retryIndex++;
        if (!stopped && delay !== undefined) {
          retryHandle = setTimer(() => {
            retryHandle = null;
            if (!stopped) {
              subscribe();
            }
          }, delay);
        }
      },
      events: {
        'seats.changed': onSeatsChanged,
        'seats.resync': onResync,
      },
    });
  }

  function onConnectionState(state: ConnectionState): void {
    if (state === 'unavailable' || state === 'failed' || state === 'disconnected') {
      setStatus('offline');
    } else if (state === 'connecting' && status !== 'offline') {
      setStatus('connecting');
    }
    // "connected" nie oznacza jeszcze subskrypcji — status "live" ustawia dopiero subscription_succeeded.
  }

  return {
    async start() {
      stopped = false;
      await snapshot();
      stopState = connection.onStateChange(onConnectionState);
      subscribe();
    },
    stop() {
      stopped = true;
      if (retryHandle !== null) {
        clearTimer(retryHandle);
      }
      unsubscribe?.();
      unsubscribe = null;
      stopState?.();
      stopState = null;
    },
    refresh: snapshot,
    get status() {
      return status;
    },
  };
}
