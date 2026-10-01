import type { PropsWithChildren } from 'react';

/**
 * The web: nothing to set up here. PaymentForm.web.tsx loads Stripe.js itself, on the
 * checkout screen only. StripeRoot.native.tsx wraps the app in Stripe's provider.
 */
export function StripeRoot({ children }: PropsWithChildren) {
  return children;
}
