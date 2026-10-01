import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import { X } from 'lucide-react-native';
import { useId, useRef, useState } from 'react';
import { AccessibilityInfo, Platform, Pressable, ScrollView, View } from 'react-native';
import { useReducedMotion } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useCart, type CartLine } from '@/cart/cart-store';
import { ChoiceRow } from '@/components/choice-row';
import { QuantityStepper } from '@/components/quantity-stepper';
import { SheetFrame, sheetBodyStyle } from '@/components/sheet-frame';
import { EmptyState, ErrorState, LoadingState } from '@/components/states';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Label } from '@/components/ui/label';
import { Text } from '@/components/ui/text';
import { Textarea } from '@/components/ui/textarea';
import { errorMessage } from '@/lib/api/client';
import { findMenuItem, useMenu } from '@/lib/api/menu';
import { useRestaurant } from '@/lib/api/restaurant';
import type { MenuItem, ModifierGroup } from '@/lib/api/schemas';
import { formatMoney, formatPriceDelta } from '@/lib/money';
import { cn } from '@/lib/utils';

const NOTES_MAX = 200;

/**
 * A dish's options: choices, quantity and a note for the kitchen, then "Add to cart".
 * Opened from the menu, or from the cart with ?line= to change a line already in it.
 */
export default function ItemScreen() {
  const params = useLocalSearchParams<{ id: string; line?: string }>();
  const menu = useMenu();
  const { data: restaurant } = useRestaurant();
  const item = findMenuItem(menu.data, Number(params.id));
  const line = useCart((state) => state.lines.find((candidate) => candidate.id === params.line));
  const titleId = useId();

  const close = () => (router.canGoBack() ? router.back() : router.replace('/'));

  let content;

  if (menu.isPending) {
    content = <LoadingState label="Loading the menu" />;
  } else if (menu.isError) {
    content = (
      <ErrorState
        title="We couldn’t load this dish"
        message={errorMessage(menu.error)}
        onRetry={() => void menu.refetch()}
        retrying={menu.isFetching}
      />
    );
  } else if (item === undefined) {
    content = (
      <EmptyState
        title="This dish isn’t on the menu any more"
        message="Choose something else from the menu."
        action={
          <Button variant="outline" onPress={close}>
            <Text>Back to the menu</Text>
          </Button>
        }
      />
    );
  } else {
    content = (
      <ItemOptions
        key={`${item.id}:${line?.id ?? 'new'}`}
        item={item}
        line={line}
        currency={restaurant?.currency ?? 'AUD'}
        titleId={titleId}
        onClose={close}
      />
    );
  }

  return (
    <SheetFrame onClose={close} labelledBy={item ? titleId : undefined}>
      {content}
    </SheetFrame>
  );
}

