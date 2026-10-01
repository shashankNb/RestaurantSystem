import { Link } from 'expo-router';
import { View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { cartSummary, useCart } from '@/cart/cart-store';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { formatMoney } from '@/lib/money';

/** "View cart · 3 items · $52.70", pinned to the bottom of the menu once the cart has something in it. */
export function CartBar({ currency }: { currency: string }) {
  const lines = useCart((state) => state.lines);
  const insets = useSafeAreaInsets();
  const { count, totalCents } = cartSummary(lines);

  if (count === 0) {
    return null;
  }

  return (
    <View className="bg-background border-border border-t px-4 pt-3" style={{ paddingBottom: Math.max(insets.bottom, 12) }}>
      <Link href="/cart" asChild>
        <Button size="lg" className="w-full max-w-3xl justify-between self-center">
          <Text>View cart</Text>
          <Text>
            {count} {count === 1 ? 'item' : 'items'} · {formatMoney(totalCents, currency)}
          </Text>
        </Button>
      </Link>
    </View>
  );
}
