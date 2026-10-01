import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '@/lib/api/client';
import { dataOf, restaurantSchema } from '@/lib/api/schemas';
import { config } from '@/lib/config';

export const restaurantQueryKey = ['restaurant', config.restaurantSlug] as const;

/**
 * The restaurant this build serves: name, branding, open/closed status and fulfilment
 * options. Loaded at start-up; the status is refreshed every minute while the app is open.
 */
export function useRestaurant() {
  return useQuery({
    queryKey: restaurantQueryKey,
    queryFn: ({ signal }) =>
      apiRequest(`/restaurants/${encodeURIComponent(config.restaurantSlug)}`, {
        schema: dataOf(restaurantSchema),
        signal,
      }),
    refetchInterval: 60_000,
  });
}
