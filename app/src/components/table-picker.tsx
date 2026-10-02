import { useId } from 'react';
import { View } from 'react-native';

import { ChoiceChip } from '@/components/choice-chip';
import { Text } from '@/components/ui/text';

/** Dine in: the restaurant's tables as chips; the customer picks the one they're at. */
export function TablePicker({
  tables,
  value,
  onChange,
  error,
}: {
  tables: string[];
  value: string | null;
  onChange: (table: string) => void;
  /** The quote's problem with the table, if any. */
  error: string | undefined;
}) {
  const labelId = useId();

  return (
    <View className="gap-2">
      <Text variant="small" nativeID={labelId}>
        Your table
      </Text>
      <View className="flex-row flex-wrap gap-2" role="radiogroup" aria-labelledby={labelId}>
        {tables.map((table) => (
          <ChoiceChip key={table} label={table} accessibilityLabel={`Table ${table}`} selected={table === value} onPress={() => onChange(table)} />
        ))}
      </View>
      {error ? (
        <Text role="alert" className="text-destructive text-sm">
          {error}
        </Text>
      ) : (
        <Text variant="muted">{value ? `We’ll bring your order to table ${value}.` : 'Choose the number on your table.'}</Text>
      )}
    </View>
  );
}
