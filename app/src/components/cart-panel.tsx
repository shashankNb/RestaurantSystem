import { Link } from 'expo-router';
import { ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { cartSummary, useCart } from '@/cart/cart-store';
import { CartLineRow } from '@/components/cart-line-row';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { formatMoney } from '@/lib/money';

/**
 * Wide screens (1024 px and up): the cart beside the menu, in place of the "View cart"
 * bar, and like the bar only once something is in it; until then the menu has the whole
 * width. Quantities and choices can be changed here; "View cart" opens the full cart for
 * the time, delivery, promo code and checkout. Hidden on narrower screens by CSS, so the
 * page the server renders is the same at every width.
 */
export function CartPanel({ currency }: { currency: string }) {
  const lines = useCart((state) => state.lines);
  const { setQuantity, remove } = useCart.getState();
  const { count, totalCents } = cartSummary(lines);
  const insets = useSafeAreaInsets();

  // A saved cart fills in after the page's first render (the server has none), so the page
  // starts without the panel in the browser too, and it appears once the cart has loaded.
  if (count === 0) {
    return null;
  }

  return (
    <View role="region" aria-label="Your cart" className="border-border bg-background hidden w-96 border-l lg:flex">
      {/* The menu runs under the status bar (a tablet in landscape); the panel starts below it. */}
      <View className="px-6 pb-2" style={{ paddingTop: insets.top + 24 }}>
        <Text variant="heading">Your cart</Text>
      </View>
      <ScrollView className="flex-1" contentContainerClassName="px-6">
        {lines.map((line) => (
          <CartLineRow
            key={line.id}
            line={line}
            currency={currency}
            onQuantity={(quantity) => setQuantity(line.id, quantity)}
            onRemove={() => remove(line.id)}
          />
        ))}
      </ScrollView>
      <View className="border-border gap-3 border-t px-6 pt-4 pb-6">
        <View className="flex-row justify-between gap-4">
          <Text>Subtotal</Text>
          <Text className="font-body-semibold">{formatMoney(totalCents, currency)}</Text>
        </View>
        <Link href="/cart" asChild>
          <Button size="lg">
            <Text>
              View cart · {count} {count === 1 ? 'item' : 'items'}
            </Text>
          </Button>
        </Link>
      </View>
    </View>
  );
}
