import { create } from 'zustand';

import { tokenStorage } from './token-storage';

type SessionStatus = 'restoring' | 'signedOut' | 'signedIn';

interface SessionState {
  /** 'restoring' until the saved token has been read at start-up. */
  status: SessionStatus;
  token: string | null;
  /** Reads the saved token. Call once at start-up. */
  restore: () => Promise<void>;
  /** Saves a token from sign-in or registration. */
  start: (token: string) => Promise<void>;
  /** Forgets the token on this device. Revoking it on the server is the caller's job. */
  clear: () => Promise<void>;
}

/**
 * Who is signed in on this device. Server data about them (the /me endpoint) lives in
 * TanStack Query; this store only holds the token, so the API client can read it outside
 * React.
 */
export const useSession = create<SessionState>()((set, get) => ({
  status: 'restoring',
  token: null,

  async restore() {
    if (get().status !== 'restoring') {
      return;
    }

    const token = await tokenStorage.get().catch(() => null);

    set({ token, status: token ? 'signedIn' : 'signedOut' });
  },

  async start(token) {
    set({ token, status: 'signedIn' });
    await tokenStorage.set(token);
  },

  async clear() {
    set({ token: null, status: 'signedOut' });
    await tokenStorage.clear().catch(() => undefined);
  },
}));
