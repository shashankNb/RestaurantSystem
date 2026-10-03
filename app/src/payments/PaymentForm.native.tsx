import { PaymentsNotSetUp } from '@/payments/payments-not-set-up';
import { SquarePaymentForm } from '@/payments/square/SquarePaymentForm.native';
import { StripePaymentForm } from '@/payments/stripe/StripePaymentForm.native';
import { usePaymentSettings } from '@/payments/use-payment-settings';

import type { PaymentFormComponent } from './types';

/**
 * iOS and Android: the payment form of the restaurant's processor, Stripe or Square (chosen
 * in the back office), or a note that it isn't taking payments online yet.
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
    <SquarePaymentForm {...props} settings={settings} />
  ) : (
    <StripePaymentForm {...props} publishableKey={settings.publishableKey} />
  );
};
