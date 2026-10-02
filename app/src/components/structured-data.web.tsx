import type { Menu, Restaurant } from '@/lib/api/schemas';
import { restaurantStructuredData, scriptJson } from '@/lib/structured-data';

/**
 * The restaurant and its menu as JSON-LD, in the page itself so it's in the server's HTML.
 * Search engines read it wherever it is on the page.
 */
export function RestaurantStructuredData({ restaurant, menu }: { restaurant: Restaurant; menu: Menu | undefined }) {
  return (
    <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: scriptJson(restaurantStructuredData(restaurant, menu)) }} />
  );
}
