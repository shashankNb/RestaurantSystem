import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '@/lib/api/client';
import { dataOf, menuSchema, type Menu, type MenuItem } from '@/lib/api/schemas';
import { config } from '@/lib/config';

export const menuQueryKey = ['menu', config.restaurantSlug] as const;

/**
 * The restaurant's menu. Sold-out switches show up on the next fetch, so it's refreshed
 * when the app comes back to the foreground and on pull-to-refresh.
 */
export function useMenu() {
  return useQuery({
    queryKey: menuQueryKey,
    queryFn: ({ signal }) =>
      apiRequest(`/restaurants/${encodeURIComponent(config.restaurantSlug)}/menu`, {
        schema: dataOf(menuSchema),
        signal,
      }),
  });
}

export function findMenuItem(menu: Menu | undefined, id: number): MenuItem | undefined {
  for (const category of menu?.categories ?? []) {
    const item = category.items.find((candidate) => candidate.id === id);

    if (item) {
      return item;
    }
  }

  return undefined;
}
