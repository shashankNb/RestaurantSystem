"use client";

import { cn } from "../../lib/utils";
import { Platform, TextInput, type TextInputProps } from "react-native";

function Textarea({
  className,
  multiline = true,
  numberOfLines = Platform.select({ web: 2, native: 8 }), // On web, numberOfLines also determines initial height. On native, it determines the maximum height.
  invalid = false,
  ...props
}: TextInputProps & React.RefAttributes<TextInput> & { invalid?: boolean }) {
  const webProps = Platform.OS === "web" ? ({ "aria-invalid": invalid } as object) : {};

  return (
    <TextInput
      className={cn(
        // Restyled to the approved design: Mukta, a 3:1 border, 8 px radius, no shadow.
        "text-foreground border-input bg-background font-body flex min-h-24 w-full flex-row rounded-md border px-3 py-2.5 text-base",
        invalid && "border-destructive border-2",
        Platform.select({
          web: "placeholder:text-muted-foreground focus-visible:outline-ring field-sizing-content resize-y outline-none focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed",
          native: "placeholder:text-muted-foreground",
        }),
        props.editable === false && "opacity-50",
        className,
      )}
      multiline={multiline}
      numberOfLines={numberOfLines}
      textAlignVertical="top"
      {...webProps}
      {...props}
    />
  );
}

export { Textarea };
