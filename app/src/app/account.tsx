import { zodResolver } from '@hookform/resolvers/zod';
import { Link } from 'expo-router';
import type { Metadata } from 'expo-router/server';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';
import { z } from 'zod';

import { useSession } from '@/auth/session';
import { FormError } from '@/components/form-error';
import { CircleCheck } from '@/components/icons';
import { OrderHistory } from '@/components/order-history';
import { PageHead } from '@/components/page-head';
import { SavedAddresses } from '@/components/saved-addresses';
import { Screen } from '@/components/screen';
import { ScreenHeader } from '@/components/screen-header';
import { SegmentedControl } from '@/components/segmented-control';
import { SignInForm } from '@/components/sign-in-form';
import { ErrorState, LoadingState } from '@/components/states';
import { TextField } from '@/components/text-field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Text } from '@/components/ui/text';
import { useDeleteAccount, useMe, useRegister, useSignOut } from '@/lib/api/account';
import { errorMessage } from '@/lib/api/client';
import { applyServerErrors } from '@/lib/forms';
import { privatePage, toMetadata } from '@/lib/page-meta';

const email = z.email('Enter your email address, like name@example.com.');

const registerSchema = z.object({
  name: z.string().trim().min(1, 'Enter your name.').max(255, 'Use 255 characters or fewer.'),
  email,
  phone: z.string().trim().max(32, 'Use 32 characters or fewer.'),
  password: z.string().min(8, 'Use at least 8 characters.'),
});

type RegisterValues = z.infer<typeof registerSchema>;

const PAGE = privatePage('Account');

/** Web: the page's title in the server's HTML. It stays out of search results. */
export function generateMetadata(): Metadata {
  return toMetadata(PAGE);
}

export default function AccountPage() {
  return (
    <>
      <PageHead page={PAGE} />
      <AccountScreen />
    </>
  );
}

/**
 * Sign in or create an account; once signed in, the account's details, past orders and
 * saved addresses.
 */
function AccountScreen() {
  const status = useSession((state) => state.status);
  const [notice, setNotice] = useState<string | null>(null);

  return (
    <Screen>
      <ScreenHeader title="Account" />
      <KeyboardAvoidingView className="flex-1" behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerClassName="w-full max-w-lg gap-6 self-center px-4 pb-12 pt-2">
          {notice ? (
            <Alert icon={CircleCheck}>
              <AlertDescription className="text-foreground">{notice}</AlertDescription>
            </Alert>
          ) : null}
          {status === 'restoring' ? (
            <LoadingState label="Checking your sign-in" />
          ) : status === 'signedIn' ? (
            <SignedInAccount onDeleted={() => setNotice('Your account is deleted.')} />
          ) : (
            <SignInOrRegister />
          )}
          {/* The way in on a phone or tablet, which has no address bar. */}
          <Link href="/staff" className="text-muted-foreground self-start py-3 text-sm underline">
            Restaurant staff: open the kitchen screens
          </Link>
        </ScrollView>
      </KeyboardAvoidingView>
    </Screen>
  );
}

function SignInOrRegister() {
  const [mode, setMode] = useState<'signIn' | 'register'>('signIn');

  return (
    <View className="gap-6">
      <Text className="text-muted-foreground">
        Sign in to save your details for next time and see your past orders. You can also order without an account.
      </Text>
      <SegmentedControl
        label="Sign in or create an account"
        value={mode}
        onChange={setMode}
        options={[
          { value: 'signIn', label: 'Sign in' },
          { value: 'register', label: 'Create account' },
        ]}
      />
      {mode === 'signIn' ? <SignInForm /> : <RegisterForm />}
    </View>
  );
}

