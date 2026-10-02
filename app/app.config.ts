import type { ConfigContext, ExpoConfig } from "expo/config";

/**
 * Each restaurant ships its own branded app and website from this codebase. Its brand
 * folder, brands/<BRAND>/, holds its identity (name, store identifiers, deep-link scheme,
 * colour, website) in brand.json, and its icons; the website's icons are in
 * public/brands/<BRAND>/. BRAND defaults to the demo restaurant. Settings that depend on
 * where the app runs, such as the API's address, are EXPO_PUBLIC_* variables, read in
 * src/lib/config.ts. See .env.example and docs/ADDING_A_RESTAURANT.md.
 */
interface Brand {
  /** The app's name, under its icon and in the stores. */
  name: string;
  /** Under the icon on a phone's home screen: 12 characters at most. */
  shortName: string;
  /** The restaurant's link name in the back office: which restaurant the app shows. */
  restaurantSlug: string;
  /** The app's slug on expo.dev. */
  appSlug: string;
  /** The deep-link scheme, e.g. himalayanmomohouse:// */
  scheme: string;
  /** The iOS bundle identifier and Android package name. */
  bundleId: string;
  /** Apple Pay: the merchant ID registered with Apple and in the restaurant's Stripe account. */
  appleMerchantId: string;
  /** For the Android icon's background and the notification icon. */
  brandColor: string;
  /** The restaurant's website, for canonical links, share links and the sitemap. */
  webUrl: string;
  /** The EAS project (`eas init` prints it): builds, hosting and push notifications. */
  easProjectId?: string;
}

const brandId = process.env.BRAND ?? "himalayan-momo-house";
const brand = require(`./brands/${brandId}/brand.json`) as Brand;
const asset = (file: string) => `./brands/${brandId}/${file}`;
// Android push: the path to Firebase's google-services.json for this app (git-ignored). On
// EAS, a file variable of the same name.
const googleServicesFile = process.env.GOOGLE_SERVICES_JSON;

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: brand.name,
  slug: brand.appSlug,
  scheme: brand.scheme,
  version: "1.0.0",
  // Phones are used in portrait, kitchen tablets in landscape.
  orientation: "default",
  icon: asset("icon.png"),
  userInterfaceStyle: "automatic",
  ios: {
    bundleIdentifier: brand.bundleId,
    supportsTablet: true,
  },
  android: {
    package: brand.bundleId,
    googleServicesFile,
    adaptiveIcon: {
      backgroundColor: brand.brandColor,
      foregroundImage: asset("android-icon-foreground.png"),
      backgroundImage: asset("android-icon-background.png"),
      monochromeImage: asset("android-icon-monochrome.png"),
    },
    predictiveBackGestureEnabled: false,
  },
  web: {
    // Pages are rendered on the server for each request (EAS Hosting runs it), so the
    // menu that search engines and link previews see is always the current one.
    output: "server",
    favicon: asset("favicon.png"),
    shortName: brand.shortName,
    lang: "en-AU",
  },
  plugins: [
    // Both flags are experimental in SDK 57 (stable from SDK 58). See docs/DECISIONS.md.
    [
      "expo-router",
      {
        unstable_useServerDataLoaders: true,
        unstable_useServerRendering: true,
      },
    ],
    [
      "expo-splash-screen",
      {
        backgroundColor: "#FCFBF8",
        dark: { backgroundColor: "#1A1411" },
        image: asset("splash-icon.png"),
        imageWidth: 96,
      },
    ],
    [
      "@stripe/stripe-react-native",
      { merchantIdentifier: brand.appleMerchantId, enableGooglePay: true },
    ],
    // Order updates. Android tints the notification icon with the brand colour.
    ["expo-notifications", { color: brand.brandColor }],
    // The kitchen's new-order alert: playback only, while the app is open. No microphone.
    [
      "expo-audio",
      {
        microphonePermission: false,
        recordAudioAndroid: false,
        enableBackgroundPlayback: false,
      },
    ],
  ],
  experiments: {
    typedRoutes: true,
    reactCompiler: true,
  },
  extra: {
    // For the app at run time (src/lib/config.ts): which restaurant, its website and colour,
    // and where its web icons are (public/brands/<id>/).
    brand: {
      id: brandId,
      restaurantSlug: brand.restaurantSlug,
      webUrl: brand.webUrl,
      brandColor: brand.brandColor,
    },
    appleMerchantId: brand.appleMerchantId,
    // Builds, hosting and push notifications need the EAS project (see docs/SETUP.md).
    ...(brand.easProjectId ? { eas: { projectId: brand.easProjectId } } : {}),
  },
});
