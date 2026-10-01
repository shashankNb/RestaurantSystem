import { onlineManager } from '@tanstack/react-query';
import { useSyncExternalStore } from 'react';
import { View } from 'react-native';

import { Text } from '@/components/ui/text';

const subscribe = (onChange: () => void) => onlineManager.subscribe(onChange);
const isOnline = () => onlineManager.isOnline();
const onlineOnServer = () => true;

/**
 * Shown while the device is offline. TanStack Query pauses requests meanwhile and retries
 * them when the connection returns.
 */
export function OfflineBanner() {
  const online = useSyncExternalStore(subscribe, isOnline, onlineOnServer);

  if (online) {
    return null;
  }

  return (
    <View className="bg-marigold px-4 py-2" role="alert">
      <Text className="text-on-marigold font-body-medium text-sm">
        You’re offline. What you see may be out of date until you reconnect.
      </Text>
    </View>
  );
}