function ItemOptions({
  item,
  line,
  currency,
  titleId,
  onClose,
}: {
  item: MenuItem;
  line: CartLine | undefined;
  currency: string;
  titleId: string;
  onClose: () => void;
}) {
  const add = useCart((state) => state.add);
  const replace = useCart((state) => state.replace);
  const insets = useSafeAreaInsets();
  const reduceMotion = useReducedMotion();
  const scrollRef = useRef<ScrollView>(null);
  const groupY = useRef(new Map<number, number>());
  const notesLabelId = useId();
  const [choices, setChoices] = useState(() => initialChoices(item, line?.optionIds ?? []));
  const [quantity, setQuantity] = useState(line?.quantity ?? 1);
  const [notes, setNotes] = useState(line?.notes ?? '');
  const [showProblems, setShowProblems] = useState(false);

  const soldOut = !item.is_available;
  const problems = groupProblems(item, choices);
  const chosen = item.modifier_groups.flatMap((group) => group.options.filter((option) => choices[group.id]?.includes(option.id)));
  const unitPriceCents = item.price_cents + chosen.reduce((sum, option) => sum + option.price_delta_cents, 0);

  const submit = () => {
    const firstProblem = item.modifier_groups.find((group) => problems.has(group.id));

    if (firstProblem) {
      setShowProblems(true);
      const y = groupY.current.get(firstProblem.id);

      if (y !== undefined) {
        scrollRef.current?.scrollTo({ y: Math.max(0, y - 8), animated: !reduceMotion });
      }

      AccessibilityInfo.announceForAccessibility(`${firstProblem.name}: ${problems.get(firstProblem.id)}`);

      return;
    }

    const trimmed = notes.trim();
    const next = {
      menuItemId: item.id,
      name: item.name,
      unitPriceCents,
      quantity,
      optionIds: chosen.map((option) => option.id),
      optionSummary: chosen.map((option) => option.name).join(', '),
      notes: trimmed === '' ? null : trimmed,
    };

    if (line) {
      replace(line.id, next);
      AccessibilityInfo.announceForAccessibility('Cart updated');
    } else {
      add(next);
      AccessibilityInfo.announceForAccessibility(`Added ${item.name} to your cart`);
    }

    onClose();
  };

  const allergens = item.allergens.map((allergen) => allergen.label.toLowerCase());

  return (
    <>
      <ScrollView
        ref={scrollRef}
        style={sheetBodyStyle}
        contentContainerClassName="pb-6"
        keyboardShouldPersistTaps="handled"
        automaticallyAdjustKeyboardInsets
      >
        {item.image_url ? (
          <Image
            source={{ uri: item.image_url }}
            style={{ width: '100%', aspectRatio: 16 / 9 }}
            contentFit="cover"
            transition={150}
            accessible={false}
          />
        ) : null}

        <View className="flex-row items-start gap-2 pl-4 pr-2 pt-4">
          <View className="flex-1 gap-1 pt-1">
            <Text variant="heading" nativeID={titleId}>
              {item.name}
            </Text>
            <Text className="font-body-semibold">{formatMoney(item.price_cents, currency)}</Text>
          </View>
          <Pressable
            role="button"
            aria-label="Close"
            onPress={onClose}
            className={cn(
              'size-11 items-center justify-center rounded-full active:bg-accent',
              Platform.select({ web: 'hover:bg-accent focus-visible:outline-ring outline-none focus-visible:outline-2' }),
            )}
          >
            <Icon as={X} className="size-6" />
          </Pressable>
        </View>

        <View className="gap-2 px-4 pt-2">
          {item.description ? <Text className="text-muted-foreground">{item.description}</Text> : null}
          {item.dietary_tags.length > 0 ? (
            <Text variant="small">{item.dietary_tags.map((tag) => tag.label).join(' · ')}</Text>
          ) : null}
          {allergens.length > 0 ? <Text variant="muted">Contains {listOf(allergens)}.</Text> : null}
          {soldOut ? <Text className="text-destructive font-body-semibold">Sold out for now.</Text> : null}
        </View>

        {item.modifier_groups.map((group) => (
          <OptionGroup
            key={group.id}
            group={group}
            selected={choices[group.id] ?? []}
            problem={showProblems ? problems.get(group.id) : undefined}
            currency={currency}
            onChange={(ids) => setChoices((current) => ({ ...current, [group.id]: ids }))}
            onLayout={(y) => groupY.current.set(group.id, y)}
          />
        ))}

        <View className="gap-1.5 px-4 pt-6">
          <Label nativeID={notesLabelId}>Notes for the kitchen (optional)</Label>
          <Textarea
            value={notes}
            onChangeText={setNotes}
            maxLength={NOTES_MAX}
            placeholder="For example, no coriander"
            aria-labelledby={notesLabelId}
            accessibilityLabel="Notes for the kitchen (optional)"
          />
          <Text variant="muted">Up to {NOTES_MAX} characters. For allergies, call the restaurant before ordering.</Text>
        </View>
      </ScrollView>

      <View className="border-border flex-row items-center gap-3 border-t px-4 pt-3" style={{ paddingBottom: Math.max(insets.bottom, 12) }}>
        <QuantityStepper value={quantity} onChange={setQuantity} itemName={item.name} />
        <Button size="lg" className="flex-1 px-4" onPress={submit} disabled={soldOut}>
          <Text numberOfLines={1}>
            {soldOut ? 'Sold out' : `${line ? 'Update item' : 'Add to cart'} · ${formatMoney(unitPriceCents * quantity, currency)}`}
          </Text>
        </Button>
      </View>
    </>
  );
}

