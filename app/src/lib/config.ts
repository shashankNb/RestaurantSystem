import Constants from 'expo-constants';

/**
 * Runtime settings. Which restaurant the app is for comes from the build's brand
 * (brands/<BRAND>/brand.json, through app.config.ts); where the API and Reverb are comes
 * from EXPO_PUBLIC_* variables (see .env.example), the same for every restaurant. Anyone
 * with the app can read all of these, so they never hold secrets.
 *
 * Expo only inlines a variable when it is read as `process.env.EXPO_PUBLIC_NAME`, so each
 * one is spelled out below rather than looked up dynamically.
 */

interface Brand {
  /** The brand folder's name. */
  id: string;
  restaurantSlug: string;
  webUrl: string;
  brandColor: string;
}

/** The build's brand (see app.config.ts). */
export const brand = (Constants.expoConfig?.extra as { brand?: Brand } | undefined)?.brand;

function required(name: string, value: string | undefined, fix: string): string {
  if (!value) {
    throw new Error(`${name} is not set. ${fix}`);
  }

  return value;
}

export const config = {
  /** The Laravel API, including /api/v1, without a trailing slash. */
  apiUrl: required(
    'EXPO_PUBLIC_API_URL',
    process.env.EXPO_PUBLIC_API_URL,
    'Copy .env.example to .env, fill it in and restart Expo.',
  ).replace(/\/+$/, ''),
  /** The restaurant this build serves. */
  restaurantSlug: required(
    'The brand’s restaurantSlug',
    brand?.restaurantSlug,
    'Check brands/<BRAND>/brand.json, then restart Expo with --clear.',
  ),
  /** The restaurant's website, for canonical links, share links and the sitemap. */
  webUrl: brand?.webUrl ?? '',
  /** Laravel Reverb, for live order updates. */
  reverb: {
    appKey: process.env.EXPO_PUBLIC_REVERB_APP_KEY ?? '',
    host: process.env.EXPO_PUBLIC_REVERB_HOST ?? 'localhost',
    port: Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? 8080),
    scheme: process.env.EXPO_PUBLIC_REVERB_SCHEME ?? 'http',
  },
} as const;
