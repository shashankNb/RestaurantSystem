import { useSyncExternalStore } from 'react';

const TICK_MS = 15_000;

let now = Date.now();
let timer: ReturnType<typeof setInterval> | undefined;
const listeners = new Set<() => void>();

function subscribe(listener: () => void) {
  listeners.add(listener);

  if (timer === undefined) {
    now = Date.now();
    timer = setInterval(() => {
      now = Date.now();
      listeners.forEach((notify) => notify());
    }, TICK_MS);
  }

  return () => {
    listeners.delete(listener);

    if (listeners.size === 0) {
      clearInterval(timer);
      timer = undefined;
    }
  };
}

/**
 * The current time in milliseconds, updated every 15 seconds while anything shows it: for
 * "4 min ago", countdowns and "late" warnings. One shared timer, however many use it.
 */
export function useNow(): number {
  return useSyncExternalStore(subscribe, () => now, () => now);
}
