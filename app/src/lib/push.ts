import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { router } from 'expo-router';
import { useEffect } from 'react';
import { Platform } from 'react-native';

import { useSession } from '@/auth/session';
import { apiRequest } from '@/lib/api/client';
import { config } from '@/lib/config';

// Show order updates even while the app is open.
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
});

export type PushResult = 'enabled' | 'denied' | 'unavailable';

/**
 * Asks to send notifications about an order and registers this device with the API:
 * for the account when signed in, otherwise for this one order. Called when the customer
 * taps "Turn on notifications", never unprompted.
 */
export async function enableOrderNotifications(order: { publicId: string; trackingToken: string | null }): Promise<PushResult> {
  // Android 13 and later only show the permission prompt once the app has a channel.
  await createChannel();

  const current = await Notifications.getPermissionsAsync();
  const permission = current.granted ? current : await Notifications.requestPermissionsAsync();

  if (!permission.granted) {
    return 'denied';
  }

  const token = await expoPushToken();

  if (token === null) {
    return 'unavailable';
  }

  const signedIn = useSession.getState().status === 'signedIn';

  await apiRequest('/push-tokens', {
    method: 'POST',
    body: {
      expo_push_token: token,
      platform: Platform.OS,
      ...(signedIn
        ? { restaurant: config.restaurantSlug }
        : { order: { public_id: order.publicId, tracking_token: order.trackingToken } }),
    },
  });

  return 'enabled';
}

/** Whether this device already lets the app send notifications. */
async function notificationsAllowed(): Promise<boolean> {
  return (await Notifications.getPermissionsAsync()).granted;
}

/**
 * This device's push token when notifications are already allowed, without asking. Sent
 * with a new order so its updates arrive with no extra step. Null otherwise.
 */
export async function pushTokenIfAllowed(): Promise<string | null> {
  if (!(await notificationsAllowed())) {
    return null;
  }

  await createChannel();

  return expoPushToken();
}

/** Android: the channel order updates arrive on (Settings shows it as "Order updates"). */
async function createChannel(): Promise<void> {
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', {
      name: 'Order updates',
      importance: Notifications.AndroidImportance.HIGH,
    });
  }
}

async function expoPushToken(): Promise<string | null> {
  const projectId = (Constants.expoConfig?.extra?.eas as { projectId?: string } | undefined)?.projectId ?? Constants.easConfig?.projectId;

  if (!projectId) {
    return null;
  }

  try {
    return (await Notifications.getExpoPushTokenAsync({ projectId })).data;
  } catch {
    // Simulators and devices without Google Play services can't get a push token.
    return null;
  }
}

/**
 * Opens the order a tapped notification is about, including the tap that launched the
 * app. Mount once, in the root layout.
 */
export function useNotificationTaps(): void {
  useEffect(() => {
    const open = (response: Notifications.NotificationResponse) => {
      const publicId = response.notification.request.content.data?.public_id;

      if (typeof publicId === 'string') {
        router.push({ pathname: '/order/[publicId]', params: { publicId } });
      }

      // Handled; don't open it again next time the layout mounts.
      Notifications.clearLastNotificationResponse();
    };

    const launchedBy = Notifications.getLastNotificationResponse();

    if (launchedBy) {
      open(launchedBy);
    }

    const subscription = Notifications.addNotificationResponseReceivedListener(open);

    return () => subscription.remove();
  }, []);
}
