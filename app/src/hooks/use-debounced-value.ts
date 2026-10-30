import { useEffect, useState } from 'react';

/**
 * The value once it has stopped changing for `delayMs`: for asking the server about what
 * someone is typing without asking at every keystroke. Pass a primitive (a string, say), so
 * a new object with the same contents doesn't start the wait again.
 */
export function useDebouncedValue<T extends string | number | boolean | null>(value: T, delayMs: number): T {
  const [settled, setSettled] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), delayMs);

    return () => clearTimeout(timer);
  }, [value, delayMs]);

  return settled;
}
