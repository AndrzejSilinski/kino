import { describe, expect, it, vi } from 'vitest';
import { createSeatSync, type SeatChanges } from '@/realtime/seatSync';
import { fakeConnection } from './fixtures/realtime';

const CHANNEL = 'private-screenings.334';

function setup(initialVersion = 5) {
  const fake = fakeConnection();
  let version = initialVersion;
  const applied: [SeatChanges, number][] = [];
  let snapshotVersion = initialVersion;
  const loadSnapshot = vi.fn(async () => {
    version = Math.max(version, snapshotVersion);
  });
  const statuses: string[] = [];
  const timers: { callback: () => void; ms: number }[] = [];
  const sync = createSeatSync({
    screeningId: 334,
    connection: fake.connection,
    target: {
      version: () => version,
      loadSnapshot,
      applyChanges: (changes, next) => {
        applied.push([changes, next]);
        version = next;
      },
    },
    onStatus: (status) => statuses.push(status),
    setTimer: (callback, ms) => timers.push({ callback, ms }),
    clearTimer: () => {},
  });
  return {
    fake, sync, applied, loadSnapshot, statuses, timers,
    setSnapshotVersion: (value: number) => { snapshotVersion = value; },
    version: () => version,
  };
}

describe('synchronizacja planu sali na żywo', () => {
  it('kolejność z wymogu 1.3: najpierw migawka REST, potem subskrypcja, po subscription_succeeded ponowna migawka', async () => {
    const t = setup();
    const order: string[] = [];
    t.loadSnapshot.mockImplementation(async () => { order.push('migawka'); });
    const subscribe = t.fake.connection.subscribe;
    t.fake.connection.subscribe = (channel, handlers) => { order.push(`subskrypcja ${channel}`); return subscribe(channel, handlers); };

    await t.sync.start();
    t.fake.subscribed(CHANNEL);
    await vi.waitFor(() => expect(t.loadSnapshot).toHaveBeenCalledTimes(2));

    expect(order).toEqual(['migawka', `subskrypcja ${CHANNEL}`, 'migawka']);
    expect(t.sync.status).toBe('live');
  });

  it('zdarzenie z wersją znana+1 jest nakładane, starsze i powtórzone pomijane', async () => {
    const t = setup(5);
    await t.sync.start();

    t.fake.emit(CHANNEL, 'seats.changed', { screening_id: 334, version: 6, seats: { held: [891] } });
    t.fake.emit(CHANNEL, 'seats.changed', { screening_id: 334, version: 6, seats: { held: [891] } });
    t.fake.emit(CHANNEL, 'seats.changed', { screening_id: 334, version: 4, seats: { free: [891] } });

    expect(t.applied).toEqual([[{ held: [891] }, 6]]);
    expect(t.loadSnapshot).toHaveBeenCalledTimes(1);
  });

  it('luka w numeracji (zgubione zdarzenie) i seats.resync wymuszają migawkę zamiast nakładania', async () => {
    const t = setup(5);
    await t.sync.start();

    t.setSnapshotVersion(8);
    t.fake.emit(CHANNEL, 'seats.changed', { screening_id: 334, version: 8, seats: { sold: [900] } });
    await vi.waitFor(() => expect(t.loadSnapshot).toHaveBeenCalledTimes(2));
    expect(t.applied).toEqual([]);
    expect(t.version()).toBe(8);

    t.setSnapshotVersion(9);
    t.fake.emit(CHANNEL, 'seats.resync', { screening_id: 334, version: 9 });
    await vi.waitFor(() => expect(t.loadSnapshot).toHaveBeenCalledTimes(3));
  });

  it('zdarzenia innego seansu są ignorowane', async () => {
    const t = setup(5);
    await t.sync.start();

    t.fake.emit(CHANNEL, 'seats.changed', { screening_id: 999, version: 6, seats: { held: [1] } });

    expect(t.applied).toEqual([]);
  });

  it('kilka żądań migawki w trakcie trwającego odczytu łączy się w jedno powtórzenie', async () => {
    const t = setup(5);
    let release!: () => void;
    t.loadSnapshot.mockImplementationOnce(async () => {});
    await t.sync.start();
    t.loadSnapshot.mockImplementationOnce(() => new Promise<void>((resolve) => { release = resolve; }));

    const first = t.sync.refresh();
    // Prośby złożone ZANIM odczyt ruszył obsłuży ten sam odczyt; powtórzenie należy się tylko
    // prośbom z czasu trwającego odczytu (mogły dotyczyć zmian, których odczyt już nie zobaczy).
    await vi.waitFor(() => expect(t.loadSnapshot).toHaveBeenCalledTimes(2));
    void t.sync.refresh();
    void t.sync.refresh();
    release();
    await first;

    expect(t.loadSnapshot).toHaveBeenCalledTimes(3);
  });

  it('utrata połączenia: status offline; po ponownej subskrypcji live i świeża migawka (reconnect)', async () => {
    const t = setup(5);
    await t.sync.start();
    t.fake.subscribed(CHANNEL);
    await vi.waitFor(() => expect(t.loadSnapshot).toHaveBeenCalledTimes(2));

    t.fake.setState('unavailable');
    expect(t.sync.status).toBe('offline');

    t.fake.setState('connecting');
    expect(t.sync.status).toBe('offline');
    t.fake.setState('connected');
    t.fake.subscribed(CHANNEL);
    await vi.waitFor(() => expect(t.loadSnapshot).toHaveBeenCalledTimes(3));
    expect(t.sync.status).toBe('live');
  });

  it('odmowa autoryzacji kanału (403/503): status unavailable i ponowienie subskrypcji z opóźnieniem', async () => {
    const t = setup(5);
    await t.sync.start();

    t.fake.fail(CHANNEL, 503);
    expect(t.sync.status).toBe('unavailable');
    expect(t.timers.map((timer) => timer.ms)).toEqual([5_000]);

    t.timers[0]?.callback();
    expect(t.fake.subscribeCount).toBe(2);
  });

  it('stop() wypisuje z kanału i zatrzymuje reakcje na zdarzenia', async () => {
    const t = setup(5);
    await t.sync.start();

    t.sync.stop();

    expect(t.fake.subscriptions.has(CHANNEL)).toBe(false);
  });
});

describe('synchronizacja: odporność migawki', () => {
  it('synchroniczny wyjątek w loadSnapshot nie blokuje kolejnych migawek', async () => {
    const fake = fakeConnection();
    let calls = 0;
    const sync = createSeatSync({
      screeningId: 1,
      connection: fake.connection,
      target: {
        version: () => 0,
        loadSnapshot: (() => { calls++; if (calls === 1) { throw new Error('synchronicznie'); } return Promise.resolve(); }) as () => Promise<void>,
        applyChanges: () => {},
      },
    });

    await sync.refresh();
    await sync.refresh();

    expect(calls).toBe(2);
  });
});
