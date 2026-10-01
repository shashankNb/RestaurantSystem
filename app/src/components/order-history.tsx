import { Link } from 'expo-router';
import { ChevronRight } from 'lucide-react-native';
import { Platform, Pressable, View } from 'react-native';

import { ErrorState } from '@/components/states';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { errorMessage } from '@/lib/api/client';
import { useMyOrders } from '@/lib/api/orders';
import { useRestaurant } from '@/lib/api/restaurant';
import type { Order } from '@/lib/api/schemas';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import { STATUS_LABEL } from '@/orders/order-status';

/** The signed-in customer's orders, newest first, 15 at a time. Each opens its tracking screen. */
export function OrderHistory() {
  const orders = useMyOrders();
  const { data: restaurant } = useRestaurant();

  if (orders.isPending) {
    return (
      <View className="gap-3" role="progressbar" aria-label="Loading your orders">
        <Skeleton className="h-14 w-full" />
        <Skeleton className="h-14 w-full" />
      </View>
    );
  }

  if (orders.isError) {
    return (
      <ErrorState
        title="We couldn’t load your orders"
        message={errorMessage(orders.error)}
        onRetry={() => void orders.refetch()}
        retrying={orders.isFetching}
      />
    );
  }

  const all = orders.data.pages.flatMap((page) => page.data);

  if (all.length === 0) {
    return <Text className="text-muted-foreground">Orders you place while signed in will show here.</Text>;
  }

  return (
    <View>
      {all.map((order) => (
        <OrderRow key={order.public_id} order={order} timeZone={restaurant?.timezone ?? 'Australia/Melbourne'} />
      ))}
      {orders.hasNextPage ? (
        <Button
          variant="outline"
          className="mt-3 self-start"
          onPress={() => void orders.fetchNextPage()}
          disabled={orders.isFetchingNextPage}
        >
          <Text>{orders.isFetchingNextPage ? 'Loading…' : 'Show more orders'}</Text>
        </Button>
      ) : null}
    </View>
  );
}

function OrderRow({ order, timeZone }: { order: Order; timeZone: string }) {
  const at = order.placed_at ?? order.created_at;
  const date = at
    ? new Intl.DateTimeFormat('en-AU', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone })
        .format(new Date(at))
        .replace(',', '')
    : null;
  const count = order.items.reduce((sum, item) => sum + item.quantity, 0);

  return (
    <Link href={{ pathname: '/order/[publicId]', params: { publicId: order.public_id } }} asChild>
      <Pressable
        className={cn(
          'border-border flex-row items-center gap-3 border-b py-3 active:bg-accent',
          Platform.select({ web: 'hover:bg-accent focus-visible:outline-ring outline-none focus-visible:outline-2' }),
        )}
      >
        <View className="flex-1 gap-0.5">
          <Text className="font-body-semibold">
            {order.order_number ? `Order ${order.order_number}` : 'Order'}
            {date ? ` · ${date}` : ''}
          </Text>
          <Text variant="muted">
            {STATUS_LABEL[order.status]} · {count} {count === 1 ? 'item' : 'items'} · {formatMoney(order.total_cents)}
          </Text>
        </View>
        <Icon as={ChevronRight} className="text-muted-foreground size-5" />
      </Pressable>
    </Link>
  );
}
