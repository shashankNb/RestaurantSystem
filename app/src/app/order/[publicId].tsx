import { useQueryClient } from '@tanstack/react-query';
import * as Linking from 'expo-linking';
import { router, useLocalSearchParams } from 'expo-router';
import { Bell, Check, CircleAlert } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Platform, RefreshControl, ScrollView, View } from 'react-native';

import { useSession } from '@/auth/session';
import { useCart } from '@/cart/cart-store';
import { OrderTotals } from '@/components/order-totals';
import { Screen } from '@/components/screen';
import { ScreenHeader } from '@/components/screen-header';
import { EmptyState, ErrorState, LoadingState } from '@/components/states';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { ApiError, errorMessage } from '@/lib/api/client';
import { findMenuItem, useMenu } from '@/lib/api/menu';
import { FINAL_STATUSES, orderQueryKey, useOrder } from '@/lib/api/orders';
import { useRestaurant } from '@/lib/api/restaurant';
import type { Menu, Order } from '@/lib/api/schemas';
import { describeDay, formatTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { enableOrderNotifications, type PushResult } from '@/lib/push';
import { useOrderEvents } from '@/lib/realtime';
import { cn } from '@/lib/utils';
import { describeOrder, orderSteps, type StatusTone } from '@/orders/order-status';
import { useRecentOrders } from '@/orders/recent-orders';

const TONE: Record<StatusTone, string> = {
  waiting: 'bg-marigold',
  active: 'bg-marigold',
  good: 'bg-coriander',
  bad: 'bg-destructive',
};

/**
 * An order's live status. Opened after paying, from a notification, or from the tracking
 * link in the confirmation email (which carries ?token=). Updates arrive over Reverb; while
 * that's unavailable the screen polls.
 */
export default function OrderScreen() {
  const params = useLocalSearchParams<{ publicId: string; token?: string; redirect_status?: string }>();
  const publicId = params.publicId;
  const remembered = useRecentOrders((state) => state.orders.find((order) => order.publicId === publicId)?.trackingToken ?? null);
  const remember = useRecentOrders((state) => state.remember);
  const signedIn = useSession((state) => state.status === 'signedIn');
  const trackingToken = params.token ?? remembered;
  const queryClient = useQueryClient();
  const order = useOrder(publicId, trackingToken);
  const { data: restaurant } = useRestaurant();
  const [refreshing, setRefreshing] = useState(false);

  useOrderEvents(`order.${publicId}`, () => void queryClient.invalidateQueries({ queryKey: orderQueryKey(publicId) }));

  // A tracking link opened on this device: keep its token, so the order opens again later.
  useEffect(() => {
    if (params.token && params.token !== remembered && order.data) {
      remember(publicId, params.token);
    }
  }, [params.token, remembered, order.data, publicId, remember]);

  // Back from a bank's page after paying on the web: the cart has been ordered.
  useEffect(() => {
    if (params.redirect_status === 'succeeded') {
      useCart.getState().clear();
    }
  }, [params.redirect_status]);

  const refresh = async () => {
    setRefreshing(true);
    await order.refetch();
    setRefreshing(false);
  };

  const title = order.data?.order_number ? `Order ${order.data.order_number}` : 'Your order';
  let content;

  if (trackingToken === null && !signedIn) {
    content = <CantShow />;
  } else if (order.isPending) {
    content = <LoadingState label="Loading your order" />;
  } else if (order.isError) {
    content =
      order.error instanceof ApiError && order.error.status === 404 ? (
        <CantShow />
      ) : (
        <ErrorState
          title="We couldn’t load your order"
          message={errorMessage(order.error)}
          onRetry={() => void order.refetch()}
          retrying={order.isFetching}
        />
      );
  } else {
    content = (
      <ScrollView
        contentContainerClassName="w-full max-w-2xl gap-8 self-center px-4 pb-12 pt-2"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void refresh()} />}
      >
        {params.redirect_status === 'failed' ? (
          <Alert icon={CircleAlert} variant="destructive">
            <AlertDescription>Your payment didn’t go through, so nothing was charged. Go back to your cart to try again.</AlertDescription>
          </Alert>
        ) : null}
        <OrderStatusBlock order={order.data} timeZone={restaurant?.timezone ?? 'Australia/Melbourne'} />
        {!FINAL_STATUSES.includes(order.data.status) && Platform.OS !== 'web' ? (
          <NotificationsOptIn publicId={publicId} trackingToken={trackingToken} />
        ) : null}
        <WhereBlock order={order.data} />
        <ItemsBlock order={order.data} currency={restaurant?.currency ?? 'AUD'} />
        <OrderAgain order={order.data} />
      </ScrollView>
    );
  }

  return (
    <Screen>
      <ScreenHeader title={title} />
      {content}
    </Screen>
  );
}

