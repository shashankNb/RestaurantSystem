import { PaymentsNotSetUp } from '@/payments/payments-not-set-up';
import { SquarePaymentForm } from '@/payments/square/SquarePaymentForm.web';
import { StripePaymentForm } from '@/payments/stripe/StripePaymentForm.web';
import { usePaymentSettings } from '@/payments/use-payment-settings';

import type { PaymentFormComponent } from './types';

/**
 * The web: the payment form of the restaurant's processor, Stripe or Square (chosen in the
 * back office), or a note that it isn't taking payments online yet.
 */
export const PaymentForm: PaymentFormComponent = (props) => {
  const settings = usePaymentSettings();

  if (settings === undefined) {
    return null;
  }

  if (settings === null) {
    return <PaymentsNotSetUp />;
  }

  return settings.processor === 'square' ? (
    <SquarePaymentForm key={`${settings.environment}:${settings.applicationId}:${settings.locationId}`} {...props} settings={settings} />
  ) : (
    <StripePaymentForm {...props} publishableKey={settings.publishableKey} />
  );
};
