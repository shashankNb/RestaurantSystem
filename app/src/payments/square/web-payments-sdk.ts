/**
 * Square's Web Payments SDK, loaded from Square's CDN on first use (never while rendering on
 * the server), and the parts of it the checkout uses. Square publishes it only as a script;
 * these types follow its reference (developer.squareup.com/reference/sdks/web/payments).
 */

export type SquareTokenStatus = 'OK' | 'Error' | 'Invalid' | 'Unknown' | 'Abort' | 'Cancel';

export interface SquareTokenResult {
  status: SquareTokenStatus;
  token?: string;
  errors?: { message: string }[];
}

/** For Square's card check (3-D Secure), which it runs when the card is tokenised. */
export interface SquareVerificationDetails {
  amount: string;
  currencyCode: string;
  intent: 'CHARGE';
  billingContact: { givenName?: string; familyName?: string; email?: string; phone?: string; countryCode?: string };
  customerInitiated: boolean;
  sellerKeyedIn: boolean;
}

export interface SquareCard {
  attach(element: HTMLElement): Promise<void>;
  tokenize(verificationDetails?: SquareVerificationDetails): Promise<SquareTokenResult>;
  destroy(): Promise<boolean>;
}

export interface SquareWallet {
  tokenize(): Promise<SquareTokenResult>;
  destroy(): Promise<boolean>;
}

export interface SquareGooglePay extends SquareWallet {
  attach(
    element: HTMLElement,
    options?: { buttonColor?: 'default' | 'black' | 'white'; buttonSizeMode?: 'fill' | 'static'; buttonType?: 'long' | 'short' | 'order' },
  ): Promise<void>;
}

export interface SquarePaymentRequest {
  update(options: { total: SquareTotal }): boolean;
}

export interface SquareTotal {
  amount: string;
  label: string;
}

export type SquareCardStyle = Record<string, Record<string, string>>;

export interface SquarePayments {
  card(options?: { style?: SquareCardStyle }): Promise<SquareCard>;
  paymentRequest(options: { countryCode: string; currencyCode: string; total: SquareTotal }): SquarePaymentRequest;
  /** Rejects where the browser can't take Apple Pay (or the domain isn't registered). */
  applePay(request: SquarePaymentRequest): Promise<SquareWallet>;
  /** Rejects where the browser can't take Google Pay. */
  googlePay(request: SquarePaymentRequest): Promise<SquareGooglePay>;
}

export interface SquareSdk {
  payments(applicationId: string, locationId: string): SquarePayments;
}

declare global {
  interface Window {
    Square?: SquareSdk;
  }
}

const loading = new Map<string, Promise<SquareSdk>>();

/** The SDK for the sandbox (test payments) or production. */
export function loadSquare(environment: 'sandbox' | 'production'): Promise<SquareSdk> {
  const src = environment === 'production' ? 'https://web.squarecdn.com/v1/square.js' : 'https://sandbox.web.squarecdn.com/v1/square.js';
  let sdk = loading.get(src);

  if (sdk === undefined) {
    sdk = new Promise<SquareSdk>((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      script.async = true;
      script.onload = () => (window.Square ? resolve(window.Square) : reject(new Error('Square’s payment form didn’t load.')));
      script.onerror = () => {
        // Let a later visit try again.
        loading.delete(src);
        reject(new Error('Square’s payment form didn’t load.'));
      };
      document.head.appendChild(script);
    });
    loading.set(src, sdk);
  }

  return sdk;
}

/**
 * Apple's own Apple Pay button, drawn by Safari from CSS (Square leaves the button to the
 * page). Added to the page once.
 */
export function addApplePayButtonStyles(): void {
  if (typeof document === 'undefined' || document.getElementById('apple-pay-button-styles')) {
    return;
  }

  const style = document.createElement('style');
  style.id = 'apple-pay-button-styles';
  style.textContent = [
    '.apple-pay-button { display: block; width: 100%; height: 48px; border: 0; border-radius: 8px; cursor: pointer;',
    '  -webkit-appearance: -apple-pay-button; -apple-pay-button-type: order; }',
    '.apple-pay-button-black { -apple-pay-button-style: black; }',
    '.apple-pay-button-white { -apple-pay-button-style: white; }',
    '.apple-pay-button:disabled { opacity: 0.5; cursor: default; }',
  ].join('\n');
  document.head.appendChild(style);
}
