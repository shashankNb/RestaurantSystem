import { Platform, Pressable, View } from 'react-native';

import { Text } from '@/components/ui/text';
import { spaceActivates } from '@/lib/keyboard';
import { cn } from '@/lib/utils';

/**
 * An on/off setting: its label (and what the current state means) with a switch on the
 * right. The whole row is the tap target. On is Coriander (good news), off is neutral.
 */
export function SwitchRow({
  label,
  value,
  onChange,
  detail,
  disabled = false,
}: {
  label: string;
  value: boolean;
  onChange: (value: boolean) => void;
  /** Describes the current state, e.g. "Sold out". */
  detail?: string;
  disabled?: boolean;
}) {
  const toggle = () => onChange(!value);

  return (
    <Pressable
      role="switch"
      aria-checked={value}
      aria-disabled={disabled}
      aria-label={detail ? `${label}, ${detail}` : label}
      disabled={disabled}
      onPress={toggle}
      {...spaceActivates(toggle, disabled)}
      className={cn(
        'min-h-12 flex-row items-center gap-4 rounded-md py-2',
        Platform.select({
          web: 'focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2',
        }),
        disabled && 'opacity-50',
      )}
    >
      <View className="flex-1 gap-0.5">
        <Text>{label}</Text>
        {detail ? <Text className={cn('text-sm', value ? 'text-muted-foreground' : 'text-destructive font-body-medium')}>{detail}</Text> : null}
      </View>
      <View
        className={cn(
          'h-7 w-12 justify-center rounded-full border-2 px-0.5',
          value ? 'bg-coriander border-coriander items-end' : 'border-input bg-background items-start',
        )}
      >
        <View className={cn('size-5 rounded-full', value ? 'bg-background' : 'bg-muted-foreground')} />
      </View>
    </Pressable>
  );
}
