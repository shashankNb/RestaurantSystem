import { router, useLocalSearchParams } from 'expo-router';
import type { Metadata } from 'expo-router/server';
import { useId, useState, type ReactNode } from 'react';
import { ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useCart } from '@/cart/cart-store';
import { CartLineRow } from '@/components/cart-line-row';
import { CircleAlert } from '@/components/icons';
import { OrderTotals } from '@/components/order-totals';
import { PageHead } from '@/components/page-head';
import { PromoCodeField } from '@/components/promo-code-field';
import { Screen } from '@/components/screen';
import { ScreenHeader } from '@/components/screen-header';
import { SegmentedControl } from '@/components/segmented-control';
import { EmptyState, ErrorState } from '@/components/states';
import { TablePicker } from '@/components/table-picker';
import { WhenPicker } from '@/components/when-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { errorMessage } from '@/lib/api/client';
import { cartRequest, useQuote } from '@/lib/api/ordering';
import { useRestaurant } from '@/lib/api/restaurant';
import type { DeliveryZone, FulfilmentType, Quote, QuoteError } from '@/lib/api/schemas';
import { fulfilmentLabel, fulfilmentOptions } from '@/lib/fulfilment';
import { formatMoney } from '@/lib/money';
import { privatePage, toMetadata } from '@/lib/page-meta';

/** Quote errors shown next to their own field rather than in the list at the bottom. */
const FIELD_ERRORS = ['postcode', 'table', 'scheduled_for', 'promo_code'];

const PAGE = privatePage('Your cart');

/** Web: the page's title in the server's HTML. It stays out of search results. */
export function generateMetadata(): Metadata {
  return toMetadata(PAGE);
}

export default function CartPage() {
  return (
    <>
      <PageHead page={PAGE} />
      <CartScreen />
    </>
  );
}

/**
 * The cart: lines to change or remove, pickup or delivery, when, a promo code and the
 * server's prices. "Check out" is available once the quote says the order can be placed.
 */
