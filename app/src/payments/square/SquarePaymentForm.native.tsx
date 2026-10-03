import { PlatformPay, PlatformPayButton } from '@stripe/stripe-react-native';
import Constants from 'expo-constants';
import { useEffect, useState } from 'react';
import { Platform, View } from 'react-native';
import {
  ApplePayNonceSuccessState,
  GooglePayEnvironment,
  GooglePayPriceStatus,
  PaymentType,
  SQIPApplePay,
  SQIPCardEntry,
  SQIPCore,
  SQIPGooglePay,
  type ApplePayNonceSuccessResult,
} from 'react-native-square-in-app-payments';

import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { paySquareOrder } from '@/lib/api/orders';
import { formatMoney } from '@/lib/money';
import { randomId } from '@/lib/random-id';
import { OrDivider } from '@/payments/or-divider';
import { PAYMENT_FAILED_MESSAGE, PROCESSOR_CHANGED_MESSAGE, paymentErrorMessage, type PaymentFormProps } from '@/payments/types';
import type { SquareSettings } from '@/payments/use-payment-settings';

/**
 * Apple Pay with Square: the brand's second merchant ID (app.config.ts, from its
 * squareAppleMerchantId), with its certificate in the restaurant's Square application. It's
 * in the build's entitlements, next to the Stripe one.
 */
const squareMerchantId = (Constants.expoConfig?.extra as { squareAppleMerchantId?: string | null } | undefined)?.squareAppleMerchantId ?? null;

/** Paid, or the message to show; null when the checkout already says what's wrong. */
type Outcome = { paid: true } | { paid: false; message: string | null };

/**
 * iOS and Android, with the restaurant's own Square account (Square's In-App Payments SDK).
 * Where the phone has Apple Pay or Google Pay ready, its button comes first. "Place order"
 * opens Square's card form, which checks the card with the customer's bank (3-D Secure) as
 * Square sees fit. Either way the order is created once the customer has paid with the
 * wallet or card, then the token is charged.
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
  const [busy, setBusy] = useState(false);
  const [wallet, setWallet] = useState<'apple' | 'google' | null>(null);
  const price = (amountCents / 100).toFixed(2);
  const code = currency.toUpperCase();

  // Square's SDK for the restaurant's account, and whether the phone has a wallet ready.
  useEffect(() => {
    let current = true;

    const available = async (): Promise<'apple' | 'google' | null> => {
      SQIPCore.setSquareApplicationId(settings.applicationId);

      if (Platform.OS === 'ios' && squareMerchantId !== null) {
        SQIPApplePay.initializeApplePay(squareMerchantId);

        return (await SQIPApplePay.canUseApplePay()) ? 'apple' : null;
      }

      if (Platform.OS === 'android') {
        SQIPGooglePay.initializeGooglePay(
          settings.locationId,
          settings.environment === 'production' ? GooglePayEnvironment.EnvironmentProduction : GooglePayEnvironment.EnvironmentTest,
        );

        return (await SQIPGooglePay.canUseGooglePay()) ? 'google' : null;
      }

      return null;
    };

    available()
      .then((ready) => {
        if (current) {
          setWallet(ready);
        }
      })
      .catch(() => undefined);

    return () => {
      current = false;
    };
  }, [settings.applicationId, settings.locationId, settings.environment]);

  /** Creates the order, then charges the token. */
  const charge = async (nonce: string | undefined, verificationToken?: string): Promise<Outcome> => {
    if (!nonce) {
      return { paid: false, message: PAYMENT_FAILED_MESSAGE };
    }

    const payment = await createPayment();

    if (payment === null) {
      return { paid: false, message: null };
    }

    if (payment.processor !== 'square') {
      return { paid: false, message: PROCESSOR_CHANGED_MESSAGE };
    }

    try {
      await paySquareOrder(
        payment.orderId,
        { tracking_token: payment.trackingToken, source_id: nonce, verification_token: verificationToken ?? null },
        randomId(),
      );

      return { paid: true };
    } catch (error) {
      return { paid: false, message: paymentErrorMessage(error) };
    }
  };

  const finish = (outcome: Outcome) => {
    setBusy(false);

    if (outcome.paid) {
      onPaid();
    } else if (outcome.message !== null) {
      onError(outcome.message);
    }
  };

  const payByCard = () => {
    if (disabled || busy || !validate()) {
      return;
    }

    setBusy(true);

    const who = contact();
    const [givenName, ...familyName] = who.name.trim().split(/\s+/);

    SQIPCardEntry.startCardEntryFlowWithBuyerVerification(
      false,
      {
        collectPostalCode: false,
        squareLocationId: settings.locationId,
        buyerAction: 'Charge',
        amount: amountCents,
        currencyCode: code,
        givenName,
        familyName: familyName.join(' ') || undefined,
        email: who.email,
        phone: who.phone,
        countryCode: 'AU',
      },
      (result) => void charge(result.nonce, result.token).then(finish),
      (error) => finish({ paid: false, message: error.message || PAYMENT_FAILED_MESSAGE }),
      () => setBusy(false),
    );
  };

  const payWithApplePay = () => {
    if (disabled || busy || !validate()) {
      return;
    }

    setBusy(true);
    let outcome: Outcome = { paid: false, message: null };

    SQIPApplePay.requestApplePayNonce(
      { price, summaryLabel: merchantName, countryCode: 'AU', currencyCode: code, paymentType: PaymentType.PaymentTypeFinal },
      async (details): Promise<ApplePayNonceSuccessResult> => {
        outcome = await charge(details.nonce);

        return outcome.paid
          ? { state: ApplePayNonceSuccessState.Succeeded }
          : { state: ApplePayNonceSuccessState.Failure, errorMessage: outcome.message ?? 'Check your order, then try again.' };
      },
      (error) => {
        outcome = { paid: false, message: error.message || PAYMENT_FAILED_MESSAGE };
      },
      // The sheet has closed: paid, failed, or cancelled (no message).
      () => finish(outcome),
    ).catch(() => finish({ paid: false, message: PAYMENT_FAILED_MESSAGE }));
  };

  const payWithGooglePay = () => {
    if (disabled || busy || !validate()) {
      return;
    }

    setBusy(true);

    SQIPGooglePay.requestGooglePayNonce(
      { price, currencyCode: code, priceStatus: GooglePayPriceStatus.TotalPriceStatusFinal },
      (details) => void charge(details.nonce).then(finish),
      (error) => finish({ paid: false, message: error.message || PAYMENT_FAILED_MESSAGE }),
      () => setBusy(false),
    ).catch(() => finish({ paid: false, message: PAYMENT_FAILED_MESSAGE }));
  };

  return (
    <View className="gap-4">
      {wallet ? (
        <>
          {/* The official Apple Pay and Google Pay buttons, drawn by Stripe's package (only the button). */}
          <PlatformPayButton
            type={PlatformPay.ButtonType.Order}
            appearance={PlatformPay.ButtonStyle.Automatic}
            borderRadius={8}
            disabled={disabled || busy}
            onPress={wallet === 'apple' ? payWithApplePay : payWithGooglePay}
            accessibilityLabel={`${wallet === 'apple' ? 'Apple Pay' : 'Google Pay'}: place order, ${formatMoney(amountCents, currency)}`}
            style={{ height: 52 }}
          />
          <OrDivider />
        </>
      ) : null}
      <Button size="lg" onPress={payByCard} disabled={disabled || busy}>
        <Text>{busy ? 'Placing order…' : `Place order · ${formatMoney(amountCents, currency)}`}</Text>
      </Button>
    </View>
  );
}
