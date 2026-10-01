const KEY = 'auth-token';

let memory: string | null = null;

/**
 * Browsers have no secure store. The token is kept in memory and copied to localStorage
 * so a reload keeps the customer signed in. localStorage is readable by any script on the
 * page, so an XSS bug could steal the token; docs/DECISIONS.md records the trade-off.
 * Storage can be missing (server rendering) or blocked (private mode), so every access
 * falls back to memory only.
 */
function storage(): Storage | null {
  try {
    return typeof window === 'undefined' ? null : window.localStorage;
  } catch {
    return null;
  }
}

export const tokenStorage = {
  async get(): Promise<string | null> {
    if (memory === null) {
      try {
        memory = storage()?.getItem(KEY) ?? null;
      } catch {
        memory = null;
      }
    }

    return memory;
  },

  async set(token: string): Promise<void> {
    memory = token;

    try {
      storage()?.setItem(KEY, token);
    } catch {
      // Blocked storage: the customer stays signed in until they close the tab.
    }
  },

  async clear(): Promise<void> {
    memory = null;

    try {
      storage()?.removeItem(KEY);
    } catch {
      // Nothing to clear.
    }
  },
};