function CartScreen() {
  const lines = useCart((state) => state.lines);
  const fulfilment = useCart((state) => state.fulfilment);
  const postcode = useCart((state) => state.postcode);
  const scheduledFor = useCart((state) => state.scheduledFor);
  const promoCode = useCart((state) => state.promoCode);
  const table = useCart((state) => state.table);
  const { setQuantity, remove, setFulfilment, setPostcode, setScheduledFor, setPromoCode, setTable } = useCart.getState();
  const { data: restaurant } = useRestaurant();
  const insets = useSafeAreaInsets();
  // From "Order again": dishes that couldn't go back in the cart.
  const { skipped } = useLocalSearchParams<{ skipped?: string }>();

  // Delivery can't be priced until there's a postcode, nor dine in until there's a table.
  const needsPostcode = fulfilment === 'delivery' && postcode === null;
  const needsTable = fulfilment === 'dine_in' && table === null;
  const request =
    lines.length > 0 && !needsPostcode && !needsTable
      ? cartRequest({ lines, fulfilment, postcode, table, scheduledFor, promoCode })
      : null;
  const quote = useQuote(request);

  if (lines.length === 0) {
    return (
      <Screen>
        <ScreenHeader title="Your cart" />
        <EmptyState
          title="Your cart is empty"
          message="Add something from the menu to start an order."
          action={
            <Button variant="outline" onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))}>
              <Text>Back to the menu</Text>
            </Button>
          }
        />
      </Screen>
    );
  }

  const currency = restaurant?.currency ?? 'AUD';
  const timeZone = restaurant?.timezone ?? 'Australia/Melbourne';
  // The previous quote stays on screen while the new one loads, but its lines may not
  // match the cart any more.
  const current = quote.data !== undefined && !quote.isPlaceholderData ? quote.data : null;
  const errors = quote.data?.errors ?? [];
  const fieldError = (field: string) => errors.find((error) => error.field === field)?.message;
  const otherErrors = errors.filter((error) => !FIELD_ERRORS.includes(error.field ?? '') && !isLineError(error));
  const canCheckOut = current?.can_place_order === true;
  const options = fulfilmentOptions(restaurant);

  return (
    <Screen>
      <ScreenHeader title="Your cart" />
      <ScrollView
        className="flex-1"
        contentContainerClassName="w-full max-w-2xl gap-8 self-center px-4 pb-8"
        keyboardShouldPersistTaps="handled"
        automaticallyAdjustKeyboardInsets
      >
        <View>
          {skipped ? (
            <Alert icon={CircleAlert} className="mb-2">
              <AlertDescription className="text-foreground">
                {skipped === '1' ? '1 dish' : `${skipped} dishes`} from that order {skipped === '1' ? 'isn’t' : 'aren’t'} on the menu
                right now, so {skipped === '1' ? 'it’s' : 'they’re'} not in your cart.
              </AlertDescription>
            </Alert>
          ) : null}
          {lines.map((line, index) => (
            <CartLineRow
              key={line.id}
              line={line}
              lineTotalCents={current?.lines[index]?.line_total_cents}
              problems={current?.lines[index]?.errors ?? []}
              currency={currency}
              onQuantity={(quantity) => setQuantity(line.id, quantity)}
              onRemove={() => remove(line.id)}
            />
          ))}
          <Button variant="link" className="h-11 self-start px-0" onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))}>
            <Text>Add more from the menu</Text>
          </Button>
        </View>

        <Section title={options.length > 1 ? 'How would you like it?' : fulfilmentLabel(fulfilment)}>
          {options.length > 1 ? (
            <SegmentedControl label="How would you like your order?" value={fulfilment} onChange={setFulfilment} options={options} />
          ) : null}
          {fulfilment === 'delivery' ? (
            <PostcodeField
              postcode={postcode}
              onChange={setPostcode}
              error={fieldError('postcode')}
              zone={current?.fulfilment.delivery_zone ?? null}
              currency={currency}
            />
          ) : fulfilment === 'dine_in' ? (
            <TablePicker
              tables={restaurant?.fulfilment.dine_in.tables ?? []}
              value={table}
              onChange={setTable}
              error={fieldError('table')}
            />
          ) : (
            <Text className="text-muted-foreground">
              {restaurant?.address?.line1
                ? `Collect from ${[restaurant.address.line1, restaurant.address.suburb].filter(Boolean).join(', ')}.`
                : 'Collect from the restaurant.'}
            </Text>
          )}
        </Section>

        {/* At a table it's always as soon as possible. */}
        {fulfilment === 'dine_in' ? (
          fieldError('scheduled_for') ? (
            <Text role="alert" className="text-destructive">
              {fieldError('scheduled_for')}
            </Text>
          ) : null
        ) : (
          <Section title={fulfilment === 'pickup' ? 'Pickup time' : 'Delivery time'}>
            <WhenPicker
              fulfilment={fulfilment}
              postcode={postcode}
              scheduledFor={scheduledFor}
              onChange={setScheduledFor}
              timeZone={timeZone}
              error={fieldError('scheduled_for')}
            />
          </Section>
        )}

        <PromoCodeField
          code={promoCode}
          applied={current?.promo_code?.description ?? null}
          error={fieldError('promo_code')}
          onApply={setPromoCode}
          onRemove={() => setPromoCode(null)}
        />

        <Section title="Summary">
          <Summary
            quote={quote.data}
            loading={quote.isPending && request !== null}
            error={quote.isError ? errorMessage(quote.error) : null}
            onRetry={() => void quote.refetch()}
            retrying={quote.isFetching}
            needsPostcode={needsPostcode}
            needsTable={needsTable}
            fulfilment={fulfilment}
            currency={currency}
          />
          {otherErrors.length > 0 ? <Problems errors={otherErrors} /> : null}
        </Section>
      </ScrollView>

      <View className="bg-background border-border border-t px-4 pt-3" style={{ paddingBottom: Math.max(insets.bottom, 12) }}>
        <Button size="lg" className="w-full max-w-2xl self-center" disabled={!canCheckOut} onPress={() => router.push('/checkout')}>
          <Text>{current ? `Check out · ${formatMoney(current.total_cents, currency)}` : 'Check out'}</Text>
        </Button>
      </View>
    </Screen>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <View className="gap-3">
      <Text variant="heading">{title}</Text>
      {children}
    </View>
  );
}

