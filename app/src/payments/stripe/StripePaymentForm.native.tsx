import {
  AddressCollectionMode,
  CollectionMode,
  PaymentSheetError,
  PlatformPay,
  PlatformPayButton,
  PlatformPayError,
  usePlatformPay,
  useStripe,
  type AppearanceParams,
} from '@stripe/stripe-react-native';
import * as Linking from 'expo-linking';
import { useEffect, useState } from 'react';
import { Platform, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { useRestaurant } from '@/lib/api/restaurant';
import { formatMoney } from '@/lib/money';
import { OrDivider } from '@/payments/or-divider';
import { PAYMENT_FAILED_MESSAGE, PROCESSOR_CHANGED_MESSAGE, type PaymentFormProps } from '@/payments/types';
import { brandColors, DEFAULT_BRAND_COLOR, type Scheme } from '@/theme/brand';
import { PALETTE } from '@/theme/palette';

/**
 * iOS and Android, with the restaurant's own Stripe account. Where the phone has Apple Pay or
 * Google Pay set up, its button comes first: one tap, Face ID or a fingerprint, no card
 * details. "Place order" below it opens Stripe's PaymentSheet for a card (it offers the
 * wallet too). Both create the order first.
 */
export function StripePaymentForm({
  publishableKey,
  amountCents,
  currency,
  merchantName,
  disabled,
  validate,
  createPayment,
  onPaid,
  onError,
}: PaymentFormProps & { publishableKey: string }) {
  const { initPaymentSheet, presentPaymentSheet } = useStripe();
  const { isPlatformPaySupported, confirmPlatformPayPayment } = usePlatformPay();
  const { data: restaurant } = useRestaurant();
  // Test keys take test payments in any build, store builds included: Google Pay then uses
  // Google's test environment, as Stripe requires.
  const testMode = publishableKey.startsWith('pk_test_');
  const [busy, setBusy] = useState(false);
  const [walletReady, setWalletReady] = useState(false);

  // Apple Pay needs a card in Wallet (and the Merchant ID in the build); Google Pay, a card
  // in Google Wallet.
  useEffect(() => {
    let current = true;

    isPlatformPaySupported({ googlePay: { testEnv: testMode } })
      .then((supported) => {
        if (current) {
          setWalletReady(supported);
        }
      })
      .catch(() => undefined);

    return () => {
      current = false;
    };
  }, [isPlatformPaySupported, publishableKey, testMode]);

  const payWithWallet = async () => {
    if (!validate()) {
      return;
    }

    setBusy(true);

    try {
      const payment = await createPayment();

      if (payment === null) {
        return;
      }

      if (payment.processor !== 'stripe') {
        onError(PROCESSOR_CHANGED_MESSAGE);

        return;
      }

      const code = currency.toUpperCase();
      const { error } = await confirmPlatformPayPayment(payment.clientSecret, {
        applePay: {
          cartItems: [{ label: merchantName, amount: (amountCents / 100).toFixed(2), paymentType: PlatformPay.PaymentType.Immediate }],
          merchantCountryCode: 'AU',
          currencyCode: code,
        },
        googlePay: { testEnv: testMode, merchantName, merchantCountryCode: 'AU', currencyCode: code },
      });

      if (error) {
        // Closing the sheet isn't a failure: the order waits for another try.
        if (error.code !== PlatformPayError.Canceled) {
          onError(error.localizedMessage ?? error.message);
        }

        return;
      }

      onPaid();
    } finally {
      setBusy(false);
    }
  };

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

      if (payment.processor !== 'stripe') {
        onError(PROCESSOR_CHANGED_MESSAGE);

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
        googlePay: { merchantCountryCode: 'AU', currencyCode: currency.toUpperCase(), testEnv: testMode },
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
    <View className="gap-4">
      {walletReady ? (
        <>
          <PlatformPayButton
            type={PlatformPay.ButtonType.Order}
            appearance={PlatformPay.ButtonStyle.Automatic}
            borderRadius={8}
            disabled={disabled || busy}
            onPress={() => void payWithWallet()}
            accessibilityLabel={`${Platform.OS === 'ios' ? 'Apple Pay' : 'Google Pay'}: place order, ${formatMoney(amountCents, currency)}`}
            style={{ height: 52 }}
          />
          <OrDivider />
        </>
      ) : null}
      <Button size="lg" onPress={() => void pay()} disabled={disabled || busy}>
        <Text>{busy ? 'Placing order…' : `Place order · ${formatMoney(amountCents, currency)}`}</Text>
      </Button>
    </View>
  );
}

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
