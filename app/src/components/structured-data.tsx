import type { Menu, Restaurant } from '@/lib/api/schemas';

/** Structured data is for search engines, on the web (see structured-data.web.tsx). */
export function RestaurantStructuredData(_props: { restaurant: Restaurant; menu: Menu | undefined }) {
  return null;
}
