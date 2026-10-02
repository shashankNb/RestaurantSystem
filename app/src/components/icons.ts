import type { LucideIcon, LucideProps } from 'lucide-react-native';
import { createElement, forwardRef, type ComponentType } from 'react';
import Svg, { Circle, Line, Path } from 'react-native-svg';

/*
 * The app's icons, from Lucide (https://lucide.dev, ISC licence), built the way
 * lucide-react-native builds them. Importing from lucide-react-native itself puts all of
 * its 1,600 icons in the web bundle (1.1 MB of 4.8 MB), so the few the app uses are here.
 *
 * To add one, copy its shapes from node_modules/lucide-react-native/dist/esm/icons/<name>.js.
 */

type Shape = ['path' | 'circle' | 'line', Record<string, string>];

// react-native-svg types each shape's own props; these get the shape's attributes as written.
const ELEMENTS = { path: Path, circle: Circle, line: Line } as unknown as Record<Shape[0], ComponentType<object>>;

const SHAPE_DEFAULTS = { fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' } as const;

function icon(name: string, shapes: Shape[]): LucideIcon {
  const Icon = forwardRef<Svg, LucideProps>(
    ({ color = 'currentColor', size = 24, strokeWidth = 2, absoluteStrokeWidth, children, ...rest }, ref) => {
      const attributes = {
        stroke: color,
        strokeWidth: absoluteStrokeWidth ? (Number(strokeWidth) * 24) / Number(size) : strokeWidth,
        ...rest,
      };

      return createElement(
        Svg,
        { ref, ...SHAPE_DEFAULTS, width: size, height: size, viewBox: '0 0 24 24', ...attributes },
        ...shapes.map(([element, shape]) => createElement(ELEMENTS[element], { ...SHAPE_DEFAULTS, ...attributes, ...shape })),
        children,
      );
    },
  );

  Icon.displayName = name;

  return Icon;
}

export const Bell = icon('Bell', [
  ['path', { d: 'M10.268 21a2 2 0 0 0 3.464 0', key: 'vwvbt9' }],
  [
    'path',
    {
      d: 'M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326',
      key: '11g9vi',
    },
  ],
]);

export const Check = icon('Check', [['path', { d: 'M20 6 9 17l-5-5', key: '1gmf2c' }]]);

export const ChevronLeft = icon('ChevronLeft', [['path', { d: 'm15 18-6-6 6-6', key: '1wnfg3' }]]);

export const ChevronRight = icon('ChevronRight', [['path', { d: 'm9 18 6-6-6-6', key: 'mthhwq' }]]);

export const CircleAlert = icon('CircleAlert', [
  ['circle', { cx: '12', cy: '12', r: '10', key: '1mglay' }],
  ['line', { x1: '12', x2: '12', y1: '8', y2: '12', key: '1pkeuh' }],
  ['line', { x1: '12', x2: '12.01', y1: '16', y2: '16', key: '4dfq90' }],
]);

export const CircleCheck = icon('CircleCheck', [
  ['circle', { cx: '12', cy: '12', r: '10', key: '1mglay' }],
  ['path', { d: 'm9 12 2 2 4-4', key: 'dzmm74' }],
]);

export const Clock = icon('Clock', [
  ['path', { d: 'M12 6v6l4 2', key: 'mmk7yg' }],
  ['circle', { cx: '12', cy: '12', r: '10', key: '1mglay' }],
]);

export const Minus = icon('Minus', [['path', { d: 'M5 12h14', key: '1ays0h' }]]);

export const Plus = icon('Plus', [
  ['path', { d: 'M5 12h14', key: '1ays0h' }],
  ['path', { d: 'M12 5v14', key: 's699le' }],
]);

export const ShoppingBag = icon('ShoppingBag', [
  ['path', { d: 'M16 10a4 4 0 0 1-8 0', key: '1ltviw' }],
  ['path', { d: 'M3.103 6.034h17.794', key: 'awc11p' }],
  [
    'path',
    {
      d: 'M3.4 5.467a2 2 0 0 0-.4 1.2V20a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6.667a2 2 0 0 0-.4-1.2l-2-2.667A2 2 0 0 0 17 2H7a2 2 0 0 0-1.6.8z',
      key: 'o988cm',
    },
  ],
]);

export const Trash2 = icon('Trash2', [
  ['path', { d: 'M10 11v6', key: 'nco0om' }],
  ['path', { d: 'M14 11v6', key: 'outv1u' }],
  ['path', { d: 'M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', key: 'miytrc' }],
  ['path', { d: 'M3 6h18', key: 'd0wm0j' }],
  ['path', { d: 'M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2', key: 'e791ji' }],
]);

export const UserRound = icon('UserRound', [
  ['circle', { cx: '12', cy: '8', r: '5', key: '1hypcn' }],
  ['path', { d: 'M20 21a8 8 0 0 0-16 0', key: 'rfgkzh' }],
]);

export const X = icon('X', [
  ['path', { d: 'M18 6 6 18', key: '1bl5f8' }],
  ['path', { d: 'm6 6 12 12', key: 'd8bk6v' }],
]);