function CantShow() {
  return (
    <EmptyState
      title="We can’t show this order"
      message="Open it from the link in your confirmation email, or sign in to the account you ordered with."
      action={
        <Button variant="outline" onPress={() => router.push('/account')}>
          <Text>Sign in</Text>
        </Button>
      }
    />
  );
}

function OrderStatusBlock({ order, timeZone }: { order: Order; timeZone: string }) {
  const { title, detail, tone } = describeOrder(order, timeZone);
  const steps = orderSteps(order);
  const showSteps = order.status !== 'pending_payment' && order.status !== 'rejected' && order.status !== 'cancelled';

  return (
    <View className="gap-6">
      <View className="gap-2" aria-live="polite">
        <View className="flex-row items-center gap-3">
          {order.status === 'pending_payment' ? (
            <ActivityIndicator className="text-muted-foreground" />
          ) : (
            <View className={cn('size-3 rounded-full', TONE[tone])} />
          )}
          <Text variant="heading" className="flex-1">
            {title}
          </Text>
        </View>
        {detail ? <Text className="text-muted-foreground">{detail}</Text> : null}
      </View>

      {showSteps ? (
        <View>
          {steps.map((step, index) => (
            <View key={step.status} className="flex-row gap-3">
              <View className="items-center">
                <View
                  className={cn(
                    'size-6 items-center justify-center rounded-full border-2',
                    step.state === 'done' && 'bg-coriander border-coriander',
                    step.state === 'current' && 'border-foreground',
                    step.state === 'upcoming' && 'border-border',
                  )}
                >
                  {step.state === 'done' ? <Icon as={Check} size={14} strokeWidth={3} className="text-background" /> : null}
                  {step.state === 'current' ? <View className="bg-foreground size-2.5 rounded-full" /> : null}
                </View>
                {index < steps.length - 1 ? (
                  <View className={cn('w-0.5 flex-1', step.state === 'done' ? 'bg-coriander' : 'bg-border')} />
                ) : null}
              </View>
              <View
                className="flex-1 flex-row justify-between gap-3 pb-5"
                accessible
                aria-label={`${step.label}: ${step.state === 'done' ? 'done' : step.state === 'current' ? 'now' : 'to come'}`}
              >
                <Text className={cn(step.state === 'upcoming' ? 'text-muted-foreground' : 'font-body-semibold')}>{step.label}</Text>
                {step.at ? <Text variant="muted">{formatTime(step.at, timeZone)}</Text> : null}
              </View>
            </View>
          ))}
        </View>
      ) : null}
    </View>
  );
}

/** Offers push notifications for this order. Asks for permission only when tapped. */
function NotificationsOptIn({ publicId, trackingToken }: { publicId: string; trackingToken: string | null }) {
  const queryClient = useQueryClient();
  const [result, setResult] = useState<PushResult | 'working' | 'failed' | null>(null);

  const turnOn = async () => {
    setResult('working');

    try {
      setResult(await enableOrderNotifications({ publicId, trackingToken }));
      // The next checkout picks up the token without asking.
      void queryClient.invalidateQueries({ queryKey: ['push-token'] });
    } catch {
      setResult('failed');
    }
  };

  const message: Partial<Record<NonNullable<typeof result>, string>> = {
    enabled: 'Notifications are on. We’ll tell you when your order is accepted and ready.',
    denied: 'Notifications are off for this app. You can turn them on in your phone’s Settings.',
    unavailable: 'This phone can’t get notifications from us. Keep this screen open for live updates.',
    failed: 'We couldn’t turn on notifications. Try again in a moment.',
  };

  return (
    <View className="bg-muted gap-3 rounded-md p-4">
      <View className="flex-row items-center gap-3">
        <Icon as={Bell} className="size-5" />
        <Text className="font-body-semibold flex-1">Get updates on this phone</Text>
      </View>
      {result !== null && result !== 'working' ? (
        <Text className="text-muted-foreground">{message[result]}</Text>
      ) : (
        <Button variant="outline" className="self-start" onPress={() => void turnOn()} disabled={result === 'working'}>
          <Text>{result === 'working' ? 'Turning on…' : 'Turn on notifications'}</Text>
        </Button>
      )}
    </View>
  );
}

