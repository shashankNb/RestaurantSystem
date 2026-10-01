import { StripeProvider, useStripe } from '@stripe/stripe-react-native';
import Constants from 'expo-constants';
import * as Linking from 'expo-linking';
import { useEffect, type PropsWithChildren } from 'react';

import { config } from '@/lib/config';

/** Set in app.config.ts from APP_APPLE_MERCHANT_ID; the same ID is in the build's entitlements. */
const merchantIdentifier = (Constants.expoConfig?.extra as { appleMerchantId?: string } | undefined)?.appleMerchantId;

/**
 * iOS and Android: Stripe's provider for PaymentSheet. Without a publishable key the app
 * still runs; the checkout says payments aren't set up.
 */
export function StripeRoot({ children }: PropsWithChildren) {
  if (!config.stripePublishableKey) {
    return children;
  }

  return (
    <StripeProvider
      publishableKey={config.stripePublishableKey}
      merchantIdentifier={merchantIdentifier}
      urlScheme={Linking.createURL('')}
    >
      <>
        <StripeRedirects />
        {children}
      </>
    </StripeProvider>
  );
}

/**
 * Passes links back into the app (after a bank's card check or a payment app) to Stripe,
 * so an open payment sheet can finish. src/app/+native-intent.ts keeps the router from
 * navigating to them.
 */
function StripeRedirects() {
  const { handleURLCallback } = useStripe();
  const url = Linking.useLinkingURL();

  useEffect(() => {
    if (url) {
      void handleURLCallback(url);
    }
  }, [url, handleURLCallback]);

  return null;
}
