import { router, type Href } from 'expo-router';
import { Platform, Pressable, View } from 'react-native';

import { ChevronLeft } from '@/components/icons';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { cn } from '@/lib/utils';

/**
 * A screen's title with a way back: to the previous screen, or to `fallback` when the
 * screen was opened directly (a shared link on the web).
 */
export function ScreenHeader({
  title,
  backLabel = 'Back to the menu',
  fallback = '/',
}: {
  title: string;
  backLabel?: string;
  fallback?: Href;
}) {
  const goBack = () => (router.canGoBack() ? router.back() : router.replace(fallback));

  return (
    <View className="flex-row items-center gap-1 px-2 pb-2 pt-1">
      <Pressable
        onPress={goBack}
        role="button"
        aria-label={backLabel}
        className={cn(
          'size-11 items-center justify-center rounded-full active:bg-accent',
          Platform.select({
            web: 'hover:bg-accent focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-solid',
          }),
        )}
      >
        <Icon as={ChevronLeft} className="size-6" />
      </Pressable>
      {/* The page's own title, under the restaurant's name in the browser's tab. */}
      <Text variant="heading" aria-level="1">
        {title}
      </Text>
    </View>
  );
}
