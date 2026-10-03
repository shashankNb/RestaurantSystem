import { useEffect, useRef, useState } from 'react';
import { View } from 'react-native';

import { CircleAlert } from '@/components/icons';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { useColorScheme } from '@/hooks/use-color-scheme';
import { paySquareOrder } from '@/lib/api/orders';
import { formatMoney } from '@/lib/money';
import { randomId } from '@/lib/random-id';
import { OrDivider } from '@/payments/or-divider';
import {
  addApplePayButtonStyles,
  loadSquare,
  type SquareCard,
  type SquareCardStyle,
  type SquareGooglePay,
  type SquarePaymentRequest,
  type SquarePayments,
  type SquareWallet,
} from '@/payments/square/web-payments-sdk';
import { PAYMENT_FAILED_MESSAGE, PROCESSOR_CHANGED_MESSAGE, paymentErrorMessage, type PaymentFormProps } from '@/payments/types';
import type { SquareSettings } from '@/payments/use-payment-settings';
import type { Scheme } from '@/theme/brand';
import { PALETTE } from '@/theme/palette';

interface Methods {
  card: SquareCard;
  applePay: SquareWallet | null;
  googlePay: SquareGooglePay | null;
  request: SquarePaymentRequest;
}

/**
 * The web, with the restaurant's own Square account (Square's Web Payments SDK). Where the
 * browser can take Apple Pay (Safari) or Google Pay, its button comes first; below, Square's
 * card form. A card is checked with the customer's bank (3-D Secure) as Square sees fit when
 * it's tokenised; then the order is created and the token charged. A wallet opens straight
 * from the tap, as Apple requires, and is charged the same way.
 */
