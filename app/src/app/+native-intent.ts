/**
 * Links that open the app (iOS and Android). Stripe's return links belong to the open
 * payment sheet (see src/payments/StripeRoot.native.tsx), so the router stays where it is.
 */
export function redirectSystemPath({ path, initial }: { path: string; initial: boolean }): string | null {
  if (path.includes('stripe-redirect')) {
    return initial ? '/' : null;
  }

  return path;
}
