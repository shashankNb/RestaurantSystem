import { useRestaurant } from '@/lib/api/restaurant';

/**
 * The restaurant's own Stripe publishable key, from its settings in the back office:
 * undefined while the restaurant loads, null while its Stripe keys aren't all in (the
 * checkout then says payments aren't set up).
 */
export function usePublishableKey(): string | null | undefined {
  const { data: restaurant } = useRestaurant();

  return restaurant === undefined ? undefined : (restaurant.payments?.stripe_publishable_key ?? null);
}
