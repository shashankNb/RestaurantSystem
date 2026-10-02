import { Link } from 'expo-router';
import { View } from 'react-native';

import type { CartLine } from '@/cart/cart-store';
import { QuantityStepper } from '@/components/quantity-stepper';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { formatMoney } from '@/lib/money';

/**
 * A line in the cart: the dish, its choices and note, its price, and controls to change
 * the quantity or the choices. `lineTotalCents` is the server's price once it has one.
 */
export function CartLineRow({
  line,
  lineTotalCents,
  problems = [],
  currency,
  onQuantity,
  onRemove,
}: {
  line: CartLine;
  lineTotalCents?: number;
  problems?: string[];
  currency: string;
  onQuantity: (quantity: number) => void;
  onRemove: () => void;
}) {
  return (
    <View className="border-border gap-3 border-b py-4">
      <View className="flex-row justify-between gap-4">
        <View className="flex-1 gap-0.5">
          <Text variant="item">{line.name}</Text>
          {line.optionSummary ? <Text variant="muted">{line.optionSummary}</Text> : null}
          {line.notes ? <Text variant="muted">Note: {line.notes}</Text> : null}
        </View>
        <Text className="font-body-semibold">{formatMoney(lineTotalCents ?? line.unitPriceCents * line.quantity, currency)}</Text>
      </View>
      {problems.map((problem) => (
        <Text key={problem} role="alert" className="text-destructive text-sm">
          {problem}
        </Text>
      ))}
      <View className="flex-row items-center justify-between gap-3">
        <QuantityStepper value={line.quantity} onChange={onQuantity} onRemove={onRemove} itemName={line.name} />
        <Link href={{ pathname: '/item/[id]', params: { id: String(line.menuItemId), line: line.id } }} asChild>
          <Button variant="ghost" size="sm" aria-label={`Change ${line.name}`}>
            <Text>Change</Text>
          </Button>
        </Link>
      </View>
    </View>
  );
}
