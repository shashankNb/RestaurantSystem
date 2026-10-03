import { useRestaurant } from '@/lib/api/restaurant';

export interface StripeSettings {
  processor: 'stripe';
  publishableKey: string;
}

export interface SquareSettings {
  processor: 'square';
  /** The platform's Square application. */
  applicationId: string;
  /** The restaurant's Square location, where payments go. */
  locationId: string;
  environment: 'sandbox' | 'production';
}

export type PaymentSettings = StripeSettings | SquareSettings;

/**
 * How the restaurant takes payments, from its settings in the back office: with its own
 * Stripe account or its own Square account. Undefined while the restaurant loads; null while
 * it can't take payments (the checkout then says payments aren't set up).
 */
export function usePaymentSettings(): PaymentSettings | null | undefined {
  const { data: restaurant } = useRestaurant();

  if (restaurant === undefined) {
    return undefined;
  }

  const payments = restaurant.payments;

  if (payments?.processor === 'square') {
    return payments.square
      ? {
          processor: 'square',
          applicationId: payments.square.application_id,
          locationId: payments.square.location_id,
          environment: payments.square.environment,
        }
      : null;
  }

  return payments?.stripe_publishable_key ? { processor: 'stripe', publishableKey: payments.stripe_publishable_key } : null;
}

/** The restaurant's own Stripe publishable key, while it takes payments with Stripe. */
export function usePublishableKey(): string | null | undefined {
  const settings = usePaymentSettings();

  return settings === undefined ? undefined : settings?.processor === 'stripe' ? settings.publishableKey : null;
}
