import { describe, expect, it, vi } from 'vitest';
import type { Booking } from '@/api/types';
import { watchBooking, type BookingWatchStatus } from '@/realtime/bookingWatch';
import { booking, REFERENCE } from './fixtures/checkout';
import { fakeConnection } from './fixtures/realtime';

const CHANNEL = `private-bookings.${REFERENCE}`;

/** Ręczne timery: test sam decyduje, kiedy mija odstęp odpytywania. */
function manualTimers() {
  const pending: { callback: () => void; ms: number }[] = [];
  return {
    pending,
    setTimer: (callback: () => void, ms: number) => {
      const entry = { callback, ms };
      pending.push(entry);
      return entry;
    },
    clearTimer: (handle: unknown) => {
      const index = pending.indexOf(handle as (typeof pending)[number]);
      if (index >= 0) pending.splice(index, 1);
    },
    async fire() {
      pending.shift()?.callback();
      await new Promise((resolve) => setTimeout(resolve, 0));
    },
  };
}

function setup(responses: Booking[], options: { connection?: boolean; delays?: number[] } = {}) {
  const realtime = fakeConnection();
  const timers = manualTimers();
  const fetchBooking = vi.fn(async () => responses.shift() ?? booking());
  const seen: string[] = [];
  const statuses: BookingWatchStatus[] = [];
  const watch = watchBooking({
    reference: REFERENCE,
    connection: options.connection === false ? null : realtime.connection,
    fetchBooking,
    onBooking: (value) => seen.push(value.status),
    onStatus: (status) => statuses.push(status),
    pollDelays: options.delays ?? [2000, 3000],
    setTimer: timers.setTimer,
    clearTimer: timers.clearTimer,
  });
  return { watch, realtime, timers, fetchBooking, seen, statuses };
}

describe('czekanie na wynik płatności', () => {
  it('subskrybuje kanał rezerwacji PRZED pierwszym odczytem i czyta ponownie po subscription_succeeded', async () => {
    const { watch, realtime, fetchBooking } = setup([booking(), booking()]);

    await watch.start();
    expect(realtime.subscriptions.has(CHANNEL)).toBe(true);
    expect(fetchBooking).toHaveBeenCalledTimes(1);

    realtime.subscribed(CHANNEL);
    await vi.waitFor(() => expect(fetchBooking).toHaveBeenCalledTimes(2));
  });

  it('zdarzenie booking.status-changed -> odczyt z API; status końcowy kończy czekanie i wypisuje z kanału', async () => {
    const { watch, realtime, timers, seen, statuses } = setup([booking(), booking({ status: 'paid', status_label: 'Opłacona' })]);
    await watch.start();

    realtime.emit(CHANNEL, 'booking.status-changed', { reference: REFERENCE, status: 'paid' });

    await vi.waitFor(() => expect(seen).toEqual(['pending', 'paid']));
    expect(statuses).toEqual(['waiting', 'done']);
    expect(realtime.subscriptions.has(CHANNEL)).toBe(false);
    expect(timers.pending).toHaveLength(0);
  });

  it('bez WebSocketu odpytuje z rosnącymi odstępami, a po limicie prób zgłasza opóźnienie', async () => {
    const { watch, timers, fetchBooking, statuses } = setup([], { connection: false, delays: [2000, 3000] });
    await watch.start();

    expect(timers.pending.map((timer) => timer.ms)).toEqual([2000]);
    await timers.fire();
    await vi.waitFor(() => expect(timers.pending.map((timer) => timer.ms)).toEqual([3000]));
    await timers.fire();

    await vi.waitFor(() => expect(statuses).toEqual(['waiting', 'delayed']));
    expect(fetchBooking).toHaveBeenCalledTimes(3);
    expect(timers.pending).toHaveLength(0);
  });

  it('błąd odczytu nie przerywa czekania; stop() sprząta subskrypcję i timer', async () => {
    const { watch, realtime, timers } = setup([]);
    const failing = vi.fn(async (): Promise<Booking> => {
      throw new Error('sieć');
    });
    const errors: unknown[] = [];
    const guarded = watchBooking({
      reference: REFERENCE,
      connection: realtime.connection,
      fetchBooking: failing,
      onBooking: () => {},
      onError: (error) => errors.push(error),
      pollDelays: [1000],
      setTimer: timers.setTimer,
      clearTimer: timers.clearTimer,
    });

    await guarded.start();
    expect(errors).toHaveLength(1);
    expect(timers.pending).toHaveLength(1);

    guarded.stop();
    watch.stop();
    expect(realtime.subscriptions.size).toBe(0);
    expect(timers.pending).toHaveLength(0);
  });
});