/** Delivery postcode. The cart is priced as soon as it has 4 digits. */
function PostcodeField({
  postcode,
  onChange,
  error,
  zone,
  currency,
}: {
  postcode: string | null;
  onChange: (postcode: string | null) => void;
  error: string | undefined;
  zone: DeliveryZone | null;
  currency: string;
}) {
  const [value, setValue] = useState(postcode ?? '');
  const [shown, setShown] = useState(postcode);
  const labelId = useId();

  // Set elsewhere (a saved address at checkout): show it. Typing clears it until 4 digits.
  if (postcode !== shown) {
    setShown(postcode);

    if (postcode !== null) {
      setValue(postcode);
    }
  }

  return (
    <View className="gap-1.5">
      <Label nativeID={labelId}>Delivery postcode</Label>
      <Input
        className="w-36"
        value={value}
        onChangeText={(text) => {
          const digits = text.replace(/\D/g, '').slice(0, 4);
          setValue(digits);
          onChange(digits.length === 4 ? digits : null);
        }}
        keyboardType="number-pad"
        maxLength={4}
        autoComplete="postal-code"
        textContentType="postalCode"
        aria-labelledby={labelId}
        accessibilityLabel="Delivery postcode"
        invalid={error !== undefined}
      />
      {error ? (
        <Text role="alert" className="text-destructive text-sm">
          {error}
        </Text>
      ) : zone ? (
        <Text variant="muted">
          {zone.name}: {zone.fee_cents === 0 ? 'free delivery' : `${formatMoney(zone.fee_cents, currency)} delivery`}
          {zone.min_order_cents > 0 ? `, minimum order ${formatMoney(zone.min_order_cents, currency)}` : ''}.
        </Text>
      ) : (
        <Text variant="muted">We’ll check we deliver to you.</Text>
      )}
    </View>
  );
}

function Summary({
  quote,
  loading,
  error,
  onRetry,
  retrying,
  needsPostcode,
  needsTable,
  fulfilment,
  currency,
}: {
  quote: Quote | undefined;
  loading: boolean;
  error: string | null;
  onRetry: () => void;
  retrying: boolean;
  needsPostcode: boolean;
  needsTable: boolean;
  fulfilment: FulfilmentType;
  currency: string;
}) {
  if (needsPostcode) {
    return <Text className="text-muted-foreground">Enter your postcode to see the delivery fee and total.</Text>;
  }

  if (needsTable) {
    return <Text className="text-muted-foreground">Choose your table to see your total.</Text>;
  }

  if (loading) {
    return (
      <View className="gap-2" role="progressbar" aria-label="Working out your total">
        <Skeleton className="h-5 w-full" />
        <Skeleton className="h-5 w-full" />
        <Skeleton className="h-6 w-full" />
      </View>
    );
  }

  if (error !== null || quote === undefined) {
    return <ErrorState title="We couldn’t work out your total" message={error ?? ''} onRetry={onRetry} retrying={retrying} />;
  }

  return (
    <OrderTotals
      subtotalCents={quote.subtotal_cents}
      deliveryFeeCents={quote.delivery_fee_cents}
      discountCents={quote.discount_cents}
      totalCents={quote.total_cents}
      gstCents={quote.gst_cents}
      delivery={fulfilment === 'delivery'}
      promoCode={quote.promo_code?.code ?? null}
      currency={currency}
    />
  );
}

function Problems({ errors }: { errors: QuoteError[] }) {
  return (
    <Alert icon={CircleAlert} variant="destructive">
      {errors.map((error) => (
        <AlertDescription key={`${error.code}:${error.field ?? ''}`}>{error.message}</AlertDescription>
      ))}
    </Alert>
  );
}

/** A problem with one line ("items.2.modifier_option_ids"); shown under that line. */
function isLineError(error: QuoteError): boolean {
  return /^items\.\d+\./.test(error.field ?? '');
}
