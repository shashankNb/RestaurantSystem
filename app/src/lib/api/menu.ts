import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '@/lib/api/client';
import { dataOf, menuSchema, type Menu, type MenuItem } from '@/lib/api/schemas';
import { config } from '@/lib/config';
import { useServerStorefront } from '@/lib/storefront-context';

export const menuQueryKey = ['menu', config.restaurantSlug] as const;

export function fetchMenu(signal?: AbortSignal): Promise<Menu> {
  return apiRequest(`/restaurants/${encodeURIComponent(config.restaurantSlug)}/menu`, {
    schema: dataOf(menuSchema),
    signal,
  });
}

/**
 * The restaurant's menu. Sold-out switches show up on the next fetch, so it's refreshed
 * when the app comes back to the foreground and on pull-to-refresh. On a page the web
 * server rendered, it starts from what the server loaded.
 */
export function useMenu() {
  const storefront = useServerStorefront();

  return useQuery({
    queryKey: menuQueryKey,
    queryFn: ({ signal }) => fetchMenu(signal),
    initialData: storefront?.menu,
    initialDataUpdatedAt: storefront?.fetchedAt,
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
