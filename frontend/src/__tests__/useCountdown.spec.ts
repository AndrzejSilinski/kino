import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { formatClock, remainingSeconds, useCountdown, type Deadline } from '@/composables/useCountdown';

describe('odliczanie do wygaśnięcia blokad', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('liczy od zegara monotonicznego i chwili odebrania odpowiedzi, nie od zegara ściennego', () => {
    expect(remainingSeconds({ seconds: 600, receivedAt: 1000 }, 1000)).toBe(600);
    expect(remainingSeconds({ seconds: 600, receivedAt: 1000 }, 1000 + 59_500)).toBe(541);
    expect(remainingSeconds({ seconds: 5, receivedAt: 0 }, 60_000)).toBe(0);
    expect(remainingSeconds(null, 0)).toBeNull();
    expect(formatClock(541)).toBe('9:01');
  });

  it('wywołuje onExpire raz na termin i zaczyna od nowa po nowej odpowiedzi serwera', async () => {
    let now = 0;
    const onExpire = vi.fn();
    const deadline = ref<Deadline | null>({ seconds: 2, receivedAt: 0 });
    const scope = effectScope();
    const countdown = scope.run(() => useCountdown(deadline, { now: () => now, tickMs: 100, onExpire }))!;

    expect(countdown.label.value).toBe('0:02');
    now = 2_100;
    vi.advanceTimersByTime(500);
    expect(countdown.remaining.value).toBe(0);
    expect(onExpire).toHaveBeenCalledTimes(1);

    deadline.value = { seconds: 300, receivedAt: now };
    await nextTick();
    vi.advanceTimersByTime(500);
    expect(countdown.label.value).toBe('5:00');
    expect(onExpire).toHaveBeenCalledTimes(1);
    scope.stop();
  });
});
