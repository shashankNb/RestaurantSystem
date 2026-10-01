import { extendTailwindMerge } from "tailwind-merge";

/**
 * Joins class names, letting later classes win conflicts.
 *
 * tailwind-merge only knows Tailwind's own font sizes. Without this extension it reads
 * our text-display / text-heading / text-item / text-ticket sizes as colours, and drops
 * the size from "text-display text-brand-text". Keep the list in step with the --text-*
 * sizes in global.css.
 */
export const cn = extendTailwindMerge({
  extend: {
    theme: {
      text: ["display", "heading", "item", "ticket"],
    },
  },
});
