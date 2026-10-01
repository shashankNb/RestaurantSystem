import { useId } from 'react';
import { useController, type Control, type FieldValues, type Path } from 'react-hook-form';
import { View, type TextInputProps } from 'react-native';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Text } from '@/components/ui/text';
import { Textarea } from '@/components/ui/textarea';

type TextFieldProps<T extends FieldValues> = Omit<TextInputProps, 'value' | 'onChangeText' | 'onBlur'> & {
  control: Control<T>;
  name: Path<T>;
  label: string;
  /** Shown under the input until there's an error. */
  hint?: string;
};

/**
 * A labelled input bound to React Hook Form (a textarea with `multiline`). Its error
 * replaces the hint, says how to fix the problem, and is announced to screen readers.
 */
export function TextField<T extends FieldValues>({ control, name, label, hint, ...inputProps }: TextFieldProps<T>) {
  // Destructured: the React Compiler's lint treats an object with a `ref` key as a ref.
  const {
    field: { ref, value, onChange, onBlur },
    fieldState,
  } = useController({ control, name });
  const id = useId();
  const error = fieldState.error?.message;

  return (
    <View className="gap-1.5">
      <Label nativeID={`${id}-label`}>{label}</Label>
      {inputProps.multiline ? (
        <Textarea
          ref={ref}
          value={typeof value === 'string' ? value : ''}
          onChangeText={onChange}
          onBlur={onBlur}
          aria-labelledby={`${id}-label`}
          accessibilityLabel={label}
          invalid={error !== undefined}
          {...inputProps}
        />
      ) : (
        <Input
          ref={ref}
          value={typeof value === 'string' ? value : ''}
          onChangeText={onChange}
          onBlur={onBlur}
          aria-labelledby={`${id}-label`}
          accessibilityLabel={label}
          invalid={error !== undefined}
          {...inputProps}
        />
      )}
      {error ? (
        <Text role="alert" className="text-destructive text-sm">
          {error}
        </Text>
      ) : hint ? (
        <Text variant="muted">{hint}</Text>
      ) : null}
    </View>
  );
}
