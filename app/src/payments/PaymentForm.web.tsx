import { Elements, ExpressCheckoutElement, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import {
  loadStripe,
  type Appearance,
  type Stripe,
  type StripeElementsOptions,
  type StripeExpressCheckoutElementConfirmEvent,
} from '@stripe/stripe-js';
import { useMemo, useState } from 'react';
import { View } from 'react-native';

import { CircleAlert } from '@/components/icons';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { useColorScheme } from '@/hooks/use-color-scheme';
import { formatMoney } from '@/lib/money';
import { OrDivider } from '@/payments/or-divider';
import { PaymentsNotSetUp } from '@/payments/payments-not-set-up';
import { usePublishableKey } from '@/payments/use-publishable-key';
import type { Scheme } from '@/theme/brand';
import { PALETTE } from '@/theme/palette';

import { PAYMENT_FAILED_MESSAGE, type PaymentFormComponent, type PaymentFormProps } from './types';

const stripePromises = new Map<string, Promise<Stripe | null>>();

/**
 * Stripe.js for the restaurant's own Stripe account, loaded from Stripe on first use (never
 * while rendering on the server).
 */
function getStripe(publishableKey: string): Promise<Stripe | null> {
  let promise = stripePromises.get(publishableKey);

  if (promise === undefined) {
    promise = loadStripe(publishableKey);
    stripePromises.set(publishableKey, promise);
  }

  return promise;
}

/**
 * The web. Where the browser has Apple Pay (Safari) or Google Pay (Chrome) ready, Stripe's
 * Express Checkout button comes first: one tap, no card details. Below it, the Payment
 * Element for cards. Either way the order is created only once the customer pays, then the
 * payment is confirmed against it (Stripe's deferred-intent flow).
 */
export const PaymentForm: PaymentFormComponent = (props) => {
  const scheme: Scheme = useColorScheme() === 'dark' ? 'dark' : 'light';
  const publishableKey = usePublishableKey();

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

  if (publishableKey === undefined) {
    return null;
  }

  if (publishableKey === null) {
    return <PaymentsNotSetUp />;
  }

  return (
    <Elements key={publishableKey} stripe={getStripe(publishableKey)} options={options}>
      <PaymentElementForm {...props} scheme={scheme} />
    </Elements>
  );
};

function PaymentElementForm({
  amountCents,
  currency,
  disabled,
  validate,
  createPayment,
  onPaid,
  onError,
  scheme,
}: PaymentFormProps & { scheme: Scheme }) {
  const stripe = useStripe();
  const elements = useElements();
  const [ready, setReady] = useState(false);
  const [loadError, setLoadError] = useState(false);
  const [busy, setBusy] = useState(false);
  const [walletShown, setWalletShown] = useState(false);

  // After the wallet sheet: create the order, then confirm the payment with the wallet.
  const payWithWallet = async (event: StripeExpressCheckoutElementConfirmEvent) => {
    if (stripe === null || elements === null) {
      event.paymentFailed({ reason: 'fail' });

      return;
    }

    setBusy(true);

    try {
      const submitted = await elements.submit();

      if (submitted.error) {
        event.paymentFailed({ reason: 'fail' });

        return;
      }

      const payment = await createPayment();

      if (payment === null) {
        event.paymentFailed({ reason: 'fail' });

        return;
      }

      const { error } = await stripe.confirmPayment({
        elements,
        clientSecret: payment.clientSecret,
        confirmParams: { return_url: `${window.location.origin}${payment.returnPath}` },
        redirect: 'if_required',
      });

      if (error) {
        onError(error.type === 'card_error' || error.type === 'validation_error' ? (error.message ?? PAYMENT_FAILED_MESSAGE) : PAYMENT_FAILED_MESSAGE);

        return;
      }

      onPaid();
    } finally {
      setBusy(false);
    }
  };

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
      <ExpressCheckoutElement
        options={{
          buttonType: { applePay: 'order', googlePay: 'order' },
          buttonTheme: { applePay: scheme === 'dark' ? 'white' : 'black', googlePay: scheme === 'dark' ? 'white' : 'black' },
          buttonHeight: 48,
          // Wallets only, wherever the browser can take them: Google Pay in Chrome and Edge even
          // before a card is saved (it helps add one), Apple Pay in Safari and, on computers, in
          // Chrome and Edge through a QR code for the customer's iPhone. Stripe still leaves them
          // out where they can't work (and on domains not registered with it, such as localhost).
          paymentMethods: { applePay: 'always', googlePay: 'always', link: 'never', paypal: 'never', amazonPay: 'never', klarna: 'never' },
          layout: { maxColumns: 2, maxRows: 1 },
        }}
        onReady={({ availablePaymentMethods }) => setWalletShown(availablePaymentMethods !== undefined && Object.values(availablePaymentMethods).some(Boolean))}
        // Opens the wallet only once the checkout form is complete (checked within the tap).
        onClick={(event) => {
          if (!disabled && !busy && validate()) {
            event.resolve();
          }
        }}
        onConfirm={(event) => void payWithWallet(event)}
      />
      {walletShown ? <OrDivider /> : null}
      {ready ? null : <Skeleton className="h-40 w-full" />}
      <PaymentElement
        options={{
          layout: 'tabs',
          // Name, email and phone come from the checkout form; don't ask twice. Stripe's Link
          // would ask for the email again to save the card with Stripe, so it's off. Apple Pay
          // and Google Pay are the buttons above, not a second time in here.
          fields: { billingDetails: { name: 'never', email: 'never', phone: 'never' } },
          wallets: { applePay: 'never', googlePay: 'never', link: 'never' },
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
