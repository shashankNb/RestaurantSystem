import { Link, Redirect } from 'expo-router';
import type { Metadata } from 'expo-router/server';
import { useEffect } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';

import { useSession } from '@/auth/session';
import { CircleAlert } from '@/components/icons';
import { PageHead } from '@/components/page-head';
import { Screen } from '@/components/screen';
import { SignInForm } from '@/components/sign-in-form';
import { LoadingState } from '@/components/states';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { useShift } from '@/kitchen/shift';
import { useMe, useSignOut } from '@/lib/api/account';
import { useRestaurant } from '@/lib/api/restaurant';
import { config } from '@/lib/config';
import { privatePage, toMetadata } from '@/lib/page-meta';

const PAGE = privatePage('Kitchen sign-in');

/** Web: the page's title in the server's HTML. It stays out of search results. */
export function generateMetadata(): Metadata {
  return toMetadata(PAGE);
}

export default function StaffLoginPage() {
  return (
    <>
      <PageHead page={PAGE} />
      <StaffLogin />
    </>
  );
}

/** Kitchen sign-in, for the restaurant's staff and owners. Signed-in staff go to the board. */
function StaffLogin() {
  const status = useSession((state) => state.status);
  const me = useMe();
  const signOut = useSignOut();
  const { data: restaurant } = useRestaurant();
  const name = restaurant?.name ?? 'the restaurant';
  const isStaff = me.data?.restaurants.some((membership) => membership.slug === config.restaurantSlug) ?? false;

  // Here when signed out (or the session ran out): no shift, until someone starts one again.
  useEffect(() => useShift.getState().setStarted(false), []);

  if (status === 'signedIn' && isStaff) {
    return <Redirect href="/staff/orders" />;
  }

  let content;

  if (status === 'restoring' || (status === 'signedIn' && me.isPending)) {
    content = <LoadingState label="Checking your sign-in" />;
  } else if (status === 'signedIn' && me.data) {
    content = (
      <View className="gap-4">
        <Alert icon={CircleAlert} variant="destructive">
          <AlertDescription>
            {me.data.email} doesn’t have kitchen access at {name}. Sign out, then sign in with a staff account.
          </AlertDescription>
        </Alert>
        <Button variant="outline" className="self-start" onPress={() => signOut.mutate()} disabled={signOut.isPending}>
          <Text>{signOut.isPending ? 'Signing out…' : 'Sign out'}</Text>
        </Button>
      </View>
    );
  } else {
    content = <SignInForm />;
  }

  return (
    <Screen>
      <KeyboardAvoidingView className="flex-1" behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerClassName="w-full max-w-md gap-6 self-center px-4 pb-12 pt-10">
          <View className="gap-2">
            <Text variant="display">{restaurant?.name ?? 'Kitchen'}</Text>
            <Text variant="heading">Kitchen sign-in</Text>
            <Text className="text-muted-foreground">For the restaurant’s staff: take orders, mark dishes sold out and pause ordering.</Text>
          </View>
          {content}
          <Link href="/" className="text-brand-text font-body-semibold py-3 underline">
            Go to the menu
          </Link>
        </ScrollView>
      </KeyboardAvoidingView>
    </Screen>
  );
}
