/**
 * Web: Expo's push service doesn't reach browsers, so the website relies on live updates
 * and polling instead. Same interface as push.ts.
 */
export type PushResult = 'enabled' | 'denied' | 'unavailable';

export async function enableOrderNotifications(_order: { publicId: string; trackingToken: string | null }): Promise<PushResult> {
  return 'unavailable';
}

export async function pushTokenIfAllowed(): Promise<string | null> {
  return null;
}

export function useNotificationTaps(): void {}
