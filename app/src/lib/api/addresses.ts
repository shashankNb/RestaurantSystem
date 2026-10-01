import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { useSession } from '@/auth/session';
import { apiRequest } from '@/lib/api/client';
import { dataOf, savedAddressSchema } from '@/lib/api/schemas';

const addressesKey = ['addresses'] as const;

export interface AddressInput {
  label: string | null;
  line1: string;
  line2: string | null;
  suburb: string;
  state: string;
  postcode: string;
  delivery_instructions: string | null;
}

/** The signed-in customer's saved delivery addresses. */
export function useAddresses() {
  const signedIn = useSession((state) => state.status === 'signedIn');

  return useQuery({
    queryKey: addressesKey,
    queryFn: ({ signal }) => apiRequest('/me/addresses', { schema: dataOf(z.array(savedAddressSchema)), signal }),
    enabled: signedIn,
  });
}

export function useSaveAddress() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id?: number; input: AddressInput }) =>
      apiRequest(id === undefined ? '/me/addresses' : `/me/addresses/${id}`, {
        method: id === undefined ? 'POST' : 'PATCH',
        body: input,
        schema: dataOf(savedAddressSchema),
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: addressesKey }),
  });
}

export function useDeleteAddress() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiRequest(`/me/addresses/${id}`, { method: 'DELETE' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: addressesKey }),
  });
}
