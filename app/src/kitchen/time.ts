import { describeDay, formatTime } from '@/lib/format';

/** "just now", "4 min ago", "1 h 5 min ago". */
export function ago(iso: string, now: number): string {
  const minutes = Math.floor((now - new Date(iso).getTime()) / 60_000);

  if (minutes < 1) {
    return 'just now';
  }

  return minutes < 60 ? `${minutes} min ago` : `${Math.floor(minutes / 60)} h ${minutes % 60} min ago`;
}

/** Whole minutes from now until a moment, rounded up; zero or less once it has passed. */
export function minutesUntil(iso: string, now: number): number {
  return Math.ceil((new Date(iso).getTime() - now) / 60_000);
}

/** "6:30 pm" today, "tomorrow 6:30 pm", "Friday 6:30 pm", in the restaurant's timezone. */
export function at(iso: string, timeZone: string, now: number): string {
  const day = describeDay(iso, timeZone, new Date(now));

  return day === 'today' ? formatTime(iso, timeZone) : `${day} ${formatTime(iso, timeZone)}`;
}
