import { router, useLocalSearchParams } from 'expo-router';
import type { Metadata } from 'expo-router/server';
import { useEffect } from 'react';

import { useCart } from '@/cart/cart-store';
import { PageHead } from '@/components/page-head';
import { Screen } from '@/components/screen';
import { EmptyState, ErrorState, LoadingState } from '@/components/states';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { errorMessage } from '@/lib/api/client';
import { useRestaurant } from '@/lib/api/restaurant';
import { privatePage, toMetadata } from '@/lib/page-meta';

const tablePage = (label: string) => privatePage(`Table ${label}`);

/** Web: the page's title in the server's HTML. A table's link stays out of search results. */
export function generateMetadata(_request: unknown, params: Record<string, string | string[]>): Metadata {
  return toMetadata(tablePage(String(params.label)));
}

export default function TableLinkPage() {
  const { label } = useLocalSearchParams<{ label: string }>();

  return (
    <>
      <PageHead page={tablePage(label)} />
      <TableLink />
    </>
  );
}

/**
 * Where a table's QR code leads (/table/12): chooses dine in at that table, then opens the
 * menu. A table the restaurant doesn't have, or dine in switched off, says so instead.
 */
function TableLink() {
  const { label } = useLocalSearchParams<{ label: string }>();
  const restaurant = useRestaurant();
  const dineIn = restaurant.data?.fulfilment.dine_in;
  const table = dineIn?.enabled ? dineIn.tables.find((candidate) => candidate.toLowerCase() === label.toLowerCase()) : undefined;

  useEffect(() => {
    if (table !== undefined) {
      const cart = useCart.getState();
      cart.setFulfilment('dine_in');
      cart.setTable(table);
      // Back to the menu already underneath (opened directly, the link has one), not a second copy.
      router.dismissTo('/');
    }
  }, [table]);

  if (restaurant.isPending || table !== undefined) {
    return (
      <Screen>
        <LoadingState label="Opening the menu" />
      </Screen>
    );
  }

  if (restaurant.isError) {
    return (
      <Screen>
        <ErrorState
          title="We couldn’t load the menu"
          message={errorMessage(restaurant.error)}
          onRetry={() => void restaurant.refetch()}
          retrying={restaurant.isFetching}
        />
      </Screen>
    );
  }

  return (
    <Screen>
      <EmptyState
        title={dineIn?.enabled ? `We can’t find table ${label}` : 'Ordering at the table is off right now'}
        message={
          dineIn?.enabled
            ? 'Choose your table from the menu instead, or ask a member of staff.'
            : 'You can still order for pickup, or ask a member of staff.'
        }
        action={
          <Button onPress={() => router.dismissTo('/')}>
            <Text>Go to the menu</Text>
          </Button>
        }
      />
    </Screen>
  );
}
