import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { View } from 'react-native';
import { z } from 'zod';

import { FormError } from '@/components/form-error';
import { TextField } from '@/components/text-field';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { useSignIn } from '@/lib/api/account';
import { applyServerErrors } from '@/lib/forms';

const signInSchema = z.object({
  email: z.email('Enter your email address, like name@example.com.'),
  password: z.string().min(1, 'Enter your password.'),
});

type SignInValues = z.infer<typeof signInSchema>;

/** Email and password. Used by the account screen and the kitchen's sign-in. */
export function SignInForm() {
  const signIn = useSignIn();
  const form = useForm<SignInValues>({
    resolver: zodResolver(signInSchema),
    defaultValues: { email: '', password: '' },
  });

  const submit = form.handleSubmit(async (values) => {
    try {
      await signIn.mutateAsync(values);
    } catch (error) {
      applyServerErrors(error, form.setError, ['email', 'password']);
    }
  });

  return (
    <View className="gap-4">
      <FormError message={form.formState.errors.root?.message} />
      <TextField
        control={form.control}
        name="email"
        label="Email"
        autoComplete="email"
        textContentType="emailAddress"
        keyboardType="email-address"
        autoCapitalize="none"
        autoCorrect={false}
        returnKeyType="next"
        onSubmitEditing={() => form.setFocus('password')}
      />
      <TextField
        control={form.control}
        name="password"
        label="Password"
        autoComplete="current-password"
        textContentType="password"
        secureTextEntry
        returnKeyType="go"
        onSubmitEditing={() => void submit()}
      />
      <Button onPress={() => void submit()} disabled={form.formState.isSubmitting}>
        <Text>{form.formState.isSubmitting ? 'Signing in…' : 'Sign in'}</Text>
      </Button>
    </View>
  );
}
