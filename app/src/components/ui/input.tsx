"use client";

import { cn } from "../../lib/utils";
import { Platform, TextInput, type TextInputProps } from "react-native";

/*
 * Restyled to the approved design: 48 px tall, 8 px radius, no shadow, a border that
 * passes 3:1 against the page, and on the web a 2 px focus ring in the text colour.
 * `invalid` shows the error state (and sets aria-invalid on the web; React Native has no
 * equivalent, so pair it with an error message that is announced).
 */
function Input({
  className,
  invalid = false,
  ...props
}: TextInputProps & React.RefAttributes<TextInput> & { invalid?: boolean }) {
  const webProps = Platform.OS === "web" ? ({ "aria-invalid": invalid } as object) : {};

  return (
    <TextInput
      className={cn(
        "border-input bg-background text-foreground font-body flex h-12 w-full min-w-0 flex-row items-center rounded-md border px-3 text-base",
        invalid && "border-destructive border-2",
        props.editable === false &&
          cn(
            "opacity-50",
            Platform.select({
              web: "disabled:pointer-events-none disabled:cursor-not-allowed",
            }),
          ),
        Platform.select({
          web: cn(
            "placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground outline-none transition-colors",
            "focus-visible:outline-ring focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2",
          ),
          native: "placeholder:text-muted-foreground",
        }),
        className,
      )}
      {...webProps}
      {...props}
    />
  );
}

export { Input };
