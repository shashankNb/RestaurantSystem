import { AddressCollectionMode, CollectionMode, PaymentSheetError, useStripe, type AppearanceParams } from '@stripe/stripe-react-native';
import * as Linking from 'expo-linking';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { useRestaurant } from '@/lib/api/restaurant';
import { config } from '@/lib/config';
import { formatMoney } from '@/lib/money';
import { PaymentsNotSetUp } from '@/payments/payments-not-set-up';
import { brandColors, DEFAULT_BRAND_COLOR, type Scheme } from '@/theme/brand';
import { PALETTE } from '@/theme/palette';

import { PAYMENT_FAILED_MESSAGE, type PaymentFormComponent } from './types';

/**
 * iOS and Android: Stripe's PaymentSheet, with cards, Apple Pay and Google Pay. "Place
 * order" creates the order, then the sheet opens to pay for it.
 */
export const PaymentForm: PaymentFormComponent = ({
  amountCents,
  currency,
  merchantName,
  disabled,
  validate,
  createPayment,
  onPaid,
  onError,
}) => {
  const { initPaymentSheet, presentPaymentSheet } = useStripe();
  const { data: restaurant } = useRestaurant();
  const [busy, setBusy] = useState(false);

  if (!config.stripePublishableKey) {
    return <PaymentsNotSetUp />;
  }

  const pay = async () => {
    if (!validate()) {
      return;
    }

    setBusy(true);

    try {
      const payment = await createPayment();

      if (payment === null) {
        return;
      }

      const init = await initPaymentSheet({
        merchantDisplayName: merchantName,
        paymentIntentClientSecret: payment.clientSecret,
        // Brings the customer back here after a bank's card check or a payment app.
        returnURL: Linking.createURL('stripe-redirect'),
        defaultBillingDetails: payment.billing,
        // Name, email and phone come from the checkout form; don't ask twice.
        billingDetailsCollectionConfiguration: {
          name: CollectionMode.NEVER,
          email: CollectionMode.NEVER,
          phone: CollectionMode.NEVER,
          address: AddressCollectionMode.AUTOMATIC,
          attachDefaultsToPaymentMethod: true,
        },
        applePay: { merchantCountryCode: 'AU' },
        googlePay: { merchantCountryCode: 'AU', currencyCode: currency.toUpperCase(), testEnv: __DEV__ },
        allowsDelayedPaymentMethods: false,
        appearance: appearance(restaurant?.brand_color ?? DEFAULT_BRAND_COLOR),
      });

      if (init.error) {
        onError(PAYMENT_FAILED_MESSAGE);

        return;
      }

      const result = await presentPaymentSheet();

      if (result.error) {
        // Closing the sheet isn't a failure: the order waits, and "Place order" opens it again.
        if (result.error.code !== PaymentSheetError.Canceled) {
          onError(result.error.localizedMessage ?? result.error.message);
        }

        return;
      }

      onPaid();
    } finally {
      setBusy(false);
    }
  };

  return (
    <Button size="lg" onPress={() => void pay()} disabled={disabled || busy}>
      <Text>{busy ? 'Placing order…' : `Place order · ${formatMoney(amountCents, currency)}`}</Text>
    </Button>
  );
};

/** The sheet in the design's colours: flat, 8 px corners, the brand colour on "Pay". */
function appearance(brandColor: string): AppearanceParams {
  const colors = (scheme: Scheme) => {
    const palette = PALETTE[scheme];

    return {
      primary: palette.ink,
      background: palette.page,
      componentBackground: palette.page,
      componentBorder: palette.input,
      componentDivider: palette.border,
      primaryText: palette.ink,
      secondaryText: palette.muted,
      componentText: palette.ink,
      placeholderText: palette.muted,
      icon: palette.muted,
      error: palette.destructive,
    };
  };
  const button = (scheme: Scheme) => {
    const brand = brandColors(brandColor, scheme);

    return { background: brand.primary, text: brand.primaryForeground, border: brand.primary };
  };
  const flat = { color: '#000000', opacity: 0, offset: { x: 0, y: 0 }, blurRadius: 0 };

  return {
    colors: { light: colors('light'), dark: colors('dark') },
    shapes: { borderRadius: 8, borderWidth: 1, shadow: flat },
    primaryButton: {
      colors: { light: button('light'), dark: button('dark') },
      shapes: { borderRadius: 8, shadow: flat, height: 52 },
    },
  };
}
