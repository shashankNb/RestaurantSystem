import { useState } from 'react';
import { ScrollView, View } from 'react-native';

import { ChoiceChip } from '@/components/choice-chip';
import { ChoiceRow } from '@/components/choice-row';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { errorMessage } from '@/lib/api/client';
import { useSlots } from '@/lib/api/ordering';
import type { FulfilmentType } from '@/lib/api/schemas';
import { describeDay, formatTime } from '@/lib/format';

/**
 * As soon as possible, or a time later: a day, then one of the quarter-hours the
 * restaurant offers that day. Times are shown in the restaurant's timezone.
 */
export function WhenPicker({
  fulfilment,
  postcode,
  scheduledFor,
  onChange,
  timeZone,
  error,
}: {
  fulfilment: FulfilmentType;
  postcode: string | null;
  scheduledFor: string | null;
  onChange: (scheduledFor: string | null) => void;
  timeZone: string;
  /** The quote's problem with the chosen time, if any. */
  error: string | undefined;
}) {
  const slots = useSlots(fulfilment, postcode);
  const [dayKey, setDayKey] = useState<string | null>(null);

  if (slots.isPending) {
    return (
      <View className="gap-3" role="progressbar" aria-label="Loading times">
        <Skeleton className="h-11 w-full" />
        <Skeleton className="h-11 w-full" />
      </View>
    );
  }

  if (slots.isError) {
    return (
      <View className="items-start gap-3">
        <Text className="text-muted-foreground">{errorMessage(slots.error)}</Text>
        <Button variant="outline" size="sm" onPress={() => void slots.refetch()} disabled={slots.isFetching}>
          <Text>Try again</Text>
        </Button>
      </View>
    );
  }

  const { asap, slots: times } = slots.data;
  const mode = scheduledFor !== null || !asap.available ? 'later' : 'asap';
  const days = groupByDay(times, timeZone);
  const day = days.find((candidate) => candidate.key === (dayKey ?? (scheduledFor ? localDate(scheduledFor, timeZone) : null))) ?? days[0];

  return (
    <View className="gap-3">
      <View role="radiogroup" aria-label={fulfilment === 'pickup' ? 'Pickup time' : 'Delivery time'}>
        <ChoiceRow
          kind="radio"
          checked={mode === 'asap'}
          disabled={!asap.available}
          label="As soon as possible"
          detail={asap.available ? `About ${asap.estimated_minutes} min` : 'Not available now'}
          onPress={() => onChange(null)}
        />
        <ChoiceRow
          kind="radio"
          checked={mode === 'later'}
          disabled={times.length === 0}
          label={fulfilment === 'pickup' ? 'Pick up later' : 'Deliver later'}
          detail={times.length === 0 ? 'No times this week' : undefined}
          // The first time on offer, so what's shown is what gets ordered; then change it.
          onPress={() => {
            if (scheduledFor === null && times[0] !== undefined) {
              setDayKey(null);
              onChange(times[0]);
            }
          }}
        />
      </View>

      {mode === 'later' && day ? (
        <View className="gap-3">
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerClassName="gap-2" role="radiogroup" aria-label="Day">
            {days.map((candidate) => (
              <ChoiceChip key={candidate.key} label={candidate.label} selected={candidate.key === day.key} onPress={() => setDayKey(candidate.key)} />
            ))}
          </ScrollView>
          <View className="flex-row flex-wrap gap-2" role="radiogroup" aria-label={`Times, ${day.label}`}>
            {day.times.map((time) => (
              <ChoiceChip key={time} label={formatTime(time, timeZone)} selected={time === scheduledFor} onPress={() => onChange(time)} />
            ))}
          </View>
          <Text variant="muted">
            {scheduledFor === null
              ? 'Choose a time.'
              : `Chosen: ${days.find((candidate) => candidate.times.includes(scheduledFor))?.label ?? describeDay(scheduledFor, timeZone)} at ${formatTime(scheduledFor, timeZone)}`}
          </Text>
        </View>
      ) : null}

      {error ? (
        <Text role="alert" className="text-destructive text-sm">
          {error}
        </Text>
      ) : null}
    </View>
  );
}

/** "2026-10-03": the restaurant-local date of a moment. */
function localDate(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(iso));
}

/** Slots by restaurant-local day, labelled "Today", "Tomorrow", "Fri 3 Oct". */
function groupByDay(times: string[], timeZone: string): { key: string; label: string; times: string[] }[] {
  const days: { key: string; label: string; times: string[] }[] = [];

  for (const time of times) {
    const key = localDate(time, timeZone);
    const last = days[days.length - 1];

    if (last?.key === key) {
      last.times.push(time);
    } else {
      const relative = describeDay(time, timeZone);
      const label =
        relative === 'today' || relative === 'tomorrow'
          ? relative[0].toUpperCase() + relative.slice(1)
          : new Intl.DateTimeFormat('en-AU', { weekday: 'short', day: 'numeric', month: 'short', timeZone })
              .format(new Date(time))
              .replace(',', '');
      days.push({ key, label, times: [time] });
    }
  }

  return days;
}
