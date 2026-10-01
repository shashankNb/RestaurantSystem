import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { View } from 'react-native';
import { z } from 'zod';

import { ErrorState } from '@/components/states';
import { TextField } from '@/components/text-field';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { useAddresses, useDeleteAddress, useSaveAddress } from '@/lib/api/addresses';
import { errorMessage } from '@/lib/api/client';
import { useRestaurant } from '@/lib/api/restaurant';
import type { SavedAddress } from '@/lib/api/schemas';
import { applyServerErrors } from '@/lib/forms';

const addressSchema = z.object({
  label: z.string().trim().max(50, 'Use 50 characters or fewer.'),
  line1: z.string().trim().min(1, 'Enter the street address.').max(255, 'Use 255 characters or fewer.'),
  line2: z.string().trim().max(255, 'Use 255 characters or fewer.'),
  suburb: z.string().trim().min(1, 'Enter the suburb.').max(100, 'Use 100 characters or fewer.'),
  state: z.string().trim().min(1, 'Enter the state, like VIC.').max(40, 'Use 40 characters or fewer.'),
  postcode: z.string().trim().regex(/^\d{4}$/, 'Enter a 4-digit postcode, like 3006.'),
  delivery_instructions: z.string().trim().max(500, 'Use 500 characters or fewer.'),
});

type AddressValues = z.infer<typeof addressSchema>;

/** Delivery addresses saved to the account, offered at checkout. Add, change or delete. */
export function SavedAddresses() {
  const addresses = useAddresses();
  // null: nothing open; 'new': adding; a number: changing that address.
  const [editing, setEditing] = useState<number | 'new' | null>(null);

  if (addresses.isPending) {
    return <Skeleton className="h-14 w-full" />;
  }

  if (addresses.isError) {
    return (
      <ErrorState
        title="We couldn’t load your addresses"
        message={errorMessage(addresses.error)}
        onRetry={() => void addresses.refetch()}
        retrying={addresses.isFetching}
      />
    );
  }

  return (
    <View className="gap-3">
      {addresses.data.length === 0 && editing === null ? (
        <Text className="text-muted-foreground">Save an address to fill it in at checkout.</Text>
      ) : null}

      {addresses.data.map((address) =>
        editing === address.id ? (
          <AddressForm key={address.id} address={address} onDone={() => setEditing(null)} />
        ) : (
          <AddressRow key={address.id} address={address} onEdit={() => setEditing(address.id)} />
        ),
      )}

      {editing === 'new' ? (
        <AddressForm onDone={() => setEditing(null)} />
      ) : (
        <Button variant="outline" className="self-start" onPress={() => setEditing('new')}>
          <Text>Add an address</Text>
        </Button>
      )}
    </View>
  );
}

function AddressRow({ address, onEdit }: { address: SavedAddress; onEdit: () => void }) {
  const remove = useDeleteAddress();
  const [confirming, setConfirming] = useState(false);

  return (
    <View className="border-border gap-2 border-b pb-3">
      <View className="gap-0.5">
        {address.label ? <Text className="font-body-semibold">{address.label}</Text> : null}
        <Text>{[address.line1, address.line2].filter(Boolean).join(', ')}</Text>
        <Text className="text-muted-foreground">
          {address.suburb} {address.state} {address.postcode}
        </Text>
      </View>
      {remove.isError ? (
        <Text role="alert" className="text-destructive text-sm">
          {errorMessage(remove.error)}
        </Text>
      ) : null}
      {confirming ? (
        <View className="flex-row flex-wrap items-center gap-3">
          <Text className="font-body-semibold">Delete this address?</Text>
          <Button variant="destructive" size="sm" onPress={() => remove.mutate(address.id)} disabled={remove.isPending}>
            <Text>{remove.isPending ? 'Deleting…' : 'Delete'}</Text>
          </Button>
          <Button variant="ghost" size="sm" onPress={() => setConfirming(false)}>
            <Text>Keep it</Text>
          </Button>
        </View>
      ) : (
        <View className="flex-row gap-2">
          <Button variant="ghost" size="sm" className="px-3" onPress={onEdit} aria-label={`Change ${address.label ?? address.line1}`}>
            <Text>Change</Text>
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="px-3"
            onPress={() => setConfirming(true)}
            aria-label={`Delete ${address.label ?? address.line1}`}
          >
            <Text className="text-destructive">Delete</Text>
          </Button>
        </View>
      )}
    </View>
  );
}

function AddressForm({ address, onDone }: { address?: SavedAddress; onDone: () => void }) {
  const save = useSaveAddress();
  const { data: restaurant } = useRestaurant();
  const form = useForm<AddressValues>({
    resolver: zodResolver(addressSchema),
    defaultValues: {
      label: address?.label ?? '',
      line1: address?.line1 ?? '',
      line2: address?.line2 ?? '',
      suburb: address?.suburb ?? '',
      state: address?.state ?? restaurant?.address?.state ?? '',
      postcode: address?.postcode ?? '',
      delivery_instructions: address?.delivery_instructions ?? '',
    },
  });

  const submit = form.handleSubmit(async (values) => {
    try {
      await save.mutateAsync({
        id: address?.id,
        input: {
          ...values,
          label: values.label || null,
          line2: values.line2 || null,
          delivery_instructions: values.delivery_instructions || null,
        },
      });
      onDone();
    } catch (error) {
      applyServerErrors(error, form.setError, ['label', 'line1', 'line2', 'suburb', 'state', 'postcode', 'delivery_instructions']);
    }
  });

  return (
    <View className="border-border gap-4 rounded-md border p-4">
      <Text variant="item">{address ? 'Change address' : 'New address'}</Text>
      {form.formState.errors.root?.message ? (
        <Text role="alert" className="text-destructive">
          {form.formState.errors.root.message}
        </Text>
      ) : null}
      <TextField control={form.control} name="label" label="Name for it (optional)" hint="For example, Home or Work." />
      <TextField control={form.control} name="line1" label="Street address" autoComplete="address-line1" textContentType="streetAddressLine1" />
      <TextField
        control={form.control}
        name="line2"
        label="Apartment, unit or floor (optional)"
        autoComplete="address-line2"
        textContentType="streetAddressLine2"
      />
      <TextField control={form.control} name="suburb" label="Suburb" autoComplete="postal-address-locality" textContentType="addressCity" />
      <View className="flex-row gap-3">
        <View className="flex-1">
          <TextField control={form.control} name="state" label="State" autoCapitalize="characters" autoComplete="postal-address-region" textContentType="addressState" />
        </View>
        <View className="flex-1">
          <TextField
            control={form.control}
            name="postcode"
            label="Postcode"
            keyboardType="number-pad"
            maxLength={4}
            autoComplete="postal-code"
            textContentType="postalCode"
          />
        </View>
      </View>
      <TextField
        control={form.control}
        name="delivery_instructions"
        label="Instructions for the driver (optional)"
        multiline
      />
      <View className="flex-row flex-wrap gap-3">
        <Button onPress={() => void submit()} disabled={form.formState.isSubmitting}>
          <Text>{form.formState.isSubmitting ? 'Saving…' : 'Save address'}</Text>
        </Button>
        <Button variant="outline" onPress={onDone}>
          <Text>Cancel</Text>
        </Button>
      </View>
    </View>
  );
}
