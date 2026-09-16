/*
 * Kolejka szeregowa (Etap 8, blok F): zadania wykonują się po jednym, w kolejności zgłoszenia.
 *
 * Kliknięcia miejsc idą do serwera szeregowo. Równoległe POST-y i DELETE-y na tym samym koszyku
 * mogłyby wrócić w innej kolejności niż wysłane — wtedy starsza odpowiedź (koszyk bez nowego miejsca)
 * nadpisałaby nowszą. Szeregowo odpowiedzi przychodzą w kolejności kliknięć i ostatnia jest najświeższa.
 * Błąd jednego zadania nie zatrzymuje kolejnych.
 */
export interface SerialQueue {
  push<T>(task: () => Promise<T>): Promise<T>;
  readonly size: number;
}

export function createSerialQueue(): SerialQueue {
  let tail: Promise<unknown> = Promise.resolve();
  let size = 0;

  return {
    push<T>(task: () => Promise<T>): Promise<T> {
      size++;
      const run = tail.then(task, task);
      tail = run.catch(() => undefined).finally(() => {
        size--;
      });
      return run;
    },
    get size() {
      return size;
    },
  };
}
