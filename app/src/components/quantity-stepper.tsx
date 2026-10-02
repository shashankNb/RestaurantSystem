import { Platform, Pressable, View } from 'react-native';

import { Minus, Plus, Trash2 } from '@/components/icons';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { MAX_QUANTITY } from '@/cart/cart-store';
import { cn } from '@/lib/utils';

/**
 * "− 2 +" with 44 px buttons. With `onRemove`, the minus becomes a bin at 1, so a cart
 * line can be removed from the same place.
 */
export function QuantityStepper({
  value,
  onChange,
  itemName,
  onRemove,
  max = MAX_QUANTITY,
}: {
  value: number;
  onChange: (value: number) => void;
  /** For screen readers: "One more Steamed momo". */
  itemName: string;
  onRemove?: () => void;
  max?: number;
}) {
  const removes = onRemove !== undefined && value <= 1;

  return (
    <View className="border-border flex-row items-center rounded-md border">
      <StepButton
        icon={removes ? Trash2 : Minus}
        label={removes ? `Remove ${itemName}` : `One fewer ${itemName}`}
        disabled={!removes && value <= 1}
        onPress={() => (removes ? onRemove() : onChange(value - 1))}
      />
      <Text className="font-body-semibold min-w-8 text-center" aria-live="polite" aria-label={`Quantity ${value}`}>
        {value}
      </Text>
      <StepButton icon={Plus} label={`One more ${itemName}`} disabled={value >= max} onPress={() => onChange(value + 1)} />
    </View>
  );
}

function StepButton({
  icon,
  label,
  disabled,
  onPress,
}: {
  icon: typeof Plus;
  label: string;
  disabled: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      role="button"
      aria-label={label}
      disabled={disabled}
      onPress={onPress}
      className={cn(
        'size-11 items-center justify-center rounded-md active:bg-accent',
        Platform.select({
          web: 'hover:bg-accent focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:-outline-offset-2',
        }),
        disabled && 'opacity-40',
      )}
    >
      <Icon as={icon} className="size-5" />
    </Pressable>
  );
}
