import { View } from 'react-native';

import { Text } from '@/components/ui/text';
import type { Restaurant } from '@/lib/api/schemas';
import { formatClockTime, formatDate, localDayOfWeek } from '@/lib/format';
import { cn } from '@/lib/utils';

const DAYS = [
  [1, 'Monday'],
  [2, 'Tuesday'],
  [3, 'Wednesday'],
  [4, 'Thursday'],
  [5, 'Friday'],
  [6, 'Saturday'],
  [0, 'Sunday'],
] as const;

type Hours = Pick<Restaurant, 'opening_hours' | 'special_hours' | 'timezone'>;

/** The weekly hours, Monday first, with today in bold and any upcoming changes below. */
export function OpeningHours({ restaurant }: { restaurant: Hours }) {
  const today = localDayOfWeek(restaurant.timezone);

  return (
    <View className="w-full max-w-md gap-3 px-4 py-6">
      <Text variant="heading">Opening hours</Text>

      <View>
        {DAYS.map(([day, name]) => {
          const shifts = restaurant.opening_hours.filter((hours) => hours.day_of_week === day);
          const isToday = day === today;

          return (
            <View
              key={day}
              className="border-border flex-row justify-between gap-4 border-b py-2.5"
              accessible
              aria-label={`${name}${isToday ? ' (today)' : ''}: ${describeShifts(shifts)}`}
            >
              <Text className={cn(isToday && 'font-body-semibold')}>
                {name}
                {isToday ? ' (today)' : ''}
              </Text>
              <Text className={cn('text-right', isToday && 'font-body-semibold', shifts.length === 0 && 'text-muted-foreground')}>
                {describeShifts(shifts)}
              </Text>
            </View>
          );
        })}
      </View>

      {restaurant.special_hours.length > 0 ? (
        <View className="gap-1 pt-2">
          <Text variant="item">Changes coming up</Text>
          {restaurant.special_hours.map((day) => (
            <Text key={day.date}>
              {formatDate(day.date)}:{' '}
              {day.is_closed || !day.opens_at || !day.closes_at
                ? 'closed'
                : `${formatClockTime(day.opens_at)} – ${formatClockTime(day.closes_at)}`}
              {day.note ? <Text className="text-muted-foreground"> · {day.note}</Text> : null}
            </Text>
          ))}
        </View>
      ) : null}
    </View>
  );
}

function describeShifts(shifts: { opens_at: string; closes_at: string }[]): string {
  if (shifts.length === 0) {
    return 'Closed';
  }

  return shifts.map((shift) => `${formatClockTime(shift.opens_at)} – ${formatClockTime(shift.closes_at)}`).join(', ');
}
