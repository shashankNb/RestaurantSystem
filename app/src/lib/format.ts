import type { Restaurant } from '@/lib/api/schemas';

/**
 * "5 pm" or "10:30 pm": a moment shown in the restaurant's own timezone, however the
 * device is set.
 */
export function formatTime(iso: string, timeZone: string): string {
  const formatted = new Intl.DateTimeFormat('en-AU', {
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
    timeZone,
  }).format(new Date(iso));

  // ICU separates "pm" with a narrow no-break space; drop ":00" on the hour.
  return formatted.replace(/\s+/g, ' ').replace(':00 ', ' ').toLowerCase();
}

/** "today", "tomorrow" or a weekday ("Friday"), in the restaurant's timezone. */
export function describeDay(iso: string, timeZone: string, now: Date = new Date()): string {
  const localDate = (date: Date) =>
    new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
  const target = localDate(new Date(iso));

  if (target === localDate(now)) {
    return 'today';
  }

  if (target === localDate(new Date(now.getTime() + 24 * 60 * 60 * 1000))) {
    return 'tomorrow';
  }

  return new Intl.DateTimeFormat('en-AU', { weekday: 'long', timeZone }).format(new Date(iso));
}

/** "17:00" → "5 pm", "21:30" → "9:30 pm": a restaurant-local wall-clock time. */
export function formatClockTime(time: string): string {
  const [hours = 0, minutes = 0] = time.split(':').map(Number);
  const period = hours < 12 ? 'am' : 'pm';
  const hour = hours % 12 === 0 ? 12 : hours % 12;

  return minutes === 0 ? `${hour} ${period}` : `${hour}:${String(minutes).padStart(2, '0')} ${period}`;
}

/** "2026-12-25" → "Friday 25 December". */
export function formatDate(date: string): string {
  return new Intl.DateTimeFormat('en-AU', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
  })
    .format(new Date(`${date}T00:00:00Z`))
    .replace(',', '');
}

/** The restaurant-local day of the week right now, 0 = Sunday. */
export function localDayOfWeek(timeZone: string, now: Date = new Date()): number {
  const weekday = new Intl.DateTimeFormat('en-US', { weekday: 'short', timeZone }).format(now);

  return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(weekday);
}

export type OpeningTone = 'open' | 'paused' | 'closed';

/**
 * The status line under the restaurant's name, e.g. "Open now · until 10 pm" or
 * "Closed · opens tomorrow at 5 pm".
 */
export function describeOpening(
  restaurant: Pick<Restaurant, 'status' | 'timezone'>,
  now: Date = new Date(),
): { tone: OpeningTone; label: string; detail: string | null } {
  const { status, timezone } = restaurant;

  if (status.is_open && status.is_accepting_orders) {
    return {
      tone: 'open',
      label: 'Open now',
      detail: status.closes_at ? `until ${formatTime(status.closes_at, timezone)}` : null,
    };
  }

  if (status.is_open) {
    return { tone: 'paused', label: 'Not taking online orders right now', detail: null };
  }

  if (status.next_opening_at) {
    return {
      tone: 'closed',
      label: 'Closed',
      detail: `opens ${describeDay(status.next_opening_at, timezone, now)} at ${formatTime(status.next_opening_at, timezone)}`,
    };
  }

  return { tone: 'closed', label: 'Closed', detail: null };
}
