import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '@/lib/api/client';
import { dataOf, restaurantSchema, type Restaurant } from '@/lib/api/schemas';
import { config } from '@/lib/config';
import { useServerStorefront } from '@/lib/storefront-context';

export const restaurantQueryKey = ['restaurant', config.restaurantSlug] as const;

export function fetchRestaurant(signal?: AbortSignal): Promise<Restaurant> {
  return apiRequest(`/restaurants/${encodeURIComponent(config.restaurantSlug)}`, {
    schema: dataOf(restaurantSchema),
    signal,
  });
}

/**
 * The restaurant this build serves: name, branding, open/closed status and fulfilment
 * options. Loaded at start-up; the status is refreshed every minute while the app is open.
 * On a page the web server rendered, it starts from what the server loaded.
 */
export function useRestaurant() {
  const storefront = useServerStorefront();

  return useQuery({
    queryKey: restaurantQueryKey,
    queryFn: ({ signal }) => fetchRestaurant(signal),
    refetchInterval: 60_000,
    initialData: storefront?.restaurant,
    initialDataUpdatedAt: storefront?.fetchedAt,
  });
}
