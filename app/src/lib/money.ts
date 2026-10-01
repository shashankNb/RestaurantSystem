/** 1790 → "$17.90". Amounts are integer cents in the restaurant's currency. */
export function formatMoney(cents: number, currency = 'AUD'): string {
  return new Intl.NumberFormat('en-AU', { style: 'currency', currency }).format(cents / 100);
}

/** 100 → "+$1.00", -50 → "−$0.50", 0 → "". For option prices. */
export function formatPriceDelta(cents: number, currency = 'AUD'): string {
  if (cents === 0) {
    return '';
  }

  return `${cents > 0 ? '+' : '−'}${formatMoney(Math.abs(cents), currency)}`;
}
