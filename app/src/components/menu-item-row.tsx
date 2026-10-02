import { Image } from 'expo-image';
import { Link } from 'expo-router';
import { Platform, Pressable, View } from 'react-native';

import { Text } from '@/components/ui/text';
import type { MenuItem } from '@/lib/api/schemas';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

const THUMBNAIL = 88;

/**
 * A dish on the menu: name, description, price and dietary tags, with a photo when there
 * is one. Opens the item's options; a sold-out dish stays visible but can't be opened.
 */
export function MenuItemRow({ item, inCart, currency }: { item: MenuItem; inCart: number; currency: string }) {
  const soldOut = !item.is_available;
  const price = formatMoney(item.price_cents, currency);
  const tags = item.dietary_tags.map((tag) => tag.label);
  const spoken = [item.name, price, ...tags, soldOut ? 'sold out' : null, inCart > 0 ? `${inCart} in your cart` : null]
    .filter(Boolean)
    .join(', ');

  const content = (
    <>
      <View className="flex-1 gap-1">
        <Text variant="item" className={cn(soldOut && 'text-muted-foreground')}>
          {item.name}
        </Text>
        {item.description ? (
          <Text variant="muted" numberOfLines={2}>
            {item.description}
          </Text>
        ) : null}
        <Text className="pt-0.5">
          <Text className={cn('font-body-semibold', soldOut && 'text-muted-foreground')}>{price}</Text>
          {tags.length > 0 ? <Text className="text-muted-foreground text-sm"> · {tags.join(' · ')}</Text> : null}
          {soldOut ? <Text className="text-destructive font-body-medium text-sm"> · Sold out</Text> : null}
          {inCart > 0 ? <Text className="text-brand-text font-body-medium text-sm"> · {inCart} in cart</Text> : null}
        </Text>
      </View>
      {item.image_url ? (
        <Image
          source={{ uri: item.image_url }}
          style={{ width: THUMBNAIL, height: THUMBNAIL, borderRadius: 8, opacity: soldOut ? 0.5 : 1 }}
          contentFit="cover"
          transition={150}
          accessible={false}
          // Decorative; on the web, expo-image takes alt="" from this.
          accessibilityLabel=""
        />
      ) : null}
    </>
  );

  const rowClass = 'border-border flex-row gap-4 border-b px-4 py-4';

  if (soldOut) {
    return (
      <View className={rowClass} accessible aria-label={spoken}>
        {content}
      </View>
    );
  }

  return (
    <Link href={{ pathname: '/item/[id]', params: { id: String(item.id) } }} asChild>
      <Pressable
        aria-label={spoken}
        className={cn(
          rowClass,
          'active:bg-accent',
          Platform.select({
            web: 'hover:bg-accent focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:-outline-offset-2',
          }),
        )}
      >
        {content}
      </Pressable>
    </Link>
  );
}
