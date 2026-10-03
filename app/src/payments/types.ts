import type { JSX } from 'react';

import { ApiError } from '@/lib/api/client';

interface PaymentRequestBase {
  /**
   * The order's tracking page, e.g. "/order/abc?token=…". On the web, Stripe returns there
   * after a redirect (some card checks and payment methods leave the page).
   */
  returnPath: string;
  billing: { name: string; email: string; phone: string };
}

/** With Stripe: the order's PaymentIntent, to confirm. */
export interface StripePaymentRequest extends PaymentRequestBase {
  processor: 'stripe';
  /** The PaymentIntent's client secret. */
  clientSecret: string;
}

/** With Square: the order to pay with the token from Square's payment form. */
export interface SquarePaymentRequest extends PaymentRequestBase {
  processor: 'square';
  orderId: string;
  trackingToken: string;
}

/** What the checkout hands the payment form once the order exists. */
export type PaymentRequest = StripePaymentRequest | SquarePaymentRequest;

/**
 * The one interface every payment form implements: Stripe's PaymentSheet on iOS and Android
 * and its Payment Element on the web, or Square's card entry and Web Payments SDK
 * (PaymentForm.native.tsx and PaymentForm.web.tsx pick the restaurant's processor).
 */
export interface PaymentFormProps {
  /** The quote's total, for the button and for Apple Pay and Google Pay. */
  amountCents: number;
  currency: string;
  /** Shown in the payment sheet, e.g. "Himalayan Momo House". */
  merchantName: string;
  disabled?: boolean;
  /**
   * Checks the checkout form and shows what to fix. Synchronous, so the web form can still
   * open Apple Pay or Google Pay from the same tap.
   */
  validate: () => boolean;
  /** Who's paying, from the checkout form: Square's card check (3-D Secure) asks for it. */
  contact: () => { name: string; email: string; phone: string };
  /** Creates the order. Returns what to pay, or null when it couldn't (the checkout says why). */
  createPayment: () => Promise<PaymentRequest | null>;
  /** The payment went through. The order is confirmed a moment later, or already is. */
  onPaid: () => void;
  /** The payment didn't go through; the message says what to do. */
  onError: (message: string) => void;
}

export type PaymentFormComponent = (props: PaymentFormProps) => JSX.Element | null;

export const PAYMENT_FAILED_MESSAGE = 'We couldn’t take the payment. Check your details and try again, or use another card.';

/** The order was made for one processor while the page still showed the other. */
export const PROCESSOR_CHANGED_MESSAGE = 'The restaurant has just changed how it takes payments. Reload the page, then place your order again.';

/**
 * What to tell the customer when paying fails: the API's own message for a declined card
 * (402), an order that can't be paid any more (409), Square being down (503) or no
 * connection; something general otherwise.
 */
export function paymentErrorMessage(error: unknown): string {
  if (error instanceof ApiError && [0, 402, 409, 503].includes(error.status)) {
    return error.message;
  }

  return PAYMENT_FAILED_MESSAGE;
}