function WhereBlock({ order }: { order: Order }) {
  const { data: restaurant } = useRestaurant();
  const timeZone = restaurant?.timezone ?? 'Australia/Melbourne';
  const placedAt = order.placed_at ?? order.created_at;
  const address = restaurant?.address;

  return (
    <View className="gap-2">
      {order.fulfilment_type === 'pickup' ? (
        <Text>
          <Text className="font-body-semibold">Pickup from </Text>
          {address?.line1 ? [address.line1, address.suburb].filter(Boolean).join(', ') : order.restaurant.name}
        </Text>
      ) : (
        <Text>
          <Text className="font-body-semibold">Delivery to </Text>
          {[order.delivery?.suburb, order.delivery?.postcode].filter(Boolean).join(' ')}
        </Text>
      )}
      {placedAt ? (
        <Text variant="muted">
          Placed {describeDay(placedAt, timeZone)} at {formatTime(placedAt, timeZone)}
        </Text>
      ) : null}
      {order.restaurant.phone ? (
        <Button variant="outline" className="mt-1 self-start" onPress={() => void Linking.openURL(`tel:${order.restaurant.phone?.replace(/\s/g, '')}`)}>
          <Text>Call {order.restaurant.name}</Text>
        </Button>
      ) : null}
    </View>
  );
}

function ItemsBlock({ order, currency }: { order: Order; currency: string }) {
  return (
    <View className="gap-4">
      <Text variant="heading">Your order</Text>
      <View>
        {order.items.map((item, index) => (
          <View key={index} className="border-border flex-row justify-between gap-4 border-b py-3">
            <View className="flex-1 gap-0.5">
              <Text className="font-body-semibold">
                {item.quantity} × {item.name}
              </Text>
              {item.modifiers.length > 0 ? (
                <Text variant="muted">{item.modifiers.map((modifier) => modifier.name).join(', ')}</Text>
              ) : null}
              {item.notes ? <Text variant="muted">Note: {item.notes}</Text> : null}
            </View>
            <Text className="font-body-medium">{formatMoney(item.line_total_cents, currency)}</Text>
          </View>
        ))}
      </View>
      <OrderTotals
        subtotalCents={order.subtotal_cents}
        deliveryFeeCents={order.delivery_fee_cents}
        discountCents={order.discount_cents}
        totalCents={order.total_cents}
        gstCents={order.gst_cents}
        delivery={order.fulfilment_type === 'delivery'}
        promoCode={order.promo_code}
        currency={currency}
      />
    </View>
  );
}

/** Puts this order's dishes, with the same choices, back in the cart. */
function OrderAgain({ order }: { order: Order }) {
  const menu = useMenu();
  const [notice, setNotice] = useState<string | null>(null);

  const orderAgain = () => {
    if (!menu.data) {
      return;
    }

    const { added, skipped } = addToCart(order, menu.data);

    if (added === 0) {
      setNotice('None of these dishes are on the menu right now.');

      return;
    }

    router.push({ pathname: '/cart', params: skipped > 0 ? { skipped: String(skipped) } : {} });
  };

  return (
    <View className="gap-3">
      {notice ? <Text className="text-muted-foreground">{notice}</Text> : null}
      <View className="flex-row flex-wrap gap-3">
        <Button onPress={orderAgain} disabled={!menu.data}>
          <Text>Order again</Text>
        </Button>
        <Button variant="outline" onPress={() => (router.canDismiss() ? router.dismissAll() : router.replace('/'))}>
          <Text>Back to the menu</Text>
        </Button>
      </View>
    </View>
  );
}

/** Adds each dish still on the menu, with its choices that are still offered. */
function addToCart(order: Order, menu: Menu): { added: number; skipped: number } {
  const cart = useCart.getState();
  let added = 0;

  for (const item of order.items) {
    const menuItem = item.menu_item_id === null ? undefined : findMenuItem(menu, item.menu_item_id);

    if (!menuItem?.is_available) {
      continue;
    }

    const wanted = new Set(item.modifiers.map((modifier) => modifier.modifier_option_id));
    const options = menuItem.modifier_groups.flatMap((group) => group.options.filter((option) => option.is_available && wanted.has(option.id)));

    cart.add({
      menuItemId: menuItem.id,
      name: menuItem.name,
      unitPriceCents: menuItem.price_cents + options.reduce((sum, option) => sum + option.price_delta_cents, 0),
      quantity: item.quantity,
      optionIds: options.map((option) => option.id),
      optionSummary: options.map((option) => option.name).join(', '),
      notes: item.notes,
    });
    added += 1;
  }

  if (added > 0) {
    cart.setFulfilment(order.fulfilment_type);

    if (order.fulfilment_type === 'delivery' && order.delivery?.postcode) {
      cart.setPostcode(order.delivery.postcode);
    }
  }

  return { added, skipped: order.items.length - added };
}
