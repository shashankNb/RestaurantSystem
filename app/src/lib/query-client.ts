import NetInfo from '@react-native-community/netinfo';
import { focusManager, onlineManager, QueryClient } from '@tanstack/react-query';
import { AppState, Platform } from 'react-native';

import { ApiError } from '@/lib/api/client';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      // A 4xx answer won't change on a retry; network and server errors might.
      retry: (failureCount, error) =>
        !(error instanceof ApiError && error.status >= 400 && error.status < 500) && failureCount < 2,
    },
    mutations: {
      retry: false,
    },
  },
});

/**
 * Browsers tell TanStack Query when the page regains focus or the connection comes back;
 * on iOS and Android it needs to be told (NetInfo, which pusher-js also relies on).
 * Returns a cleanup function.
 */
export function connectQueryManagers(): () => void {
  if (Platform.OS === 'web') {
    return () => {};
  }

  onlineManager.setEventListener((setOnline) => NetInfo.addEventListener((state) => setOnline(state.isConnected !== false)));

  const appState = AppState.addEventListener('change', (status) => focusManager.setFocused(status === 'active'));

  return () => appState.remove();
}