/**
 * One option group. Pick-one groups are radio buttons (with "None" when the group is
 * optional); pick-several groups are checkboxes that stop at the group's maximum.
 */
function OptionGroup({
  group,
  selected,
  problem,
  currency,
  onChange,
  onLayout,
}: {
  group: ModifierGroup;
  selected: number[];
  problem: string | undefined;
  currency: string;
  onChange: (ids: number[]) => void;
  onLayout: (y: number) => void;
}) {
  const titleId = useId();
  const single = group.max_select === 1;
  const full = !single && selected.length >= group.max_select;

  return (
    <View
      onLayout={(event) => onLayout(event.nativeEvent.layout.y)}
      role={single ? 'radiogroup' : 'group'}
      aria-labelledby={titleId}
      className="px-4 pt-6"
    >
      <View className="flex-row flex-wrap items-baseline justify-between gap-x-3 pb-1">
        <Text variant="item" nativeID={titleId}>
          {group.name}
        </Text>
        <Text
          role={problem ? 'alert' : undefined}
          className={cn('text-sm', problem ? 'text-destructive font-body-semibold' : 'text-muted-foreground')}
        >
          {problem ?? group.selection_rule}
        </Text>
      </View>

      {single && group.min_select === 0 ? (
        <ChoiceRow kind="radio" checked={selected.length === 0} label="None" onPress={() => onChange([])} />
      ) : null}

      {group.options.map((option) => {
        const checked = selected.includes(option.id);
        const soldOut = !option.is_available;

        return (
          <ChoiceRow
            key={option.id}
            kind={single ? 'radio' : 'checkbox'}
            checked={checked}
            // A sold-out option can still be unticked (when changing a cart line).
            disabled={checked ? soldOut && single : soldOut || full}
            label={option.name}
            detail={soldOut ? 'Sold out' : formatPriceDelta(option.price_delta_cents, currency)}
            onPress={() =>
              onChange(single ? [option.id] : checked ? selected.filter((id) => id !== option.id) : [...selected, option.id])
            }
          />
        );
      })}
    </View>
  );
}

/** The options already chosen (when changing a cart line), by group. */
function initialChoices(item: MenuItem, optionIds: number[]): Record<number, number[]> {
  return Object.fromEntries(
    item.modifier_groups.map((group) => [
      group.id,
      group.options.filter((option) => optionIds.includes(option.id)).map((option) => option.id),
    ]),
  );
}

/** What stops each group being valid, in the words shown next to it. */
function groupProblems(item: MenuItem, choices: Record<number, number[]>): Map<number, string> {
  const problems = new Map<number, string>();

  for (const group of item.modifier_groups) {
    const selected = group.options.filter((option) => choices[group.id]?.includes(option.id));
    const soldOut = selected.find((option) => !option.is_available);

    if (soldOut) {
      problems.set(group.id, `${soldOut.name} is sold out. Choose something else.`);
    } else if (selected.length < group.min_select) {
      problems.set(group.id, group.min_select === 1 ? 'Choose 1 option' : `Choose at least ${group.min_select}`);
    } else if (selected.length > group.max_select) {
      problems.set(group.id, `Choose up to ${group.max_select}`);
    }
  }

  return problems;
}

/** ["gluten", "soy", "sesame"] → "gluten, soy and sesame". */
function listOf(words: string[]): string {
  return words.length <= 1 ? (words[0] ?? '') : `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}`;
}
