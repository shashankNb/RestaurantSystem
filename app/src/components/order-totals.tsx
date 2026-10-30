import { View } from 'react-native';

import { Text } from '@/components/ui/text';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

/**
 * Subtotal, delivery, discount and total, with the GST included in it (prices include
 * GST, so the tax invoice line is "Includes GST of …"). When the delivery fee is DoorDash's
 * price for an address not entered yet, it says so, and the total is "+ delivery".
 */
export function OrderTotals({
  subtotalCents,
  deliveryFeeCents,
  deliveryFeePending = false,
  discountCents,
  totalCents,
  gstCents,
  delivery,
  promoCode,
  currency,
}: {
  subtotalCents: number;
  deliveryFeeCents: number;
  deliveryFeePending?: boolean;
  discountCents: number;
  totalCents: number;
  gstCents: number;
  delivery: boolean;
  promoCode: string | null;
  currency: string;
}) {
  return (
    <View className="gap-1.5">
      <Row label="Subtotal" value={formatMoney(subtotalCents, currency)} />
      {delivery ? (
        <Row
          label="Delivery"
          value={deliveryFeePending ? 'Priced from your address' : deliveryFeeCents === 0 ? 'Free' : formatMoney(deliveryFeeCents, currency)}
        />
      ) : null}
      {discountCents > 0 ? (
        <Row
          label={promoCode ? `Discount (${promoCode})` : 'Discount'}
          value={`−${formatMoney(discountCents, currency)}`}
          valueClassName="text-coriander"
        />
      ) : null}
      <View className="border-border mt-1.5 flex-row justify-between gap-4 border-t pt-3">
        <Text variant="item">Total</Text>
        <Text variant="item">
          {formatMoney(totalCents, currency)}
          {deliveryFeePending ? ' + delivery' : ''}
        </Text>
      </View>
      <Text variant="muted">Includes GST of {formatMoney(gstCents, currency)}</Text>
    </View>
  );
}

function Row({ label, value, valueClassName }: { label: string; value: string; valueClassName?: string }) {
  return (
    <View className="flex-row justify-between gap-4">
      <Text className="text-muted-foreground">{label}</Text>
      <Text className={cn('font-body-medium', valueClassName)}>{value}</Text>
    </View>
  );
}
