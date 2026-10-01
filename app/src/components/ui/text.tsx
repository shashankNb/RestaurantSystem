"use client";

import { cn } from "../../lib/utils";
import * as Slot from "@rn-primitives/slot";
import { cva, type VariantProps } from "class-variance-authority";
import * as React from "react";
import { Platform, Text as RNText, type Role } from "react-native";

/*
 * The approved type scale (docs/DESIGN.md). Eczar is for display text only, used large
 * and sparingly; Mukta is everything else. Weights are separate font families, so use the
 * font-body-* classes rather than font-medium / font-semibold.
 */
const textVariants = cva(
  cn(
    "text-foreground font-body text-base",
    Platform.select({
      web: "select-text",
    }),
  ),
  {
    variants: {
      variant: {
        default: "",
        /** The restaurant's name. */
        display: cn(
          "font-display text-display text-brand-text",
          Platform.select({ web: "text-balance" }),
        ),
        /** Category headings, the item title in the options sheet, page titles. */
        heading: cn(
          "font-display text-heading",
          Platform.select({ web: "text-balance" }),
        ),
        /** Menu item names and other list titles. */
        item: "font-body-semibold text-item",
        /** Rules, status lines and captions. */
        small: "font-body-medium text-sm",
        muted: "text-muted-foreground text-sm",
        /** Order numbers on kitchen tickets. */
        ticket: "font-display-bold text-ticket",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
);

type TextVariantProps = VariantProps<typeof textVariants>;

type TextVariant = NonNullable<TextVariantProps["variant"]>;

const ROLE: Partial<Record<TextVariant, Role>> = {
  display: "heading",
  heading: "heading",
};

const ARIA_LEVEL: Partial<Record<TextVariant, string>> = {
  display: "1",
  heading: "2",
};

const TextClassContext = React.createContext<string | undefined>(undefined);

function Text({
  className,
  asChild = false,
  variant = "default",
  ...props
}: React.ComponentProps<typeof RNText> &
  TextVariantProps &
  React.RefAttributes<RNText> & {
    asChild?: boolean;
  }) {
  const textClass = React.useContext(TextClassContext);
  const Component = asChild ? Slot.Text : RNText;
  return (
    <Component
      className={cn(textVariants({ variant }), textClass, className)}
      role={variant ? ROLE[variant] : undefined}
      aria-level={variant ? ARIA_LEVEL[variant] : undefined}
      {...props}
    />
  );
}

export { Text, TextClassContext };
