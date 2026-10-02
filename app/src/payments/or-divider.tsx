import { View } from 'react-native';

import { Text } from '@/components/ui/text';

/** Between the Apple Pay / Google Pay button and the card form. */
export function OrDivider() {
  return (
    <View className="flex-row items-center gap-3" aria-hidden>
      <View className="bg-border h-px flex-1" />
      <Text variant="muted">or pay by card</Text>
      <View className="bg-border h-px flex-1" />
    </View>
  );
}
