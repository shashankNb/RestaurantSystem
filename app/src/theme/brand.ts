import { brand } from '@/lib/config';

/**
 * Turns a restaurant's brand colour (from its settings) into the "Dhaka maroon" tokens of
 * the design, for light and dark mode, keeping WCAG AA contrast whatever colour the owner
 * picks. The colour keeps its hue; only its lightness moves, and only as far as needed.
 *
 * - primary: button fills. Its label must reach 4.5:1, and in dark mode the fill must
 *   stand out from the page at 3:1.
 * - primaryForeground: the label colour on primary.
 * - brandText: the restaurant's name and links on the page, at 4.5:1 or better (7:1 in
 *   dark mode, where thin pink text on near-black reads poorly at the minimum).
 */

export type Scheme = 'light' | 'dark';

export interface BrandColors {
  primary: string;
  primaryForeground: string;
  brandText: string;
}

/** The fixed neutrals of the design: Steam and Wok iron, per scheme. */
export const PAGE = { light: '#FCFBF8', dark: '#1A1411' } as const;
export const INK = { light: '#1E1714', dark: '#F2EDE6' } as const;

/**
 * The brand colour until the restaurant's settings load: its brand's (brand.json), or the
 * design's maroon.
 */
export const DEFAULT_BRAND_COLOR = brand && /^#[0-9a-f]{6}$/i.test(brand.brandColor) ? brand.brandColor : '#7A1F2B';

const STEP = 0.005;

export function brandColors(brandColor: string, scheme: Scheme): BrandColors {
  const base = parseHex(brandColor) ?? parseHex(DEFAULT_BRAND_COLOR)!;
  const [hue, saturation, lightness] = rgbToHsl(base);
  const page = parseHex(PAGE[scheme])!;
  const at = (l: number): Rgb => hslToRgb([hue, saturation, clamp(l)]);

  if (scheme === 'light') {
    const pageText = parseHex(PAGE.light)!;
    // Darken until the Steam label reads on it (a dark fill always stands out from the page).
    const fill = walk(lightness, -STEP, at, (rgb) => contrast(pageText, rgb) >= 4.5);
    const text = walk(lightness, -STEP, at, (rgb) => contrast(rgb, page) >= 4.5);

    return { primary: toHex(fill), primaryForeground: PAGE.light, brandText: toHex(text) };
  }

  const ink = parseHex(INK.dark)!;
  // Lighten until the fill stands out from the dark page and one of the two labels
  // (Steam or Wok iron) reads on it; mid-tones need to go lighter for the dark label.
  const fill = walk(
    lightness,
    STEP,
    at,
    (rgb) => contrast(rgb, page) >= 3 && Math.max(contrast(ink, rgb), contrast(page, rgb)) >= 4.5,
  );
  const label = contrast(ink, fill) >= contrast(page, fill) ? INK.dark : PAGE.dark;
  const text = walk(lightness, STEP, at, (rgb) => contrast(rgb, page) >= 7);

  return { primary: toHex(fill), primaryForeground: label, brandText: toHex(text) };
}

/** CSS variables for NativeWind's VariableContextProvider. */
export function brandVariables(brandColor: string, scheme: Scheme): Record<`--${string}`, string> {
  const colors = brandColors(brandColor, scheme);

  return {
    '--color-primary': colors.primary,
    '--color-primary-foreground': colors.primaryForeground,
    '--color-brand-text': colors.brandText,
  };
}

/** WCAG 2.2 contrast ratio between two colours. */
export function contrast(a: Rgb, b: Rgb): number {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

  return (light + 0.05) / (dark + 0.05);
}

export type Rgb = [number, number, number];

export function parseHex(hex: string): Rgb | null {
  const match = /^#?([0-9a-f]{6})$/i.exec(hex.trim());

  if (!match) {
    return null;
  }

  const value = parseInt(match[1], 16);

  return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}

export function toHex([r, g, b]: Rgb): string {
  return `#${[r, g, b].map((channel) => channel.toString(16).padStart(2, '0')).join('').toUpperCase()}`;
}

/**
 * Steps lightness from `start` until `done` passes, stopping at black or white.
 */
function walk(start: number, step: number, at: (lightness: number) => Rgb, done: (rgb: Rgb) => boolean): Rgb {
  for (let l = start; l >= 0 && l <= 1; l += step) {
    const rgb = at(l);

    if (done(rgb)) {
      return rgb;
    }
  }

  return at(step < 0 ? 0 : 1);
}

function luminance([r, g, b]: Rgb): number {
  const linear = (channel: number) => {
    const c = channel / 255;

    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };

  return 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
}

function rgbToHsl([r, g, b]: Rgb): [number, number, number] {
  const [rn, gn, bn] = [r / 255, g / 255, b / 255];
  const max = Math.max(rn, gn, bn);
  const min = Math.min(rn, gn, bn);
  const lightness = (max + min) / 2;

  if (max === min) {
    return [0, 0, lightness];
  }

  const delta = max - min;
  const saturation = lightness > 0.5 ? delta / (2 - max - min) : delta / (max + min);
  const hue =
    max === rn ? ((gn - bn) / delta + (gn < bn ? 6 : 0)) / 6 : max === gn ? ((bn - rn) / delta + 2) / 6 : ((rn - gn) / delta + 4) / 6;

  return [hue, saturation, lightness];
}

function hslToRgb([hue, saturation, lightness]: [number, number, number]): Rgb {
  if (saturation === 0) {
    const grey = Math.round(lightness * 255);

    return [grey, grey, grey];
  }

  const q = lightness < 0.5 ? lightness * (1 + saturation) : lightness + saturation - lightness * saturation;
  const p = 2 * lightness - q;
  const channel = (t: number) => {
    const tt = t < 0 ? t + 1 : t > 1 ? t - 1 : t;

    if (tt < 1 / 6) return p + (q - p) * 6 * tt;
    if (tt < 1 / 2) return q;
    if (tt < 2 / 3) return p + (q - p) * (2 / 3 - tt) * 6;

    return p;
  };

  return [channel(hue + 1 / 3), channel(hue), channel(hue - 1 / 3)].map((c) => Math.round(c * 255)) as Rgb;
}

function clamp(value: number): number {
  return Math.min(1, Math.max(0, value));
}
