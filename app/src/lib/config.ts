/**
 * Runtime settings, compiled in from EXPO_PUBLIC_* variables (see .env.example). Anyone
 * with the app can read them, so they never hold secrets.
 *
 * Expo only inlines a variable when it is read as `process.env.EXPO_PUBLIC_NAME`, so each
 * one is spelled out below rather than looked up dynamically.
 */

function required(name: string, value: string | undefined): string {
  if (!value) {
    throw new Error(`${name} is not set. Copy .env.example to .env, fill it in and restart Expo.`);
  }

  return value;
}

export const config = {
  /** The Laravel API, including /api/v1, without a trailing slash. */
  apiUrl: required('EXPO_PUBLIC_API_URL', process.env.EXPO_PUBLIC_API_URL).replace(/\/+$/, ''),
  /** The restaurant this build serves. */
  restaurantSlug: required('EXPO_PUBLIC_RESTAURANT_SLUG', process.env.EXPO_PUBLIC_RESTAURANT_SLUG),
  /** The public website, for canonical links (phase 7). */
  webUrl: process.env.EXPO_PUBLIC_WEB_URL ?? '',
  /** Laravel Reverb, for live order updates (phase 5). */
  reverb: {
    appKey: process.env.EXPO_PUBLIC_REVERB_APP_KEY ?? '',
    host: process.env.EXPO_PUBLIC_REVERB_HOST ?? 'localhost',
    port: Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 8080),
    scheme: process.env.EXPO_PUBLIC_REVERB_SCHEME ?? 'http',
  },
  /** Stripe publishable key, pk_test_… or pk_live_… (phase 5). */
  stripePublishableKey: process.env.EXPO_PUBLIC_STRIPE_PUBLISHABLE_KEY ?? '',
} as const;
