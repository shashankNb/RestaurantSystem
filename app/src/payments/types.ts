import type { JSX } from 'react';

/** What the checkout hands the payment form once the order exists. */
export interface PaymentRequest {
  /** The PaymentIntent's client secret. */
  clientSecret: string;
  /**
   * The order's tracking page, e.g. "/order/abc?token=…". On the web, Stripe returns there
   * after a redirect (some card checks and payment methods leave the page).
   */
  returnPath: string;
  billing: { name: string; email: string; phone: string };
}

/**
 * The one interface both payment forms implement: PaymentSheet on iOS and Android
 * (PaymentForm.native.tsx), the Payment Element on the web (PaymentForm.web.tsx).
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
  /** Creates the order. Returns what to pay, or null when it couldn't (the checkout says why). */
  createPayment: () => Promise<PaymentRequest | null>;
  /** Stripe accepted the payment. The order is confirmed by webhook a moment later. */
  onPaid: () => void;
  /** The payment didn't go through; the message says what to do. */
  onError: (message: string) => void;
}

export type PaymentFormComponent = (props: PaymentFormProps) => JSX.Element | null;

export const PAYMENT_FAILED_MESSAGE = 'We couldn’t take the payment. Check your details and try again, or use another card.';
