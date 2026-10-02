import { Platform, Pressable, View } from 'react-native';

import { Text } from '@/components/ui/text';
import { spaceActivates } from '@/lib/keyboard';
import { cn } from '@/lib/utils';

interface Option<T extends string> {
  value: T;
  label: string;
}

/**
 * Two or three mutually exclusive choices side by side, such as "Pickup | Delivery".
 * The chosen one is filled with the text colour (calm, not the brand maroon).
 */
export function SegmentedControl<T extends string>({
  value,
  onChange,
  options,
  label,
}: {
  value: T;
  onChange: (value: T) => void;
  options: Option<T>[];
  /** Names the group for screen readers. */
  label: string;
}) {
  return (
    <View role="radiogroup" aria-label={label} className="border-border flex-row overflow-hidden rounded-md border">
      {options.map((option) => {
        const selected = option.value === value;

        return (
          <Pressable
            key={option.value}
            role="radio"
            aria-checked={selected}
            onPress={() => onChange(option.value)}
            {...spaceActivates(() => onChange(option.value))}
            className={cn(
              'min-h-11 flex-1 items-center justify-center px-3',
              selected ? 'bg-foreground' : 'bg-background active:bg-accent',
              Platform.select({
                web: 'focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:-outline-offset-4',
              }),
            )}
          >
            <Text className={cn('font-body-semibold', selected ? 'text-background' : 'text-foreground')}>
              {option.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}
