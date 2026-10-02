import { Platform, Pressable } from 'react-native';

import { Text } from '@/components/ui/text';
import { spaceActivates } from '@/lib/keyboard';
import { cn } from '@/lib/utils';

/**
 * A compact choice, such as a time or a table, for grids of options. Selected is filled
 * with the text colour. Put a set of them in a View with role="radiogroup".
 */
export function ChoiceChip({
  label,
  selected,
  onPress,
  accessibilityLabel,
}: {
  label: string;
  selected: boolean;
  onPress: () => void;
  accessibilityLabel?: string;
}) {
  return (
    <Pressable
      role="radio"
      aria-checked={selected}
      aria-label={accessibilityLabel ?? label}
      onPress={onPress}
      {...spaceActivates(onPress)}
      className={cn(
        'min-h-11 min-w-14 items-center justify-center rounded-md border px-3',
        selected ? 'bg-foreground border-foreground' : 'border-border bg-background active:bg-accent',
        Platform.select({ web: 'focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2' }),
      )}
    >
      <Text className={cn('font-body-medium', selected ? 'text-background' : 'text-foreground')}>{label}</Text>
    </Pressable>
  );
}