function RegisterForm() {
  const register = useRegister();
  const form = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: { name: '', email: '', phone: '', password: '' },
  });

  const submit = form.handleSubmit(async ({ phone, ...values }) => {
    try {
      await register.mutateAsync({ ...values, phone: phone === '' ? undefined : phone });
    } catch (error) {
      applyServerErrors(error, form.setError, ['name', 'email', 'phone', 'password']);
    }
  });

  return (
    <View className="gap-4">
      <FormError message={form.formState.errors.root?.message} />
      <TextField
        control={form.control}
        name="name"
        label="Name"
        autoComplete="name"
        textContentType="name"
        returnKeyType="next"
        onSubmitEditing={() => form.setFocus('email')}
      />
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
        onSubmitEditing={() => form.setFocus('phone')}
      />
      <TextField
        control={form.control}
        name="phone"
        label="Mobile (optional)"
        hint="So the restaurant can call you about an order."
        autoComplete="tel"
        textContentType="telephoneNumber"
        keyboardType="phone-pad"
        returnKeyType="next"
        onSubmitEditing={() => form.setFocus('password')}
      />
      <TextField
        control={form.control}
        name="password"
        label="Password"
        hint="At least 8 characters."
        autoComplete="new-password"
        textContentType="newPassword"
        secureTextEntry
        returnKeyType="go"
        onSubmitEditing={() => void submit()}
      />
      <Button onPress={() => void submit()} disabled={form.formState.isSubmitting}>
        <Text>{form.formState.isSubmitting ? 'Creating your account…' : 'Create account'}</Text>
      </Button>
    </View>
  );
}

function SignedInAccount({ onDeleted }: { onDeleted: () => void }) {
  const me = useMe();
  const signOut = useSignOut();
  const deleteAccount = useDeleteAccount();
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  if (me.isPending) {
    return <LoadingState label="Loading your account" />;
  }

  if (me.isError) {
    return (
      <ErrorState
        title="We couldn’t load your account"
        message={errorMessage(me.error)}
        onRetry={() => void me.refetch()}
        retrying={me.isFetching}
      />
    );
  }

  const user = me.data;

  return (
    <View className="gap-8">
      <View className="gap-1">
        <Text variant="item">{user.name}</Text>
        <Text className="text-muted-foreground">{user.email}</Text>
        {user.phone ? <Text className="text-muted-foreground">{user.phone}</Text> : null}
      </View>

      <View className="gap-3">
        <Text variant="heading">Your orders</Text>
        <OrderHistory />
      </View>

      <View className="gap-3">
        <Text variant="heading">Saved addresses</Text>
        <SavedAddresses />
      </View>

      <Separator />

      <Button variant="outline" className="self-start" onPress={() => signOut.mutate()} disabled={signOut.isPending}>
        <Text>{signOut.isPending ? 'Signing out…' : 'Sign out'}</Text>
      </Button>

      <Separator />

      <View className="gap-3">
        <Text variant="item">Delete your account</Text>
        <Text className="text-muted-foreground">
          We remove your account, saved addresses and sign-ins. Past orders stay with the restaurant for its
          records, without your name or contact details.
        </Text>

        {confirmingDelete ? (
          <View className="gap-3">
            <Text className="font-body-semibold">Delete your account? You can’t undo this.</Text>
            <FormError message={deleteAccount.isError ? errorMessage(deleteAccount.error) : undefined} />
            <View className="flex-row flex-wrap gap-3">
              <Button
                variant="destructive"
                onPress={() => deleteAccount.mutate(undefined, { onSuccess: onDeleted })}
                disabled={deleteAccount.isPending}
              >
                <Text>{deleteAccount.isPending ? 'Deleting…' : 'Delete account'}</Text>
              </Button>
              <Button
                variant="outline"
                onPress={() => {
                  setConfirmingDelete(false);
                  deleteAccount.reset();
                }}
              >
                <Text>Keep my account</Text>
              </Button>
            </View>
          </View>
        ) : (
          <Button variant="outline" className="self-start" onPress={() => setConfirmingDelete(true)}>
            <Text className="text-destructive">Delete account</Text>
          </Button>
        )}
      </View>
    </View>
  );
}
