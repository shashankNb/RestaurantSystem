import { createContext, use } from 'react';

import type { Menu, Restaurant } from '@/lib/api/schemas';

/** What a public page needs: the restaurant and its menu, as the API sent them at `fetchedAt`. */
export interface Storefront {
  restaurant: Restaurant;
  menu: Menu;
  /** When the API answered (ms since the epoch), so the app knows how fresh it is. */
  fetchedAt: number;
}

/**
 * What the public pages' loaders return. Always an object, because Expo Router fetches a
 * page's loader again when its data is empty. `storefront` is null when the server
 * couldn't reach the API; the page then loads in the browser as before.
 */
export interface StorefrontLoaderData {
  storefront: Storefront | null;
}

/** Set around a page the web server rendered (see src/lib/server-storefront.web.tsx). */
export const StorefrontContext = createContext<Storefront | null>(null);

/** What the server loaded for this page, if it rendered it; otherwise null. */
export function useServerStorefront(): Storefront | null {
  return use(StorefrontContext);
}
