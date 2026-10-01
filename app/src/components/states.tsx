import type { ReactNode } from 'react';
import { ActivityIndicator, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';

/** A centred spinner for whole-screen loads where a skeleton wouldn't fit. */
export function LoadingState({ label = 'Loading' }: { label?: string }) {
  return (
    <View className="flex-1 items-center justify-center gap-3 p-6" role="progressbar" aria-label={label}>
      <ActivityIndicator className="text-muted-foreground" />
      <Text variant="muted">{label}…</Text>
    </View>
  );
}

/**
 * Something failed to load. Says what went wrong and offers the fix: usually "Try again".
 */
export function ErrorState({
  title,
  message,
  onRetry,
  retrying = false,
}: {
  title: string;
  message: string;
  onRetry?: () => void;
  retrying?: boolean;
}) {
  return (
    <View className="items-start gap-3 px-4 py-8" role="alert">
      <Text variant="heading">{title}</Text>
      <Text className="text-muted-foreground max-w-xl">{message}</Text>
      {onRetry ? (
        <Button variant="outline" onPress={onRetry} disabled={retrying}>
          <Text>{retrying ? 'Trying again…' : 'Try again'}</Text>
        </Button>
      ) : null}
    </View>
  );
}

/** Nothing to show yet. Tells people what to do next. */
export function EmptyState({ title, message, action }: { title: string; message: string; action?: ReactNode }) {
  return (
    <View className="items-start gap-3 px-4 py-8">
      <Text variant="heading">{title}</Text>
      <Text className="text-muted-foreground max-w-xl">{message}</Text>
      {action}
    </View>
  );
}
