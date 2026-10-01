import { StatusBar } from 'expo-status-bar';
import { Clock } from 'lucide-react-native';
import { useEffect, useRef, useState } from 'react';
import { RefreshControl, ScrollView, View, type NativeScrollEvent, type NativeSyntheticEvent } from 'react-native';
import { useReducedMotion } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useCart, type CartLine } from '@/cart/cart-store';
import { ActiveOrderBanner } from '@/components/active-order-banner';
import { CartBar } from '@/components/cart-bar';
import { CategoryTabs } from '@/components/category-tabs';
import { MenuItemRow } from '@/components/menu-item-row';
import { OpeningHours } from '@/components/opening-hours';
import { RestaurantHeader, RestaurantHeaderSkeleton } from '@/components/restaurant-header';
import { Screen } from '@/components/screen';
import { SegmentedControl } from '@/components/segmented-control';
import { EmptyState, ErrorState } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { errorMessage } from '@/lib/api/client';
import { useMenu } from '@/lib/api/menu';
import { useRestaurant } from '@/lib/api/restaurant';
import type { Restaurant } from '@/lib/api/schemas';
import { formatMoney } from '@/lib/money';

/** The category chips are the third child of the scroll view: header, ordering options, chips. */
const TABS_INDEX = 2;

/**
 * The menu: the restaurant's header, pickup or delivery, then the dishes by category under
 * a row of category chips that sticks to the top and follows the scroll. Tapping a dish
 * opens its options; once something is in the cart, "View cart" sits at the bottom.
 */
export default function MenuScreen() {
  const restaurant = useRestaurant();
  const menu = useMenu();
  const insets = useSafeAreaInsets();
  const reduceMotion = useReducedMotion();
  const lines = useCart((state) => state.lines);
  const scrollRef = useRef<ScrollView>(null);
  // Where things are in the scroll view, measured as they lay out.
  const positions = useRef({ bandBottom: 0, tabsHeight: 0, sections: new Map<number, number>() });
  const [activeId, setActiveId] = useState<number | null>(null);
  const [pastBand, setPastBand] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const refresh = async () => {
    setRefreshing(true);
    await Promise.all([restaurant.refetch(), menu.refetch()]);
    setRefreshing(false);
  };

  if (restaurant.isPending) {
    return (
      <Screen underStatusBar>
        <StatusBar style="light" />
        <RestaurantHeaderSkeleton />
        <MenuSkeleton />
      </Screen>
    );
  }

  if (restaurant.isError) {
    return (
      <Screen>
        <ErrorState
          title="We couldn’t load the restaurant"
          message={errorMessage(restaurant.error)}
          onRetry={() => void restaurant.refetch()}
          retrying={restaurant.isFetching}
        />
      </Screen>
    );
  }

  const { currency } = restaurant.data;
  const categories = (menu.data?.categories ?? []).filter((category) => category.items.length > 0);
  const active = activeId ?? categories[0]?.id ?? null;
  const inCart = countByItem(lines);

  const onScroll = (event: NativeSyntheticEvent<NativeScrollEvent>) => {
    const y = event.nativeEvent.contentOffset.y;
    const { bandBottom, tabsHeight, sections } = positions.current;
    let current = categories[0]?.id ?? null;

    for (const category of categories) {
      const top = sections.get(category.id);

      if (top !== undefined && top <= y + tabsHeight + 1) {
        current = category.id;
      }
    }

    setActiveId(current);
    setPastBand(y > bandBottom - insets.top);
  };

  const jumpTo = (id: number) => {
    const top = positions.current.sections.get(id);

    if (top !== undefined) {
      setActiveId(id);
      scrollRef.current?.scrollTo({ y: Math.max(0, top - positions.current.tabsHeight), animated: !reduceMotion });
    }
  };

  return (
    <Screen underStatusBar>
      {/* Light over the brand band; dark once the page colour is behind the status bar. */}
      <StatusBar style={pastBand ? 'auto' : 'light'} />
      <ScrollView
        ref={scrollRef}
        className="flex-1"
        contentContainerClassName="pb-6"
        stickyHeaderIndices={categories.length > 0 ? [TABS_INDEX] : undefined}
        onScroll={onScroll}
        scrollEventThrottle={32}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void refresh()} />}
      >
        <View onLayout={(event) => (positions.current.bandBottom = event.nativeEvent.layout.height)}>
          <RestaurantHeader restaurant={restaurant.data} />
        </View>

        <OrderingOptions restaurant={restaurant.data} bottomSpace={categories.length > 0 ? insets.top : 0} />

        {categories.length > 0 ? (
          <CategoryTabs
            categories={categories}
            active={active}
            onSelect={jumpTo}
            topInset={insets.top}
            onLayout={(event) => (positions.current.tabsHeight = event.nativeEvent.layout.height)}
          />
        ) : menu.isPending ? (
          <MenuSkeleton />
        ) : menu.isError ? (
          <ErrorState
            title="We couldn’t load the menu"
            message={errorMessage(menu.error)}
            onRetry={() => void menu.refetch()}
            retrying={menu.isFetching}
          />
        ) : (
          <EmptyState
            title="The menu is empty for now"
            message={
              restaurant.data.phone
                ? `Check back soon, or call us on ${restaurant.data.phone} to order.`
                : 'Check back soon.'
            }
          />
        )}

        {categories.map((category) => (
          <View
            key={category.id}
            onLayout={(event) => positions.current.sections.set(category.id, event.nativeEvent.layout.y)}
            className="w-full max-w-3xl self-center pt-6"
          >
            <View className="gap-1 px-4 pb-2">
              <Text variant="heading">{category.name}</Text>
              {category.description ? <Text variant="muted">{category.description}</Text> : null}
            </View>
            {category.items.map((item) => (
              <MenuItemRow key={item.id} item={item} inCart={inCart.get(item.id) ?? 0} currency={currency} />
            ))}
          </View>
        ))}

        <View className="w-full max-w-3xl self-center pt-4">
          <OpeningHours restaurant={restaurant.data} />
        </View>
      </ScrollView>
      <CartBar currency={currency} />
    </Screen>
  );
}

