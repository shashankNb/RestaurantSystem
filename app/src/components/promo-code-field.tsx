import { useId, useState } from 'react';
import { View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Text } from '@/components/ui/text';

/**
 * "Add a promo code", then a field to enter it. The quote checks it: once it applies, its
 * description shows here; if it doesn't, the reason does.
 */
export function PromoCodeField({
  code,
  applied,
  error,
  onApply,
  onRemove,
}: {
  code: string | null;
  /** The quote's description of the applied code, e.g. "10% off food". */
  applied: string | null;
  error: string | undefined;
  onApply: (code: string) => void;
  onRemove: () => void;
}) {
  const [open, setOpen] = useState(code !== null);
  const [value, setValue] = useState(code ?? '');
  const labelId = useId();

  if (code !== null && applied !== null) {
    return (
      <View className="flex-row items-center justify-between gap-3">
        <View className="flex-1">
          <Text className="font-body-semibold">{code}</Text>
          <Text className="text-coriander text-sm">{applied}</Text>
        </View>
        <Button
          variant="ghost"
          size="sm"
          onPress={() => {
            setValue('');
            onRemove();
          }}
        >
          <Text>Remove</Text>
        </Button>
      </View>
    );
  }

  if (!open) {
    return (
      <Button variant="link" className="h-11 self-start px-0" onPress={() => setOpen(true)}>
        <Text>Add a promo code</Text>
      </Button>
    );
  }

  const apply = () => {
    const trimmed = value.trim().toUpperCase();

    if (trimmed !== '') {
      onApply(trimmed);
    }
  };

  return (
    <View className="gap-1.5">
      <Label nativeID={labelId}>Promo code</Label>
      <View className="flex-row gap-2">
        <Input
          className="flex-1"
          value={value}
          onChangeText={setValue}
          autoCapitalize="characters"
          autoCorrect={false}
          maxLength={40}
          returnKeyType="done"
          onSubmitEditing={apply}
          aria-labelledby={labelId}
          accessibilityLabel="Promo code"
          invalid={error !== undefined}
        />
        <Button variant="outline" onPress={apply} disabled={value.trim() === ''}>
          <Text>Apply</Text>
        </Button>
      </View>
      {error ? (
        <View className="flex-row flex-wrap items-center gap-x-3">
          <Text role="alert" className="text-destructive flex-1 text-sm">
            {error}
          </Text>
          <Button
            variant="link"
            className="h-11 px-0"
            onPress={() => {
              setValue('');
              setOpen(false);
              onRemove();
            }}
          >
            <Text>Remove code</Text>
          </Button>
        </View>
      ) : null}
    </View>
  );
}
