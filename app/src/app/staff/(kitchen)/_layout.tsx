import { useQueryClient } from '@tanstack/react-query';
import { Redirect, Slot } from 'expo-router';
import { useEffect } from 'react';
import { BackHandler } from 'react-native';

import { useSession } from '@/auth/session';
import { KitchenHeader } from '@/components/kitchen/kitchen-header';
import { Screen } from '@/components/screen';
import { ErrorState, LoadingState } from '@/components/states';
import { KeepScreenOn } from '@/kitchen/keep-screen-on';
import { KitchenContext } from '@/kitchen/kitchen-context';
import { useShift } from '@/kitchen/shift';
import { useNewOrderAlert } from '@/kitchen/use-new-order-alert';
import { useMe } from '@/lib/api/account';
import { errorMessage } from '@/lib/api/client';
import { useRestaurant } from '@/lib/api/restaurant';
import { staffOrdersKey, useStaffOrders } from '@/lib/api/staff';
import { config } from '@/lib/config';
import { useOrderEvents } from '@/lib/realtime';

/**
 * The order board and settings, for this restaurant's staff and owners only; anyone else
 * goes to the kitchen sign-in. Alerts, live updates and keep-awake run here, so they carry
 * on while staff are on the settings screen.
 */
export default function KitchenLayout() {
  const status = useSession((state) => state.status);
  const me = useMe();

  if (status === 'restoring' || (status === 'signedIn' && me.isPending)) {
    return (
      <Screen>
        <LoadingState label="Checking your sign-in" />
      </Screen>
    );
  }

  if (status !== 'signedIn') {
    return <Redirect href="/staff/login" />;
  }

  if (me.isError) {
    return (
      <Screen>
        <ErrorState
          title="We couldn’t check your account"
          message={errorMessage(me.error)}
          onRetry={() => void me.refetch()}
          retrying={me.isFetching}
        />
      </Screen>
    );
  }

  // Signed in, but not staff here: the sign-in screen explains.
  if (!me.data?.restaurants.some((restaurant) => restaurant.slug === config.restaurantSlug)) {
    return <Redirect href="/staff/login" />;
  }

  return <Kitchen />;
}

function Kitchen() {
  const queryClient = useQueryClient();
  const { data: restaurant } = useRestaurant();
  const orders = useStaffOrders();
  const started = useShift((state) => state.started);
  const setStarted = useShift((state) => state.setStarted);
  const newOrderIds = (orders.data ?? []).filter((order) => order.status === 'placed').map((order) => order.public_id);
  const alert = useNewOrderAlert({ active: started, newOrderIds });

  // Android: during a shift the back button doesn't leave the board (End shift does).
  useEffect(() => {
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => started);

    return () => subscription.remove();
  }, [started]);

  // Each new or changed order arrives here within a second; the queue is then reloaded.
  useOrderEvents(
    restaurant ? `restaurant.${restaurant.id}.orders` : null,
    () => void queryClient.invalidateQueries({ queryKey: staffOrdersKey }),
    true,
  );

  const kitchen = {
    started,
    startShift: () => {
      // Inside the tap, so browsers allow sound from now on; also a sound check.
      alert.ring();
      setStarted(true);
    },
    endShift: () => setStarted(false),
  };

  return (
    <KitchenContext.Provider value={kitchen}>
      <Screen>
        <KitchenHeader newCount={newOrderIds.length} />
        <Slot />
      </Screen>
      {started ? <KeepScreenOn /> : null}
    </KitchenContext.Provider>
  );
}