export function SquarePaymentForm({
  settings,
  amountCents,
  currency,
  merchantName,
  disabled,
  validate,
  contact,
  createPayment,
  onPaid,
  onError,
}: PaymentFormProps & { settings: SquareSettings }) {
  const scheme: Scheme = useColorScheme() === 'dark' ? 'dark' : 'light';
  const cardContainer = useRef<View>(null);
  const googlePayContainer = useRef<View>(null);
  const [methods, setMethods] = useState<Methods | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [busy, setBusy] = useState(false);
  const amount = (amountCents / 100).toFixed(2);
  const code = currency.toUpperCase();
  // Read by the set-up below without starting it again for every new total.
  const total = useRef({ amount, label: merchantName });

  // Square's SDK and card form, then Apple Pay and Google Pay where the browser can take them.
  useEffect(() => {
    let current = true;
    const made: { destroy(): Promise<boolean> }[] = [];

    const setUp = async () => {
      const square = await loadSquare(settings.environment);
      const payments = square.payments(settings.applicationId, settings.locationId);
      const card = await makeCard(payments, scheme);
      made.push(card);

      if (!current || cardContainer.current === null) {
        return;
      }

      await card.attach(cardContainer.current as unknown as HTMLElement);

      const request = payments.paymentRequest({ countryCode: 'AU', currencyCode: code, total: total.current });
      const applePay = await payments.applePay(request).catch(() => null);
      const googlePay = await payments.googlePay(request).catch(() => null);

      if (applePay) {
        made.push(applePay);
        addApplePayButtonStyles();
      }

      if (googlePay) {
        made.push(googlePay);

        if (googlePayContainer.current !== null) {
          await googlePay.attach(googlePayContainer.current as unknown as HTMLElement, {
            buttonColor: scheme === 'dark' ? 'white' : 'black',
            buttonSizeMode: 'fill',
            buttonType: 'long',
          });
        }
      }

      if (current) {
        setMethods({ card, applePay, googlePay, request });
      }
    };

    setUp().catch(() => {
      if (current) {
        setLoadError(true);
      }
    });

    return () => {
      current = false;
      setMethods(null);

      for (const method of made) {
        void method.destroy().catch(() => undefined);
      }
    };
  }, [settings.environment, settings.applicationId, settings.locationId, code, scheme]);

  // Apple Pay's and Google Pay's sheets show the current total.
  useEffect(() => {
    total.current = { amount, label: merchantName };
    methods?.request.update({ total: total.current });
  }, [methods, amount, merchantName]);

  /** Creates the order, then charges the token. */
  const charge = async (token: string, verificationToken?: string) => {
    const payment = await createPayment();

    if (payment === null) {
      return;
    }

    if (payment.processor !== 'square') {
      onError(PROCESSOR_CHANGED_MESSAGE);

      return;
    }

    try {
      await paySquareOrder(
        payment.orderId,
        { tracking_token: payment.trackingToken, source_id: token, verification_token: verificationToken ?? null },
        randomId(),
      );
    } catch (error) {
      onError(paymentErrorMessage(error));

      return;
    }

    onPaid();
  };

  const payByCard = async () => {
    if (methods === null || !validate()) {
      return;
    }

    setBusy(true);

    try {
      const who = contact();
      const [givenName, ...familyName] = who.name.trim().split(/\s+/);
      const result = await methods.card.tokenize({
        amount,
        currencyCode: code,
        intent: 'CHARGE',
        billingContact: { givenName, familyName: familyName.join(' ') || undefined, email: who.email, phone: who.phone, countryCode: 'AU' },
        customerInitiated: true,
        sellerKeyedIn: false,
      });

      if (result.status === 'OK' && result.token) {
        await charge(result.token);
      } else if (result.status !== 'Invalid' && result.status !== 'Cancel' && result.status !== 'Abort') {
        // Square shows what to fix in the card form itself; anything else gets a message.
        onError(PAYMENT_FAILED_MESSAGE);
      }
    } catch {
      onError(PAYMENT_FAILED_MESSAGE);
    } finally {
      setBusy(false);
    }
  };

  const payWithWallet = async (wallet: SquareWallet) => {
    if (disabled || busy || !validate()) {
      return;
    }

    setBusy(true);

    try {
      // Straight from the tap: Apple Pay's sheet opens only from one.
      const result = await wallet.tokenize();

      if (result.status === 'OK' && result.token) {
        await charge(result.token);
      } else if (result.status !== 'Cancel' && result.status !== 'Abort') {
        onError(PAYMENT_FAILED_MESSAGE);
      }
    } catch {
      onError(PAYMENT_FAILED_MESSAGE);
    } finally {
      setBusy(false);
    }
  };

  // Square draws the Google Pay button; a tap on it starts the payment.
  const onGooglePay = useRef<() => void>(() => undefined);

  useEffect(() => {
    onGooglePay.current = () => {
      if (methods?.googlePay) {
        void payWithWallet(methods.googlePay);
      }
    };
  });

  useEffect(() => {
    const element = googlePayContainer.current as unknown as HTMLElement | null;

    if (!methods?.googlePay || element === null) {
      return;
    }

    const listener = () => onGooglePay.current();
    element.addEventListener('click', listener);

    return () => element.removeEventListener('click', listener);
  }, [methods]);

  if (loadError) {
    return (
      <Alert icon={CircleAlert} variant="destructive">
        <AlertDescription>We couldn’t load the payment form. Check your connection and reload the page.</AlertDescription>
      </Alert>
    );
  }

  const applePay = methods?.applePay ?? null;

  return (
    <View className="gap-5">
      {applePay ? (
        <button
          type="button"
          aria-label={`Apple Pay: place order, ${formatMoney(amountCents, currency)}`}
          className={`apple-pay-button ${scheme === 'dark' ? 'apple-pay-button-white' : 'apple-pay-button-black'}`}
          disabled={disabled || busy}
          onClick={() => void payWithWallet(applePay)}
        />
      ) : null}
      <View ref={googlePayContainer} className={methods?.googlePay ? 'h-12' : 'hidden'} />
      {applePay || methods?.googlePay ? <OrDivider /> : null}
      {methods ? null : <Skeleton className="h-24 w-full" />}
      <View ref={cardContainer} />
      <Button size="lg" onPress={() => void payByCard()} disabled={disabled || busy || methods === null}>
        <Text>{busy ? 'Placing order…' : `Place order · ${formatMoney(amountCents, currency)}`}</Text>
      </Button>
    </View>
  );
}

/** Square's card form in the design's colours, or Square's own look if it won't take them. */
async function makeCard(payments: SquarePayments, scheme: Scheme): Promise<SquareCard> {
  try {
    return await payments.card({ style: cardStyle(scheme) });
  } catch {
    return payments.card();
  }
}

function cardStyle(scheme: Scheme): SquareCardStyle {
  const palette = PALETTE[scheme];

  return {
    '.input-container': { borderColor: palette.input, borderRadius: '8px' },
    '.input-container.is-focus': { borderColor: palette.ink },
    '.input-container.is-error': { borderColor: palette.destructive },
    '.message-text': { color: palette.muted },
    '.message-icon': { color: palette.muted },
    '.message-text.is-error': { color: palette.destructive },
    '.message-icon.is-error': { color: palette.destructive },
    input: { backgroundColor: palette.page, color: palette.ink, fontSize: '16px' },
    'input::placeholder': { color: palette.muted },
    'input.is-error': { color: palette.destructive },
  };
}
