import Echo, { type ConnectionStatus } from 'laravel-echo';
// Metro picks pusher-js's React Native build on iOS and Android and its browser build on
// the web (the package's "react-native" and "browser" fields).
import Pusher from 'pusher-js';
import { useEffect, useEffectEvent, useSyncExternalStore } from 'react';
import { Platform } from 'react-native';

import { useSession } from '@/auth/session';
import { apiRequest } from '@/lib/api/client';
import { config } from '@/lib/config';

type ReverbEcho = Echo<'reverb'>;

let echo: ReverbEcho | null = null;

/**
 * The shared Reverb connection, created on first use. None while rendering on the server,
 * or when Reverb isn't configured (the screens then poll instead).
 */
function connection(): ReverbEcho | null {
  if (Platform.OS === 'web' && typeof window === 'undefined') {
    return null;
  }

  if (!config.reverb.appKey) {
    return null;
  }

  echo ??= new Echo({
    broadcaster: 'reverb',
    key: config.reverb.appKey,
    wsHost: config.reverb.host,
    wsPort: config.reverb.port,
    wssPort: config.reverb.port,
    forceTLS: config.reverb.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    Pusher,
    // Private channels (the kitchen's) are authorised with whoever is signed in right now.
    channelAuthorization: {
      transport: 'ajax',
      endpoint: `${config.apiUrl}/broadcasting/auth`,
      customHandler: (params: { socketId: string; channelName: string }, callback: (error: Error | null, data: { auth: string } | null) => void) => {
        apiRequest<{ auth: string }>('/broadcasting/auth', {
          method: 'POST',
          body: { socket_id: params.socketId, channel_name: params.channelName },
        })
          .then((data) => callback(null, data))
          .catch((error: unknown) => callback(error instanceof Error ? error : new Error(String(error)), null));
      },
    },
  });

  return echo;
}

/** Whether live updates are flowing: 'connected', or anything else (the screens then poll). */
export function useRealtimeStatus(): ConnectionStatus {
  return useSyncExternalStore(
    (onChange) => connection()?.connector.onConnectionChange(onChange) ?? (() => {}),
    () => connection()?.connector.connectionStatus() ?? 'disconnected',
    () => 'disconnected',
  );
}

/** How many mounted listeners each channel has; it's left when the last one goes. */
const listeners = new Map<string, number>();

/**
 * Calls `onUpdate` with each `order.updated` event on a channel while mounted. The payload
 * carries status and times only; callers refetch the details they show. Several screens can
 * follow the same channel (the menu's order banner and the order screen).
 */
export function useOrderEvents(channelName: string | null, onUpdate: (event: OrderEvent) => void, privateChannel = false): void {
  const handle = useEffectEvent(onUpdate);
  const token = useSession((state) => state.token);

  useEffect(() => {
    const client = connection();

    if (client === null || channelName === null) {
      return;
    }

    const channel = privateChannel ? client.private(channelName) : client.channel(channelName);
    const listener = (event: OrderEvent) => handle(event);
    channel.listen('.order.updated', listener);
    listeners.set(channelName, (listeners.get(channelName) ?? 0) + 1);

    return () => {
      channel.stopListening('.order.updated', listener);
      const remaining = (listeners.get(channelName) ?? 1) - 1;

      if (remaining > 0) {
        listeners.set(channelName, remaining);
      } else {
        listeners.delete(channelName);
        client.leave(channelName);
      }
    };
  }, [channelName, privateChannel, token]);
}

export interface OrderEvent {
  public_id: string;
  order_number: string | null;
  status: string;
  fulfilment_type: string;
  estimated_ready_at: string | null;
  updated_at: string | null;
}
