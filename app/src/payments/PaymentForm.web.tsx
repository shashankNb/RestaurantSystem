import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { loadStripe, type Appearance, type Stripe, type StripeElementsOptions } from '@stripe/stripe-js';
import { CircleAlert } from 'lucide-react-native';
import { useMemo, useState } from 'react';
import { View } from 'react-native';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { useColorScheme } from '@/hooks/use-color-scheme';
import { config } from '@/lib/config';
import { formatMoney } from '@/lib/money';
import { PaymentsNotSetUp } from '@/payments/payments-not-set-up';
import type { Scheme } from '@/theme/brand';
import { PALETTE } from '@/theme/palette';

import { PAYMENT_FAILED_MESSAGE, type PaymentFormComponent, type PaymentFormProps } from './types';

let stripePromise: Promise<Stripe | null> | null = null;

/** Stripe.js, loaded from Stripe on first use (never while rendering on the server). */
function getStripe(): Promise<Stripe | null> {
  stripePromise ??= loadStripe(config.stripePublishableKey);

  return stripePromise;
}

/**
 * The web: Stripe's Payment Element (cards, plus Apple Pay and Google Pay where the
 * browser offers them). The order is created only when "Place order" is tapped, then the
 * payment is confirmed against it (Stripe's deferred-intent flow).
 */
export const PaymentForm: PaymentFormComponent = (props) => {
  const scheme: Scheme = useColorScheme() === 'dark' ? 'dark' : 'light';

  const options = useMemo<StripeElementsOptions>(
    () => ({
      mode: 'payment',
      amount: props.amountCents,
      currency: props.currency.toLowerCase(),
      appearance: appearance(scheme),
      fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Mukta:wght@400;500;600&display=swap' }],
    }),
    [props.amountCents, props.currency, scheme],
  );

  if (!config.stripePublishableKey) {
    return <PaymentsNotSetUp />;
  }

  return (
    <Elements stripe={getStripe()} options={options}>
      <PaymentElementForm {...props} />
    </Elements>
  );
};

function PaymentElementForm({ amountCents, currency, disabled, validate, createPayment, onPaid, onError }: PaymentFormProps) {
  const stripe = useStripe();
  const elements = useElements();
  const [ready, setReady] = useState(false);
  const [loadError, setLoadError] = useState(false);
  const [busy, setBusy] = useState(false);

  const pay = async () => {
    if (stripe === null || elements === null || !validate()) {
      return;
    }

    setBusy(true);

    try {
      // First, while still handling the tap: checks the card details and opens Apple Pay
      // or Google Pay when chosen. The Payment Element shows anything to fix.
      const submitted = await elements.submit();

      if (submitted.error) {
        return;
      }

      const payment = await createPayment();

      if (payment === null) {
        return;
      }

      const { error } = await stripe.confirmPayment({
        elements,
        clientSecret: payment.clientSecret,
        confirmParams: {
          return_url: `${window.location.origin}${payment.returnPath}`,
          payment_method_data: { billing_details: payment.billing },
        },
        redirect: 'if_required',
      });

      if (error) {
        // Card and validation messages are written for customers; anything else isn't.
        onError(error.type === 'card_error' || error.type === 'validation_error' ? (error.message ?? PAYMENT_FAILED_MESSAGE) : PAYMENT_FAILED_MESSAGE);

        return;
      }

      onPaid();
    } finally {
      setBusy(false);
    }
  };

  if (loadError) {
    return (
      <Alert icon={CircleAlert} variant="destructive">
        <AlertDescription>We couldn’t load the payment form. Check your connection and reload the page.</AlertDescription>
      </Alert>
    );
  }

  return (
    <View className="gap-5">
      {ready ? null : <Skeleton className="h-40 w-full" />}
      <PaymentElement
        options={{
          layout: 'tabs',
          // Name, email and phone come from the checkout form; don't ask twice. Stripe's Link
          // would ask for the email again to save the card with Stripe, so it's off.
          fields: { billingDetails: { name: 'never', email: 'never', phone: 'never' } },
          wallets: { link: 'never' },
        }}
        onReady={() => setReady(true)}
        onLoadError={() => setLoadError(true)}
      />
      <Button size="lg" onPress={() => void pay()} disabled={disabled || busy || !ready}>
        <Text>{busy ? 'Placing order…' : `Place order · ${formatMoney(amountCents, currency)}`}</Text>
      </Button>
    </View>
  );
}

/**
 * Stripe's fields sit in an iframe, so they get the design's colours as values. As in the
 * app's own controls, the chosen payment method is filled with the text colour.
 */
function appearance(scheme: Scheme): Appearance {
  const palette = PALETTE[scheme];
  const focus = `0 0 0 1px ${palette.ink}`;

  return {
    theme: scheme === 'dark' ? 'night' : 'stripe',
    variables: {
      colorPrimary: palette.ink,
      colorBackground: palette.page,
      colorText: palette.ink,
      colorTextSecondary: palette.muted,
      colorTextPlaceholder: palette.muted,
      colorDanger: palette.destructive,
      fontFamily: 'Mukta, system-ui, sans-serif',
      fontSizeBase: '16px',
      borderRadius: '8px',
      focusBoxShadow: focus,
      focusOutline: 'none',
    },
    rules: {
      '.Input': { border: `1px solid ${palette.input}`, boxShadow: 'none' },
      '.Input:focus': { borderColor: palette.ink, boxShadow: focus },
      '.Input--invalid': { borderColor: palette.destructive, boxShadow: `0 0 0 1px ${palette.destructive}` },
      '.Tab': { border: `1px solid ${palette.border}`, boxShadow: 'none' },
      '.Tab--selected': { backgroundColor: palette.ink, borderColor: palette.ink, color: palette.page, boxShadow: 'none' },
      '.Tab--selected:focus': { boxShadow: `0 0 0 2px ${palette.page}, 0 0 0 4px ${palette.ink}` },
      '.TabIcon--selected': { fill: palette.page },
      '.TabLabel--selected': { color: palette.page },
      '.Label': { fontWeight: '500' },
    },
  };
}
