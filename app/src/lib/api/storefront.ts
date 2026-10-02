import { fetchMenu } from '@/lib/api/menu';
import { fetchRestaurant } from '@/lib/api/restaurant';
import type { StorefrontLoaderData } from '@/lib/storefront-context';

/**
 * Loads the restaurant and its menu for a public page rendered on the web server (the
 * pages' `loader` and `generateMetadata`). When the API can't be reached the page still
 * renders, without the data, and loads it in the browser.
 */
export async function loadStorefront(): Promise<StorefrontLoaderData> {
  try {
    const [restaurant, menu] = await Promise.all([fetchRestaurant(), fetchMenu()]);

    return { storefront: { restaurant, menu, fetchedAt: Date.now() } };
  } catch (error) {
    console.error('Server rendering: couldn’t load the restaurant and menu.', error);

    return { storefront: null };
  }
}
