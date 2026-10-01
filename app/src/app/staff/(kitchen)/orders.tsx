import { Bell } from 'lucide-react-native';
import { useState } from 'react';
import { Platform, Pressable, RefreshControl, ScrollView, useWindowDimensions, View } from 'react-native';

import { OrderCard } from '@/components/kitchen/order-card';
import { ErrorState, LoadingState } from '@/components/states';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { useNow } from '@/hooks/use-now';
import { useKitchen } from '@/kitchen/kitchen-context';
import { errorMessage } from '@/lib/api/client';
import { useRestaurant } from '@/lib/api/restaurant';
import type { OrderStatus, StaffOrder } from '@/lib/api/schemas';
import { useStaffOrders } from '@/lib/api/staff';
import { cn } from '@/lib/utils';

type ColumnKey = 'new' | 'preparing' | 'ready' | 'out';

const COLUMNS: { key: ColumnKey; title: string; statuses: OrderStatus[]; empty: string }[] = [
  { key: 'new', title: 'New', statuses: ['placed'], empty: 'No new orders. They arrive here with an alert.' },
  { key: 'preparing', title: 'Preparing', statuses: ['accepted', 'preparing'], empty: 'Nothing being prepared.' },
  { key: 'ready', title: 'Ready', statuses: ['ready'], empty: 'Nothing waiting to go.' },
  { key: 'out', title: 'Out for delivery', statuses: ['out_for_delivery'], empty: 'No deliveries on the road.' },
];

/** Columns side by side from this width (a tablet in landscape); below it, one at a time. */
const WIDE = 900;

/**
 * The live order board: New, Preparing, Ready and Out for delivery. It opens on "Start
 * shift", because browsers only play sound after a tap, and that tap also keeps the screen on.
 */
export default function OrdersBoard() {
  const { started, startShift } = useKitchen();
  const orders = useStaffOrders();
  const { data: restaurant } = useRestaurant();
  const { width } = useWindowDimensions();
  const now = useNow();
  const [tab, setTab] = useState<ColumnKey>('new');
  const [refreshing, setRefreshing] = useState(false);

  const waiting = (orders.data ?? []).filter((order) => order.status === 'placed').length;

  if (!started) {
    return <StartShift waiting={waiting} onStart={startShift} />;
  }

  if (orders.isPending) {
    return <LoadingState label="Loading orders" />;
  }

  if (orders.isError) {
    return (
      <ErrorState
        title="We couldn’t load the orders"
        message={errorMessage(orders.error)}
        onRetry={() => void orders.refetch()}
        retrying={orders.isFetching}
      />
    );
  }

  const refresh = async () => {
    setRefreshing(true);
    await orders.refetch();
    setRefreshing(false);
  };

  const byColumn = group(orders.data);
  const columns = COLUMNS.filter((column) => column.key !== 'out' || restaurant?.fulfilment.delivery.enabled || byColumn.out.length > 0);
  const timeZone = restaurant?.timezone ?? 'Australia/Melbourne';
  const currency = restaurant?.currency ?? 'AUD';

  const column = (key: ColumnKey, showTitle: boolean) => {
    const definition = COLUMNS.find((candidate) => candidate.key === key)!;
    const list = byColumn[key];

    return (
      <View key={key} className={cn('bg-muted flex-1 rounded-lg', showTitle ? 'min-w-0' : '')}>
        {showTitle ? (
          <View className="flex-row items-baseline gap-2 px-3 pb-1 pt-3">
            <Text variant="heading">{definition.title}</Text>
            <Text className="text-muted-foreground font-body-semibold">{list.length}</Text>
          </View>
        ) : null}
        <ScrollView
          contentContainerClassName="gap-3 p-3"
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void refresh()} />}
        >
          {list.length === 0 ? (
            <Text className="text-muted-foreground px-1 py-2">{definition.empty}</Text>
          ) : (
            list.map((order) => <OrderCard key={order.public_id} order={order} now={now} timeZone={timeZone} currency={currency} />)
          )}
        </ScrollView>
      </View>
    );
  };

  if (width >= WIDE) {
    return <View className="flex-1 flex-row gap-3 p-3">{columns.map((definition) => column(definition.key, true))}</View>;
  }

  const current = columns.some((definition) => definition.key === tab) ? tab : 'new';

  return (
    <View className="flex-1 gap-3 p-3">
      <ScrollView horizontal showsHorizontalScrollIndicator={false} role="tablist" aria-label="Order columns" className="grow-0" contentContainerClassName="gap-2">
        {columns.map((definition) => {
          const selected = definition.key === current;
          const count = byColumn[definition.key].length;

          return (
            <Pressable
              key={definition.key}
              role="tab"
              aria-selected={selected}
              aria-label={`${definition.title}, ${count}`}
              onPress={() => setTab(definition.key)}
              className={cn(
                'min-h-11 flex-row items-center gap-2 rounded-full px-4',
                selected ? 'bg-foreground' : 'bg-muted active:bg-accent',
                Platform.select({ web: 'focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-offset-2' }),
              )}
            >
              <Text className={cn('font-body-semibold', selected ? 'text-background' : 'text-foreground')}>{definition.title}</Text>
              <Text className={cn('font-body-bold', selected ? 'text-background' : definition.key === 'new' && count > 0 ? 'text-brand-text' : 'text-muted-foreground')}>
                {count}
              </Text>
            </Pressable>
          );
        })}
      </ScrollView>
      {column(current, false)}
    </View>
  );
}

function StartShift({ waiting, onStart }: { waiting: number; onStart: () => void }) {
  return (
    <View className="flex-1 items-center justify-center gap-5 p-6">
      <Icon as={Bell} className="size-10" />
      <Text variant="heading" className="text-center">
        Start your shift
      </Text>
      <Text className="text-muted-foreground max-w-md text-center">
        Starting turns on the alert for new orders and keeps this screen on. Keep the volume up.
      </Text>
      {waiting > 0 ? (
        <Text className="font-body-semibold">{waiting === 1 ? '1 new order is waiting.' : `${waiting} new orders are waiting.`}</Text>
      ) : null}
      <Button size="lg" onPress={onStart}>
        <Text>Start shift</Text>
      </Button>
    </View>
  );
}

/** Orders by column, each in the order the kitchen needs them. */
function group(orders: StaffOrder[]): Record<ColumnKey, StaffOrder[]> {
  const time = (iso: string | null) => (iso === null ? Number.MAX_SAFE_INTEGER : new Date(iso).getTime());
  const by = (pick: (order: StaffOrder) => string | null) => (a: StaffOrder, b: StaffOrder) => time(pick(a)) - time(pick(b));
  const take = (statuses: OrderStatus[]) => orders.filter((order) => statuses.includes(order.status));

  return {
    // Oldest first: the next to be rejected automatically.
    new: take(['placed']).sort(by((order) => order.placed_at)),
    // Soonest due first.
    preparing: take(['accepted', 'preparing']).sort(by((order) => order.estimated_ready_at)),
    ready: take(['ready']).sort(by((order) => order.ready_at)),
    out: take(['out_for_delivery']).sort(by((order) => order.ready_at)),
  };
}
