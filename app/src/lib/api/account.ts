import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Platform } from 'react-native';

import { useSession } from '@/auth/session';
import { apiRequest } from '@/lib/api/client';
import { dataOf, signedInSchema, userSchema, type SignedIn } from '@/lib/api/schemas';

export const meQueryKey = ['me'] as const;

/** Names the API token so it can be recognised later. */
const DEVICE_NAME = Platform.select({ ios: 'iOS app', android: 'Android app', default: 'Website' });

export interface SignInInput {
  email: string;
  password: string;
}

export interface RegisterInput {
  name: string;
  email: string;
  phone?: string;
  password: string;
}

/** The signed-in user, or nothing while signed out. */
export function useMe() {
  const signedIn = useSession((state) => state.status === 'signedIn');

  return useQuery({
    queryKey: meQueryKey,
    queryFn: ({ signal }) => apiRequest('/me', { schema: dataOf(userSchema), signal }),
    enabled: signedIn,
  });
}

function useSignedIn() {
  const queryClient = useQueryClient();
  const start = useSession((state) => state.start);

  return async ({ token, user }: SignedIn) => {
    await start(token);
    queryClient.setQueryData(meQueryKey, user);
  };
}

export function useSignIn() {
  const signedIn = useSignedIn();

  return useMutation({
    mutationFn: (input: SignInInput) =>
      apiRequest('/auth/login', {
        method: 'POST',
        body: { ...input, device_name: DEVICE_NAME },
        schema: dataOf(signedInSchema),
      }),
    onSuccess: signedIn,
  });
}

export function useRegister() {
  const signedIn = useSignedIn();

  return useMutation({
    mutationFn: (input: RegisterInput) =>
      apiRequest('/auth/register', {
        method: 'POST',
        body: { ...input, device_name: DEVICE_NAME },
        schema: dataOf(signedInSchema),
      }),
    onSuccess: signedIn,
  });
}

/** Revokes the token on the server, then forgets it here even if that request failed. */
export function useSignOut() {
  const queryClient = useQueryClient();
  const clear = useSession((state) => state.clear);

  return useMutation({
    mutationFn: () => apiRequest('/auth/logout', { method: 'POST' }),
    onSettled: async () => {
      await clear();
      queryClient.removeQueries({ queryKey: meQueryKey });
    },
  });
}

/** Deletes the account on the server; the token dies with it. */
export function useDeleteAccount() {
  const queryClient = useQueryClient();
  const clear = useSession((state) => state.clear);

  return useMutation({
    mutationFn: () => apiRequest('/me', { method: 'DELETE' }),
    onSuccess: async () => {
      await clear();
      queryClient.removeQueries({ queryKey: meQueryKey });
    },
  });
}
