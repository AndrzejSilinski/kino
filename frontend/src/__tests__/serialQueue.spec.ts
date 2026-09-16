import { describe, expect, it } from 'vitest';
import { createSerialQueue } from '@/lib/serialQueue';
import { deferred } from './fixtures/seats';

describe('kolejka szeregowa kliknięć', () => {
  it('drugie zadanie startuje dopiero po zakończeniu pierwszego, a błąd nie zatrzymuje kolejki', async () => {
    const queue = createSerialQueue();
    const started: string[] = [];
    const first = deferred<void>();

    const a = queue.push(async () => { started.push('A'); await first.promise; throw new Error('409'); });
    const b = queue.push(async () => { started.push('B'); return 'ok'; });
    await Promise.resolve();

    expect(started).toEqual(['A']);
    first.resolve();
    await expect(a).rejects.toThrow('409');
    await expect(b).resolves.toBe('ok');
    expect(started).toEqual(['A', 'B']);
  });
});
