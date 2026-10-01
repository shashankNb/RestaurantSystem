import type { ComponentType } from "react";
import { styled } from "nativewind";
type MoeIconInteropProps = {
  className?: string;
  style?: object;
  size?: number | string;
  color?: string;
};
export function withMoeIcon<Props extends object>(
  component: ComponentType<Props>,
): ComponentType<Props> {
  return styled(component as unknown as ComponentType<MoeIconInteropProps>, {
    className: { target: "style", nativeStyleToProp: { width: "size", height: "size", color: true } },
  }) as unknown as ComponentType<Props>;
}
