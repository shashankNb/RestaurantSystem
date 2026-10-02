import { Image } from 'expo-image';
import { Link } from 'expo-router';
import { Platform, Pressable, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { UserRound } from '@/components/icons';
import { OfflineBanner } from '@/components/offline-banner';
import { Icon } from '@/components/ui/icon';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import type { Restaurant } from '@/lib/api/schemas';
import { describeOpening, type OpeningTone } from '@/lib/format';
import { cn } from '@/lib/utils';

const HERO_HEIGHT = 200;

const DOT: Record<OpeningTone, string> = {
  open: 'bg-coriander',
  paused: 'bg-marigold',
  closed: 'bg-destructive',
};

const LABEL: Record<OpeningTone, string> = {
  open: 'text-coriander',
  paused: 'text-foreground',
  closed: 'text-destructive',
};

/**
 * The one loud moment of the design: the cover photo (or, without one, a band of the
 * brand colour) with the restaurant's name and whether it's open.
 */
export function RestaurantHeader({ restaurant }: { restaurant: Restaurant }) {
  const insets = useSafeAreaInsets();

  if (restaurant.cover_image_url === null) {
    return (
      <View>
        <View className="bg-primary pb-6" style={{ paddingTop: insets.top + 8 }}>
          {/* The band spans the page; its content lines up with the menu's column. */}
          <View className="w-full max-w-3xl gap-2 self-center px-4">
            <View className="flex-row justify-end">
              <AccountButton surface="brand" />
            </View>
            <View className="flex-row items-center gap-3">
              <Logo url={restaurant.logo_url} />
              <Text variant="display" className="text-primary-foreground flex-1">
                {restaurant.name}
              </Text>
            </View>
            <OpeningStatus restaurant={restaurant} onBrand />
            {restaurant.description ? (
              <Text className="text-primary-foreground max-w-xl">{restaurant.description}</Text>
            ) : null}
          </View>
        </View>
        <OfflineBanner />
      </View>
    );
  }

  return (
    <View>
      <View>
        <Image
          source={{ uri: restaurant.cover_image_url }}
          style={{ width: '100%', height: HERO_HEIGHT + insets.top }}
          contentFit="cover"
          accessible={false}
          // Decorative; on the web, expo-image takes alt="" from this.
          accessibilityLabel=""
        />
        <View className="absolute right-3" style={{ top: insets.top + 8 }}>
          <AccountButton surface="photo" />
        </View>
      </View>
      <OfflineBanner />
      <View className="w-full max-w-3xl gap-2 self-center px-4 pt-4">
        <View className="flex-row items-center gap-3">
          <Logo url={restaurant.logo_url} />
          <Text variant="display" className="flex-1">
            {restaurant.name}
          </Text>
        </View>
        <OpeningStatus restaurant={restaurant} />
        {restaurant.description ? (
          <Text className="text-muted-foreground max-w-xl">{restaurant.description}</Text>
        ) : null}
      </View>
    </View>
  );
}

/**
 * The restaurant's logo from its settings, on a tile of the page colour so any logo shows on
 * the brand band or a photo. Decorative: the name is right beside it.
 */
function Logo({ url }: { url: string | null }) {
  if (url === null) {
    return null;
  }

  return (
    <View className="bg-background size-14 overflow-hidden rounded-md">
      <Image source={{ uri: url }} style={{ width: '100%', height: '100%' }} contentFit="contain" transition={150} accessible={false} accessibilityLabel="" />
    </View>
  );
}

/** Stands in for the header while the restaurant loads. */
export function RestaurantHeaderSkeleton() {
  const insets = useSafeAreaInsets();

  return (
    <View className="bg-primary gap-3 px-4 pb-6" style={{ paddingTop: insets.top + 60 }}>
      <Skeleton className="bg-primary-foreground/25 h-9 w-3/4" />
      <Skeleton className="bg-primary-foreground/25 h-5 w-1/2" />
    </View>
  );
}

function OpeningStatus({ restaurant, onBrand = false }: { restaurant: Restaurant; onBrand?: boolean }) {
  const { tone, label, detail } = describeOpening(restaurant);

  return (
    <View
      className="flex-row flex-wrap items-center gap-x-2 gap-y-1"
      accessible
      aria-label={detail ? `${label}, ${detail}` : label}
    >
      <View className={cn('size-2 rounded-full', DOT[tone])} />
      <Text variant="small" className={onBrand ? 'text-primary-foreground' : LABEL[tone]}>
        {label}
      </Text>
      {detail ? (
        <Text className={cn('text-sm', onBrand ? 'text-primary-foreground' : 'text-muted-foreground')}>· {detail}</Text>
      ) : null}
    </View>
  );
}

function AccountButton({ surface }: { surface: 'brand' | 'photo' }) {
  return (
    <Link href="/account" asChild>
      <Pressable
        aria-label="Account"
        className={cn(
          'size-11 items-center justify-center rounded-full',
          surface === 'photo' && 'bg-scrim',
          Platform.select({
            web: cn(
              'outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2',
              surface === 'brand' ? 'focus-visible:outline-primary-foreground' : 'focus-visible:outline-on-photo',
            ),
          }),
        )}
      >
        <Icon
          as={UserRound}
          className={cn('size-6', surface === 'brand' ? 'text-primary-foreground' : 'text-on-photo')}
        />
      </Pressable>
    </Link>
  );
}
