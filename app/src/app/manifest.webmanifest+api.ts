import Constants from 'expo-constants';

import { fetchRestaurant } from '@/lib/api/restaurant';
import type { Restaurant } from '@/lib/api/schemas';
import { SITE_NAME } from '@/lib/page-meta';
import { webIcon } from '@/lib/web-icons';
import { brandColors, DEFAULT_BRAND_COLOR, PAGE } from '@/theme/brand';

/**
 * The web app manifest, so the site can be added to a phone's home screen and opens like
 * an app. Name, description and colour follow the restaurant's settings; the icons are its
 * brand's (public/brands/<brand>/).
 */
export async function GET(): Promise<Response> {
  let restaurant: Restaurant | null = null;

  try {
    restaurant = await fetchRestaurant();
  } catch {
    // The build's name and the default colour will do.
  }

  const name = restaurant?.name ?? SITE_NAME;
  const manifest = {
    id: '/',
    name,
    short_name: Constants.expoConfig?.web?.shortName ?? name,
    description: restaurant?.description ?? undefined,
    lang: 'en-AU',
    dir: 'ltr',
    start_url: '/',
    scope: '/',
    display: 'standalone',
    background_color: PAGE.light,
    theme_color: brandColors(restaurant?.brand_color ?? DEFAULT_BRAND_COLOR, 'light').primary,
    categories: ['food', 'shopping'],
    icons: [
      { src: webIcon('icon-192.png'), sizes: '192x192', type: 'image/png', purpose: 'any' },
      { src: webIcon('icon-512.png'), sizes: '512x512', type: 'image/png', purpose: 'any' },
      { src: webIcon('icon-maskable-512.png'), sizes: '512x512', type: 'image/png', purpose: 'maskable' },
    ],
  };

  return new Response(JSON.stringify(manifest), {
    headers: { 'Content-Type': 'application/manifest+json', 'Cache-Control': 'public, max-age=3600' },
  });
}
