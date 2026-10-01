import { INK, PAGE, type Scheme } from '@/theme/brand';

/**
 * The design's fixed colours as values, for UI that CSS variables can't reach: Stripe's
 * payment forms. Keep in step with global.css.
 */
export const PALETTE: Record<
  Scheme,
  { page: string; card: string; ink: string; muted: string; border: string; input: string; destructive: string }
> = {
  light: {
    page: PAGE.light,
    card: PAGE.light,
    ink: INK.light,
    muted: '#6E6966',
    border: '#DDDBD8',
    input: '#6E6966',
    destructive: '#B23A1B',
  },
  dark: {
    page: PAGE.dark,
    card: '#27211E',
    ink: INK.dark,
    muted: '#A9A39E',
    border: '#3D3733',
    input: '#A9A39E',
    destructive: '#F39274',
  },
};
