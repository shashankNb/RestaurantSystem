import { Check } from 'lucide-react-native';
import { Platform, Pressable, View } from 'react-native';

import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { spaceActivates } from '@/lib/keyboard';
import { cn } from '@/lib/utils';

/**
 * One choice in a list: a radio button or checkbox with its label and, on the right, a
 * detail such as "+$1.00" or "Sold out". The whole row is the tap target (at least 48 px
 * tall). Put radio rows in a View with role="radiogroup".
 */
export function ChoiceRow({
  kind,
  checked,
  onPress,
  label,
  detail,
  disabled = false,
  accessibilityLabel,
}: {
  kind: 'radio' | 'checkbox';
  checked: boolean;
  onPress: () => void;
  label: string;
  detail?: string;
  disabled?: boolean;
  /** What screen readers say, when it should differ from "label, detail". */
  accessibilityLabel?: string;
}) {
  return (
    <Pressable
      role={kind}
      aria-checked={checked}
      aria-disabled={disabled}
      aria-label={accessibilityLabel ?? (detail ? `${label}, ${detail}` : label)}
      disabled={disabled}
      onPress={onPress}
      {...spaceActivates(onPress, disabled)}
      className={cn(
        'min-h-12 flex-row items-center gap-3 rounded-md py-2',
        Platform.select({
          web: 'focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-offset-2',
        }),
        disabled && 'opacity-50',
      )}
    >
      <View
        className={cn(
          'size-[22px] shrink-0 items-center justify-center border-2',
          kind === 'radio' ? 'rounded-full' : 'rounded-[5px]',
          checked ? 'border-foreground' : 'border-muted-foreground',
          checked && kind === 'checkbox' && 'bg-foreground',
        )}
      >
        {checked && kind === 'radio' ? <View className="bg-foreground size-2.5 rounded-full" /> : null}
        {checked && kind === 'checkbox' ? (
          <Icon as={Check} size={14} strokeWidth={Platform.OS === 'web' ? 3 : 3.5} className="text-background" />
        ) : null}
      </View>
      <Text className="flex-1">{label}</Text>
      {detail ? <Text className="text-muted-foreground">{detail}</Text> : null}
    </Pressable>
  );
}
