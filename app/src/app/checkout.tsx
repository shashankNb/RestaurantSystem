import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, router } from 'expo-router';
import { CircleAlert } from 'lucide-react-native';
import { useRef, useState, type ReactNode } from 'react';
import { useForm, type Path } from 'react-hook-form';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';
import { z } from 'zod';

import { useSession } from '@/auth/session';
import { useCart } from '@/cart/cart-store';
import { ChoiceRow } from '@/components/choice-row';
import { OrderTotals } from '@/components/order-totals';
import { Screen } from '@/components/screen';
import { ScreenHeader } from '@/components/screen-header';
import { EmptyState, ErrorState, LoadingState } from '@/components/states';
import { TextField } from '@/components/text-field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { useMe } from '@/lib/api/account';
import { useAddresses, useSaveAddress } from '@/lib/api/addresses';
import { ApiError, errorMessage } from '@/lib/api/client';
import { cartRequest, useQuote } from '@/lib/api/ordering';
import { orderQueryKey, usePlaceOrder, type OrderRequest } from '@/lib/api/orders';
import { useRestaurant } from '@/lib/api/restaurant';
import type { Checkout, Quote, SavedAddress, User } from '@/lib/api/schemas';
import { describeDay, formatTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { pushTokenIfAllowed } from '@/lib/push';
import { randomId } from '@/lib/random-id';
import { useRecentOrders } from '@/orders/recent-orders';
import { PaymentForm } from '@/payments/PaymentForm';
import type { PaymentRequest } from '@/payments/types';

const contact = {
  name: z.string().trim().min(1, 'Enter your name so the restaurant knows whose order it is.').max(255, 'Use 255 characters or fewer.'),
  phone: z
    .string()
    .trim()
    .regex(/^\+?[0-9 ()-]{8,}$/, 'Enter a phone number with at least 8 digits, like 0412 345 678.')
    .max(32, 'Use 32 characters or fewer.'),
  email: z.string().trim().pipe(z.email('Enter an email address like name@example.com.')),
  notes: z.string().trim().max(500, 'Use 500 characters or fewer.'),
};

const address = {
  line1: z.string().trim().max(255, 'Use 255 characters or fewer.'),
  line2: z.string().trim().max(255, 'Use 255 characters or fewer.'),
  suburb: z.string().trim().max(100, 'Use 100 characters or fewer.'),
  state: z.string().trim().max(40, 'Use 40 characters or fewer.'),
  instructions: z.string().trim().max(500, 'Use 500 characters or fewer.'),
};

const pickupSchema = z.object({ ...contact, ...address });
const deliverySchema = z.object({
  ...contact,
  ...address,
  line1: address.line1.min(1, 'Enter the street address for delivery.'),
  suburb: address.suburb.min(1, 'Enter the suburb for delivery.'),
});

type CheckoutValues = z.infer<typeof pickupSchema>;

/** The form's fields and what the API calls them, for its validation errors. */
const API_FIELDS: Record<Path<CheckoutValues>, string> = {
  name: 'customer.name',
  phone: 'customer.phone',
  email: 'customer.email',
  notes: 'notes',
  line1: 'delivery.line1',
  line2: 'delivery.line2',
  suburb: 'delivery.suburb',
  state: 'delivery.state',
  instructions: 'delivery.instructions',
};

/**
 * Checkout: who the order is for, where it goes (for delivery), then "Place order", which
 * creates the order and takes the payment (PaymentSheet on iOS and Android, the Payment
 * Element on the web). Details are filled in from the account when signed in.
 */
export default function CheckoutScreen() {
  const status = useSession((state) => state.status);
  const me = useMe();
  const lines = useCart((state) => state.lines);
  const [completing, setCompleting] = useState(false);

  let content: ReactNode;

  if (completing) {
    content = <LoadingState label="Opening your order" />;
  } else if (lines.length === 0) {
    content = (
      <EmptyState
        title="Your cart is empty"
        message="Add something from the menu to start an order."
        action={
          <Button variant="outline" onPress={() => router.replace('/')}>
            <Text>Back to the menu</Text>
          </Button>
        }
      />
    );
  } else if (status === 'signedIn' && me.isPending) {
    content = <LoadingState label="Loading your details" />;
  } else {
    const user = status === 'signedIn' ? me.data : undefined;

    content = <CheckoutForm key={user ? `user:${user.id}` : 'guest'} user={user} onCompleting={() => setCompleting(true)} />;
  }

  return (
    <Screen>
      <ScreenHeader title="Check out" backLabel="Back to your cart" fallback="/cart" />
      {content}
    </Screen>
  );
}

function CheckoutForm({ user, onCompleting }: { user: User | undefined; onCompleting: () => void }) {
  const queryClient = useQueryClient();
  const { data: restaurant } = useRestaurant();
  const lines = useCart((state) => state.lines);
  const fulfilment = useCart((state) => state.fulfilment);
  const postcode = useCart((state) => state.postcode);
  const scheduledFor = useCart((state) => state.scheduledFor);
  const promoCode = useCart((state) => state.promoCode);
  const remember = useRecentOrders((state) => state.remember);
  const addresses = useAddresses();
  const saveAddress = useSaveAddress();
  const placeOrder = usePlaceOrder();
  const pushToken = useQuery({ queryKey: ['push-token'], queryFn: pushTokenIfAllowed, staleTime: Infinity });

  const delivery = fulfilment === 'delivery';
  const schema = delivery ? deliverySchema : pickupSchema;
  const form = useForm<CheckoutValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: user?.name ?? '',
      phone: user?.phone ?? '',
      email: user?.email ?? '',
      notes: '',
      line1: '',
      line2: '',
      suburb: '',
      state: restaurant?.address?.state ?? '',
      instructions: '',
    },
  });

  const [savedAddressId, setSavedAddressId] = useState<number | null>(null);
  const [saveNewAddress, setSaveNewAddress] = useState(true);
  const [problem, setProblem] = useState<{ message: string; inCart: boolean } | null>(null);
  // One Idempotency-Key per version of the order: a retry of the same order reuses it (and
  // gets the same order back); any change to the order gets a new one.
  const attempt = useRef<{ payload: string; key: string } | null>(null);
  const placed = useRef<Checkout | null>(null);

  const request = delivery && postcode === null ? null : cartRequest({ lines, fulfilment, postcode, scheduledFor, promoCode });
  const quote = useQuote(request);
  const currency = restaurant?.currency ?? 'AUD';
  const timeZone = restaurant?.timezone ?? 'Australia/Melbourne';
  const current = quote.data !== undefined && !quote.isPlaceholderData ? quote.data : null;
  const ready = current?.can_place_order === true;

  const chooseSavedAddress = (saved: SavedAddress | null) => {
    setSavedAddressId(saved?.id ?? null);
    form.setValue('line1', saved?.line1 ?? '');
    form.setValue('line2', saved?.line2 ?? '');
    form.setValue('suburb', saved?.suburb ?? '');
    form.setValue('state', saved?.state ?? restaurant?.address?.state ?? '');
    form.setValue('instructions', saved?.delivery_instructions ?? '');
    form.clearErrors(['line1', 'line2', 'suburb', 'state', 'instructions']);

    // The saved address's postcode becomes the cart's, and the cart is priced again.
    if (saved && saved.postcode !== postcode) {
      useCart.getState().setPostcode(saved.postcode);
    }
  };

  // Synchronous (see PaymentFormProps). handleSubmit shows any errors and focuses the
  // first; from then on, each field is checked again as it changes.
  const validate = () => {
    void form.handleSubmit(() => undefined)();

    return schema.safeParse(form.getValues()).success;
  };

  const createPayment = async (): Promise<PaymentRequest | null> => {
    if (request === null || current === null) {
      return null;
    }

    setProblem(null);

    const parsed = schema.safeParse(form.getValues());

    if (!parsed.success) {
      return null;
    }

    const values = parsed.data;
    const order: OrderRequest = {
      ...request,
      customer: { name: values.name, phone: values.phone, email: values.email },
      delivery: delivery
        ? {
            line1: values.line1,
            line2: values.line2 || null,
            suburb: values.suburb,
            state: values.state || null,
            instructions: values.instructions || null,
          }
        : null,
      notes: values.notes || null,
    };
    const payload = JSON.stringify(order);

    if (attempt.current?.payload !== payload) {
      attempt.current = { payload, key: randomId() };
    }

    let checkout: Checkout;

    try {
      checkout = await placeOrder.mutateAsync({
        request: { ...order, push_token: pushToken.data ?? null },
        idempotencyKey: attempt.current.key,
      });
    } catch (error) {
      showPlaceError(error);

      return null;
    }

    // Prices changed between the quote and the order (a menu or promo change): show the
    // new total before taking payment. Placing again returns this same order.
    if (checkout.payment.amount_cents !== current.total_cents) {
      await queryClient.invalidateQueries({ queryKey: ['quote'] });
      setProblem({
        message: `The total is now ${formatMoney(checkout.payment.amount_cents, currency)}. Check it, then place your order again.`,
        inCart: false,
      });

      return null;
    }

    placed.current = checkout;

    return {
      clientSecret: checkout.payment.client_secret,
      returnPath: `/order/${encodeURIComponent(checkout.order.public_id)}?token=${encodeURIComponent(checkout.tracking_token)}`,
      billing: { name: values.name, email: values.email, phone: values.phone },
    };
  };

  const showPlaceError = (error: unknown) => {
    if (error instanceof ApiError && error.status === 422) {
      let onField = false;

      for (const [field, apiField] of Object.entries(API_FIELDS) as [Path<CheckoutValues>, string][]) {
        const message = error.fieldError(apiField);

        if (message) {
          form.setError(field, { type: 'server', message }, { shouldFocus: !onField });
          onField = true;
        }
      }

      if (onField) {
        return;
      }

      // Something about the cart itself: a dish sold out, the time passed, the promo ended.
      void queryClient.invalidateQueries({ queryKey: ['quote'] });
      setProblem({ message: error.message, inCart: true });

      return;
    }

    setProblem({ message: errorMessage(error), inCart: false });
  };

  const onPaid = () => {
    const checkout = placed.current;

    if (checkout === null) {
      return;
    }

    const { public_id: publicId } = checkout.order;
    remember(publicId, checkout.tracking_token);
    queryClient.setQueryData(orderQueryKey(publicId), checkout.order);

    if (user && delivery && savedAddressId === null && saveNewAddress && postcode) {
      const values = form.getValues();
      saveAddress.mutate({
        input: {
          label: null,
          line1: values.line1.trim(),
          line2: values.line2.trim() || null,
          suburb: values.suburb.trim(),
          state: values.state.trim() || (restaurant?.address?.state ?? ''),
          postcode,
          delivery_instructions: values.instructions.trim() || null,
        },
      });
    }

    onCompleting();

    if (router.canDismiss()) {
      router.dismissAll();
    }

    router.push({ pathname: '/order/[publicId]', params: { publicId, token: checkout.tracking_token } });
    useCart.getState().clear();
  };

  const count = lines.reduce((sum, line) => sum + line.quantity, 0);

  return (
    <KeyboardAvoidingView className="flex-1" behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerClassName="w-full max-w-2xl gap-8 self-center px-4 pb-12 pt-2">
        <View className="gap-1">
          <Text variant="item">
            {delivery ? 'Delivery' : 'Pickup'} · {describeWhen(scheduledFor, current, timeZone)}
          </Text>
          <View className="flex-row flex-wrap items-center gap-x-2">
            <Text className="text-muted-foreground">
              {count} {count === 1 ? 'item' : 'items'}
              {delivery && postcode ? ` · to ${postcode}` : ''}
            </Text>
            <Link href="/cart" asChild>
              <Button variant="link" className="h-11 px-0">
                <Text>Change</Text>
              </Button>
            </Link>
          </View>
        </View>

        <Section title="Your details">
          {user ? null : (
            <Text className="text-muted-foreground">
              Have an account?{' '}
              <Link href="/account" className="text-brand-text font-body-semibold underline">
                Sign in
              </Link>{' '}
              to use your saved details.
            </Text>
          )}
          <TextField
            control={form.control}
            name="name"
            label="Name"
            autoComplete="name"
            textContentType="name"
            returnKeyType="next"
            onSubmitEditing={() => form.setFocus('phone')}
          />
          <TextField
            control={form.control}
            name="phone"
            label="Mobile"
            hint="In case the restaurant needs to reach you about this order."
            autoComplete="tel"
            textContentType="telephoneNumber"
            keyboardType="phone-pad"
            returnKeyType="next"
            onSubmitEditing={() => form.setFocus('email')}
          />
          <TextField
            control={form.control}
            name="email"
            label="Email"
            hint="For your receipt."
            autoComplete="email"
            textContentType="emailAddress"
            keyboardType="email-address"
            autoCapitalize="none"
            autoCorrect={false}
          />
        </Section>

        {delivery ? (
          <Section title="Delivery address">
            {user && (addresses.data?.length ?? 0) > 0 ? (
              <View role="radiogroup" aria-label="Saved addresses">
                {addresses.data?.map((saved) => (
                  <ChoiceRow
                    key={saved.id}
                    kind="radio"
                    checked={savedAddressId === saved.id}
                    label={[saved.label, saved.line1, saved.suburb].filter(Boolean).join(', ')}
                    detail={saved.postcode}
                    onPress={() => chooseSavedAddress(saved)}
                  />
                ))}
                <ChoiceRow kind="radio" checked={savedAddressId === null} label="A new address" onPress={() => chooseSavedAddress(null)} />
              </View>
            ) : null}
            <TextField control={form.control} name="line1" label="Street address" autoComplete="address-line1" textContentType="streetAddressLine1" />
            <TextField
              control={form.control}
              name="line2"
              label="Apartment, unit or floor (optional)"
              autoComplete="address-line2"
              textContentType="streetAddressLine2"
            />
            <View className="flex-row gap-3">
              <View className="flex-1">
                <TextField control={form.control} name="suburb" label="Suburb" autoComplete="postal-address-locality" textContentType="addressCity" />
              </View>
              <View className="w-28">
                <TextField control={form.control} name="state" label="State" autoCapitalize="characters" autoComplete="postal-address-region" textContentType="addressState" />
              </View>
            </View>
            <Text className="text-muted-foreground">Postcode {postcode}, from your cart.</Text>
            <TextField
              control={form.control}
              name="instructions"
              label="Instructions for the driver (optional)"
              hint="For example, the gate code or where to leave it."
              multiline
            />
            {user && savedAddressId === null ? (
              <ChoiceRow kind="checkbox" checked={saveNewAddress} label="Save this address to my account" onPress={() => setSaveNewAddress(!saveNewAddress)} />
            ) : null}
          </Section>
        ) : null}

        <Section title="Anything else?">
          <TextField
            control={form.control}
            name="notes"
            label="Notes for the restaurant (optional)"
            hint="Notes for a dish go on that dish, from your cart."
            multiline
          />
        </Section>

        <Section title="Payment">
          {quote.isPending && request !== null ? (
            <LoadingState label="Working out your total" />
          ) : quote.isError && !current ? (
            <ErrorState
              title="We couldn’t work out your total"
              message={errorMessage(quote.error)}
              onRetry={() => void quote.refetch()}
              retrying={quote.isFetching}
            />
          ) : current ? (
            <OrderTotals
              subtotalCents={current.subtotal_cents}
              deliveryFeeCents={current.delivery_fee_cents}
              discountCents={current.discount_cents}
              totalCents={current.total_cents}
              gstCents={current.gst_cents}
              delivery={delivery}
              promoCode={current.promo_code?.code ?? null}
              currency={currency}
            />
          ) : null}

          {request === null ? <Problem message="Enter your delivery postcode in your cart." inCart /> : null}
          {current && !current.can_place_order ? (
            <Problem message={current.errors[0]?.message ?? 'This order can’t be placed yet.'} inCart />
          ) : null}
          {problem ? <Problem message={problem.message} inCart={problem.inCart} /> : null}

          {/* Stays mounted while the cart is re-priced, so card details aren't lost. */}
          {quote.data ? (
            <PaymentForm
              amountCents={quote.data.total_cents}
              currency={currency}
              merchantName={restaurant?.name ?? 'Restaurant'}
              disabled={!ready || placeOrder.isPending}
              validate={validate}
              createPayment={createPayment}
              onPaid={onPaid}
              onError={(message) => setProblem({ message, inCart: false })}
            />
          ) : null}
          <Text variant="muted">
            Payments are handled by Stripe. We never see your card details.
          </Text>
        </Section>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <View className="gap-4">
      <Text variant="heading">{title}</Text>
      {children}
    </View>
  );
}

function Problem({ message, inCart }: { message: string; inCart: boolean }) {
  return (
    <Alert icon={CircleAlert} variant="destructive">
      <AlertDescription>{message}</AlertDescription>
      {inCart ? (
        <Link href="/cart" asChild>
          <Button variant="link" className="ml-6 h-11 self-start px-0">
            <Text>Back to your cart</Text>
          </Button>
        </Link>
      ) : null}
    </Alert>
  );
}

/** "as soon as possible (about 25 min)" or "tomorrow at 6:30 pm". */
function describeWhen(scheduledFor: string | null, quote: Quote | null, timeZone: string): string {
  if (scheduledFor) {
    const day = describeDay(scheduledFor, timeZone);

    return `${day === 'today' ? 'today' : day} at ${formatTime(scheduledFor, timeZone)}`;
  }

  return quote ? `as soon as possible (about ${quote.fulfilment.estimated_minutes} min)` : 'as soon as possible';
}