/**
 * Pickup or delivery, what that means here, and what to do when the restaurant can't take
 * an order right now. `bottomSpace` leaves room for the category chips to reach up into
 * (see CategoryTabs).
 */
function OrderingOptions({ restaurant, bottomSpace }: { restaurant: Restaurant; bottomSpace: number }) {
  const fulfilment = useCart((state) => state.fulfilment);
  const setFulfilment = useCart((state) => state.setFulfilment);
  const { pickup, delivery } = restaurant.fulfilment;
  const offered = pickup.enabled && !delivery.enabled ? 'pickup' : delivery.enabled && !pickup.enabled ? 'delivery' : null;

  // A choice saved in the cart that the restaurant no longer offers.
  useEffect(() => {
    if (offered !== null && offered !== fulfilment) {
      setFulfilment(offered);
    }
  }, [offered, fulfilment, setFulfilment]);

  const lowestFee = Math.min(...delivery.zones.map((zone) => zone.fee_cents));
  const note =
    fulfilment === 'delivery'
      ? delivery.zones.length > 0
        ? `Delivery ${lowestFee === 0 ? 'is free to nearby suburbs' : `from ${formatMoney(lowestFee, restaurant.currency)}`}. Check your postcode in your cart.`
        : null
      : `Pickup${restaurant.address?.line1 ? ` from ${restaurant.address.line1}${restaurant.address.suburb ? `, ${restaurant.address.suburb}` : ''}` : ''}. Usually ready in about ${pickup.prep_minutes} minutes.`;

  const { status } = restaurant;
  const notice = status.can_order_asap
    ? null
    : !status.is_open
      ? 'We’re closed right now'
      : !status.is_accepting_orders
        ? 'We’ve paused new orders for now'
        : 'We can’t take orders for right now';

  return (
    <View className="w-full max-w-3xl gap-3 self-center px-4 pt-4" style={{ paddingBottom: 16 + bottomSpace }}>
      <ActiveOrderBanner timeZone={restaurant.timezone} />
      {pickup.enabled && delivery.enabled ? (
        <SegmentedControl
          label="Pickup or delivery"
          value={fulfilment}
          onChange={setFulfilment}
          options={[
            { value: 'pickup', label: 'Pickup' },
            { value: 'delivery', label: 'Delivery' },
          ]}
        />
      ) : null}
      {note ? <Text variant="muted">{note}</Text> : null}
      {notice ? (
        <Alert icon={Clock}>
          <AlertTitle>{notice}</AlertTitle>
          <AlertDescription>You can still order ahead: choose a time in your cart.</AlertDescription>
        </Alert>
      ) : null}
    </View>
  );
}

function MenuSkeleton() {
  return (
    <View className="w-full max-w-3xl gap-8 self-center px-4 pt-6" role="progressbar" aria-label="Loading the menu">
      {[0, 1].map((section) => (
        <View key={section} className="gap-5">
          <Skeleton className="h-7 w-40" />
          {[0, 1, 2].map((row) => (
            <View key={row} className="gap-2">
              <Skeleton className="h-5 w-1/2" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-20" />
            </View>
          ))}
        </View>
      ))}
    </View>
  );
}

/** How many of each dish are in the cart, across lines with different choices. */
function countByItem(lines: CartLine[]): Map<number, number> {
  const counts = new Map<number, number>();

  for (const line of lines) {
    counts.set(line.menuItemId, (counts.get(line.menuItemId) ?? 0) + line.quantity);
  }

  return counts;
}
