import { Link, usePathname, type Href } from 'expo-router';
import { Platform, Pressable, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { useKitchen } from '@/kitchen/kitchen-context';
import { useSignOut } from '@/lib/api/account';
import { useRestaurant } from '@/lib/api/restaurant';
import { useRealtimeStatus } from '@/lib/realtime';
import { cn } from '@/lib/utils';

/**
 * The kitchen's bar: the restaurant, Orders (with how many are new) and Settings, whether
 * online ordering is on, whether live updates are flowing, and the shift and sign-out.
 */
export function KitchenHeader({ newCount }: { newCount: number }) {
  const pathname = usePathname();
  const { data: restaurant } = useRestaurant();
  const realtime = useRealtimeStatus();
  const { started, endShift } = useKitchen();
  const signOut = useSignOut();
  const accepting = restaurant?.status.is_accepting_orders ?? true;

  return (
    <View className="border-border bg-background flex-row flex-wrap items-center gap-x-3 gap-y-2 border-b px-4 py-2">
      <Text variant="item" numberOfLines={1} className="max-w-64">
        {restaurant?.name ?? 'Kitchen'}
      </Text>
      <View className="flex-row gap-1" role="tablist" aria-label="Kitchen screens">
        <NavTab href="/staff/orders" label="Orders" badge={newCount} active={pathname.startsWith('/staff/orders')} />
        <NavTab href="/staff/settings" label="Settings" active={pathname.startsWith('/staff/settings')} />
      </View>
      <View className="flex-1" />
      <Pill tone={accepting ? 'good' : 'attention'} label={accepting ? 'Taking orders' : 'Orders paused'} />
      {realtime !== 'connected' ? <Pill tone="attention" label="Live updates off · checking every 10 s" /> : null}
      {started ? (
        <Button variant="outline" size="sm" onPress={endShift}>
          <Text>End shift</Text>
        </Button>
      ) : null}
      <Button
        variant="ghost"
        size="sm"
        onPress={() => {
          endShift();
          signOut.mutate();
        }}
        disabled={signOut.isPending}
      >
        <Text>{signOut.isPending ? 'Signing out…' : 'Sign out'}</Text>
      </Button>
    </View>
  );
}

function NavTab({ href, label, active, badge = 0 }: { href: Href; label: string; active: boolean; badge?: number }) {
  return (
    <Link href={href} replace asChild>
      <Pressable
        role="tab"
        aria-selected={active}
        aria-label={badge > 0 ? `${label}, ${badge} new` : label}
        className={cn(
          'min-h-11 flex-row items-center gap-2 rounded-md px-3',
          active ? 'bg-foreground' : 'active:bg-accent',
          Platform.select({ web: cn(!active && 'hover:bg-accent', 'focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-offset-2') }),
        )}
      >
        <Text className={cn('font-body-semibold', active ? 'text-background' : 'text-foreground')}>{label}</Text>
        {badge > 0 ? (
          <View className="bg-marigold min-w-6 items-center rounded-full px-1.5">
            <Text className="text-on-marigold font-body-bold text-sm">{badge}</Text>
          </View>
        ) : null}
      </Pressable>
    </Link>
  );
}

function Pill({ tone, label }: { tone: 'good' | 'attention'; label: string }) {
  return (
    <View className="border-border flex-row items-center gap-2 rounded-full border px-3 py-1.5">
      <View className={cn('size-2 rounded-full', tone === 'good' ? 'bg-coriander' : 'bg-marigold')} />
      <Text variant="small">{label}</Text>
    </View>
  );
}
