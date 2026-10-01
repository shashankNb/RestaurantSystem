"use client";

import { cn } from "../../lib/utils";
import { View } from "react-native";
import Animated, {
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withRepeat,
  withTiming,
} from "react-native-reanimated";
import * as React from "react";

const duration = 1000;

/**
 * A loading placeholder. It pulses gently, or holds still when the device asks for
 * reduced motion.
 */
function Skeleton({
  className,
  ...props
}: React.ComponentProps<typeof View> & React.RefAttributes<View>) {
  const reduceMotion = useReducedMotion();
  const sv = useSharedValue(reduceMotion ? 0.75 : 1);

  React.useEffect(() => {
    if (!reduceMotion) {
      sv.value = withRepeat(withTiming(0.5, { duration }), -1, true);
    }
  }, [reduceMotion, sv]);

  const style = useAnimatedStyle(
    () => ({
      opacity: sv.value,
    }),
    [sv],
  );
  return (
    <Animated.View
      style={style}
      className={cn("bg-muted rounded-md", className)}
      aria-hidden
      {...props}
    />
  );
}

export { Skeleton };
