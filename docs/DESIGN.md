# Design

The approved design for the customer app, website and kitchen screens (approved in
phase 4). The tokens live in `app/src/global.css`; this file explains them.

The idea: the food photography is the loud part, and everything around it reads like the
printed menu on the counter. That means steam-white paper, wok-black type, and the
restaurant's own colour for the things you tap.

## Colours

Six named colours. Dhaka maroon comes from the restaurant's settings; the other five are
fixed, so every restaurant on the platform shares the same calm base.

| Name | Light | Dark | Used for |
|---|---|---|---|
| Dhaka maroon | `#7A1F2B` | fills `#B72F41`, text `#E18793` | The restaurant's name, primary buttons, the active category. Nothing else, so it always means "tap this". |
| Steam | `#FCFBF8` page | `#F2EDE6` text | The page in light mode, text in dark mode. |
| Wok iron | `#1E1714` text | `#1A1411` page | Text in light mode, the page in dark mode. Hairlines (`#DDDBD8` / `#3D3733`), fills (`#EFEDEA` / `#27211E`) and secondary text (`#6E6966` / `#A9A39E`) are mixes of Wok iron and Steam. |
| Marigold | `#E4A11B` | `#E9AE4A` | Needs attention: new orders on the kitchen board, an applied promo code, the offline banner. Always a fill behind Wok-iron text. |
| Coriander | `#2F6B3F` | `#86C595` | Good news: open now, paid, ready. |
| Achar | `#B23A1B` | `#F39274` | Problems: errors, closed, "Reject order". Kept orange-red so an error never looks like a maroon button. |

Where the names come from: hand-woven dhaka cloth, steam off a basket of momos, a
seasoned cast-iron wok, the marigold garlands of Tihar, coriander on the achar, and momo
achar itself.

### The brand colour

An owner can change the brand colour in the back office. The app then derives its
variants itself (`app/src/theme/brand.ts`), keeping the hue and moving only the lightness,
only as far as needed:

- **Light mode:** darkened until a Steam label on it reaches 4.5:1. `#7A1F2B` is already
  well past that.
- **Dark mode:** buttons are lightened until they stand out from the page at 3:1 and a label
  (Steam or Wok iron) reads on them at 4.5:1. Text is lightened until it reaches 7:1, because
  thin pink-on-black text reads poorly at the 4.5:1 minimum.

For the demo maroon that gives `#B72F41` and `#E18793` in dark mode. The design plan's
hand-picked values were `#BA2E41` and `#EFA3AE`, both slightly lighter. Every brand colour
passes: a sweep of 4,096 colours in both schemes found no pairing below its target.

### Contrast

Measured with the WCAG 2.2 formula. Text needs 4.5:1; buttons, borders and focus rings 3:1.

| Pairing | Light | Dark |
|---|---|---|
| Text on the page | 17.08 | 15.65 |
| Secondary text on the page | 5.24 | 7.31 |
| Secondary text on a fill | 4.64 | 6.37 |
| Label on a maroon button | 9.86 | 5.16 |
| Maroon button against the page | 9.86 | 3.03 |
| Maroon text and links | 9.86 | 7.02 |
| Text on Marigold | 7.92 | 9.22 |
| Coriander text | 6.16 | 9.06 |
| Achar text | 5.77 | 7.96 |
| Input border | 5.24 | 7.31 |

## Type

Two faces from Indian type foundries, both drawn for Devanagari as well as Latin, so a
Nepali dish name (मोमो, थुक्पा, झोल) can sit beside its English one. Both have tabular figures.

- **Eczar** (Rosetta) for display text only: the restaurant's name, category headings, the
  item title in the options sheet, and order numbers on kitchen tickets. Always large and sparing.
- **Mukta** (Ek Type) for everything else.

| Role | Face | Size / line height | Class |
|---|---|---|---|
| Display | Eczar 600 | 34 / 38 | `text-display` (Text `variant="display"`) |
| Heading | Eczar 600 | 24 / 30 | `text-heading` (`variant="heading"`) |
| Ticket number | Eczar 700 | 40 / 44 | `text-ticket` (`variant="ticket"`) |
| Item | Mukta 600 | 17 / 22 | `text-item` (`variant="item"`) |
| Body | Mukta 400 | 16 / 24 | `text-base` (default) |
| Small | Mukta 500 | 14 / 20 | `text-sm` (`variant="small"`) |
| Button | Mukta 600 | 16 / 20 | set by `Button` |

Each weight is its own font family (`font-body`, `font-body-medium`, `font-body-semibold`,
`font-body-bold`, `font-display`, `font-display-bold`), because Android can't select a
weight of a custom font. Don't combine them with `font-medium` or `font-semibold`. No
all-caps labels and no letter-spacing tricks.

## Layout

- **Phone (360–767 px):** one column. The hero photo and the restaurant's name are the single
  loud moment. Below them come the sticky category tabs and a menu of plain rows (name, a line
  of description, price, a square photo on the right), separated by hairlines. A "View cart"
  bar stays at the bottom once something is in the cart. Without a cover photo, the header is
  a band of the brand colour carrying the name.
- **From 1024 px:** categories on the left, the menu in the middle, the cart as a panel on the
  right (no cart bar).
- **Item options:** a bottom sheet on phones, a dialog on the web. Photo large at the top; each
  rule next to its group name ("Required · choose 1"); the button shows the running price
  ("Add to cart · $20.90").
- **Kitchen board (tablet landscape):** columns for New, Preparing, Ready and Out for delivery,
  with paper-docket tickets. A new order keeps a marigold edge and a repeating chime until
  someone accepts it.
- **Shape:** one 8 px radius for buttons, inputs and photos; 16 px for sheet corners. Hairlines
  instead of drop shadows. No gradients.

## Principles

1. **The food brings the colour.** Photography is the one loud thing. The interface stays
   Steam and Wok iron so the red of the achar and the gold of a fried momo do the work, and
   maroon is kept for what you tap.
2. **Read like a takeaway menu.** Rows, not cards: no card grids, shadows or badges stacked on
   photos.
3. **Say it once, plainly.** One name per action through the whole flow: Add to cart, View
   cart, Place order, Accept order. Rules sit next to what they govern. Errors say how to fix
   them.

## Accessibility

- Tap targets are at least 44 × 44 px; rows, options and buttons are 46–48 px tall.
- WCAG AA contrast in both schemes (above).
- On the web, every control shows a 2 px focus ring in the text colour with a 2 px gap; on
  the brand band the ring is Steam instead.
- With reduced motion on, skeletons hold still, sheets fade instead of sliding, and the
  kitchen alert keeps its sound but doesn't flash.
- Dark mode follows the device setting.
- Colour never carries meaning alone: sold out, closed, errors and order states are always
  written out.

## Photography

Real photos of the actual dishes: overhead or at 45°, warm natural light, on dark wood or
in the bamboo steamer, steam visible. Crop to 4:3 with the food filling most of the frame.
No white-background cut-outs and no stock photos. Until photos exist, rows simply have no
thumbnail and the header shows the brand band.
