import { useQueryClient } from '@tanstack/react-query';
import { Link } from 'expo-router';
import { Platform, Pressable, View } from 'react-native';

import { ChevronRight } from '@/components/icons';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { FINAL_STATUSES, orderQueryKey, useOrder } from '@/lib/api/orders';
import { useOrderEvents } from '@/lib/realtime';
import { cn } from '@/lib/utils';
import { describeOrder } from '@/orders/order-status';
import { isRecent, useRecentOrders } from '@/orders/recent-orders';

/**
 * On the menu while an order placed on this device is still under way: where it's at, and
 * a way back to tracking it.
 */
export function ActiveOrderBanner({ timeZone }: { timeZone: string }) {
  const latest = useRecentOrders((state) => state.orders[0]);

  if (latest === undefined || !isRecent(latest)) {
    return null;
  }

  return <Banner publicId={latest.publicId} trackingToken={latest.trackingToken} timeZone={timeZone} />;
}

function Banner({ publicId, trackingToken, timeZone }: { publicId: string; trackingToken: string; timeZone: string }) {
  const queryClient = useQueryClient();
  const order = useOrder(publicId, trackingToken);

  useOrderEvents(`order.${publicId}`, () => void queryClient.invalidateQueries({ queryKey: orderQueryKey(publicId) }));

  if (!order.data || FINAL_STATUSES.includes(order.data.status)) {
    return null;
  }

  const { title } = describeOrder(order.data, timeZone);

  return (
    <Link href={{ pathname: '/order/[publicId]', params: { publicId } }} asChild>
      <Pressable
        className={cn(
          'bg-muted flex-row items-center gap-3 rounded-md px-4 py-3 active:bg-accent',
          Platform.select({ web: 'focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2' }),
        )}
      >
        <View className="flex-1 gap-0.5">
          <Text variant="small">{order.data.order_number ? `Order ${order.data.order_number}` : 'Your order'}</Text>
          <Text className="text-muted-foreground">{title}</Text>
        </View>
        <Text className="font-body-semibold">Track order</Text>
        <Icon as={ChevronRight} className="size-5" />
      </Pressable>
    </Link>
  );
}
