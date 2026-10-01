import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * Each restaurant ships its own branded build from this codebase. The app's identity
 * (name, store identifiers, deep-link scheme) comes from these build-time variables and
 * defaults to the demo restaurant. Runtime settings such as the API URL are
 * EXPO_PUBLIC_* variables, read in src/lib/config.ts. See .env.example.
 */
const name = process.env.APP_NAME ?? 'Himalayan Momo House';
const slug = process.env.APP_SLUG ?? 'himalayan-momo-house';
const scheme = process.env.APP_SCHEME ?? 'himalayanmomohouse';
const bundleIdentifier = process.env.APP_BUNDLE_ID ?? 'au.com.examplerestaurant.ordering';
const easProjectId = process.env.EAS_PROJECT_ID;
// Apple Pay: the Merchant ID registered in the Apple Developer account and in Stripe.
const appleMerchantId = process.env.APP_APPLE_MERCHANT_ID ?? `merchant.${bundleIdentifier}`;
// Android push: the path to Firebase's google-services.json (git-ignored). On EAS, a file
// variable of the same name.
const googleServicesFile = process.env.GOOGLE_SERVICES_JSON;

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name,
  slug,
  scheme,
  version: '1.0.0',
  // Phones are used in portrait, kitchen tablets in landscape.
  orientation: 'default',
  icon: './assets/images/icon.png',
  userInterfaceStyle: 'automatic',
  ios: {
    bundleIdentifier,
    supportsTablet: true,
  },
  android: {
    package: bundleIdentifier,
    googleServicesFile,
    adaptiveIcon: {
      backgroundColor: '#E6F4FE',
      foregroundImage: './assets/images/android-icon-foreground.png',
      backgroundImage: './assets/images/android-icon-background.png',
      monochromeImage: './assets/images/android-icon-monochrome.png',
    },
    predictiveBackGestureEnabled: false,
  },
  web: {
    output: 'static',
    favicon: './assets/images/favicon.png',
  },
  plugins: [
    'expo-router',
    [
      'expo-splash-screen',
      {
        backgroundColor: '#FCFBF8',
        dark: { backgroundColor: '#1A1411' },
        image: './assets/images/splash-icon.png',
        imageWidth: 76,
      },
    ],
    ['@stripe/stripe-react-native', { merchantIdentifier: appleMerchantId, enableGooglePay: true }],
    // Order updates. Android tints the notification icon with the brand colour.
    ['expo-notifications', { color: '#7A1F2B' }],
    // The kitchen's new-order alert: playback only, while the app is open. No microphone.
    ['expo-audio', { microphonePermission: false, recordAudioAndroid: false, enableBackgroundPlayback: false }],
  ],
  experiments: {
    typedRoutes: true,
    reactCompiler: true,
  },
  extra: {
    appleMerchantId,
    // Push notifications need the EAS project (see docs/SETUP.md).
    ...(easProjectId ? { eas: { projectId: easProjectId } } : {}),
  },
});
