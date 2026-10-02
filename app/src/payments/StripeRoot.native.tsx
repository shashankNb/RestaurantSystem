import { StripeProvider, useStripe } from '@stripe/stripe-react-native';
import Constants from 'expo-constants';
import * as Linking from 'expo-linking';
import { useEffect, type PropsWithChildren } from 'react';

import { usePublishableKey } from '@/payments/use-publishable-key';

/** Set in app.config.ts from the brand's appleMerchantId; the same ID is in the build's entitlements. */
const merchantIdentifier = (Constants.expoConfig?.extra as { appleMerchantId?: string } | undefined)?.appleMerchantId;

/**
 * iOS and Android: Stripe's provider for PaymentSheet, set up with the restaurant's own
 * publishable key once it has loaded. Always rendered, so the app doesn't remount when the
 * key arrives; without a key Stripe stays idle and the checkout says payments aren't set up.
 */
export function StripeRoot({ children }: PropsWithChildren) {
  const publishableKey = usePublishableKey();

  return (
    <StripeProvider publishableKey={publishableKey ?? ''} merchantIdentifier={merchantIdentifier} urlScheme={Linking.createURL('')}>
      <>
        {publishableKey ? <StripeRedirects /> : null}
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
