import { useEffect, useRef, useState } from 'react';
import { Platform, Pressable, ScrollView, useWindowDimensions, View, type LayoutChangeEvent } from 'react-native';

import { Text } from '@/components/ui/text';
import type { MenuCategory } from '@/lib/api/schemas';
import { cn } from '@/lib/utils';

/** The menu's column width (max-w-3xl); the chips line up with its left edge. */
const COLUMN = 768;

/**
 * The menu's categories as a row of chips that sticks to the top while the menu scrolls.
 * The current category is filled; tapping one jumps to it.
 *
 * `topInset` is the status bar's height: the bar reaches up under the status bar by that
 * much (with a negative margin), so it covers it once stuck, and the chips sit below it.
 */
export function CategoryTabs({
  categories,
  active,
  onSelect,
  topInset,
  onLayout,
}: {
  categories: MenuCategory[];
  active: number | null;
  onSelect: (id: number) => void;
  topInset: number;
  onLayout: (event: LayoutChangeEvent) => void;
}) {
  const scrollRef = useRef<ScrollView>(null);
  const chipX = useRef(new Map<number, number>());
  // The bar's own width: on the web the window's width includes the scrollbar.
  const window = useWindowDimensions();
  const [measured, setMeasured] = useState<number | null>(null);
  const gutter = Math.max(16, ((measured ?? window.width) - COLUMN) / 2 + 16);

  // Keep the current chip in view as the menu scrolls.
  useEffect(() => {
    const x = active === null ? undefined : chipX.current.get(active);

    if (x !== undefined) {
      scrollRef.current?.scrollTo({ x: Math.max(0, x - gutter), animated: true });
    }
  }, [active, gutter]);

  return (
    <View
      onLayout={(event) => {
        setMeasured(event.nativeEvent.layout.width);
        onLayout(event);
      }}
      className="bg-background border-border border-b"
      style={{ marginTop: -topInset, paddingTop: topInset }}
    >
      <ScrollView
        ref={scrollRef}
        horizontal
        showsHorizontalScrollIndicator={false}
        role="tablist"
        aria-label="Menu categories"
        contentContainerClassName="gap-2 py-2"
        contentContainerStyle={{ paddingHorizontal: gutter }}
      >
        {categories.map((category) => {
          const selected = category.id === active;

          return (
            <Pressable
              key={category.id}
              role="tab"
              aria-selected={selected}
              onPress={() => onSelect(category.id)}
              onLayout={(event) => chipX.current.set(category.id, event.nativeEvent.layout.x)}
              className={cn(
                'min-h-11 justify-center rounded-full px-4',
                selected ? 'bg-foreground' : 'bg-muted active:bg-accent',
                Platform.select({
                  web: 'focus-visible:outline-ring outline-none focus-visible:outline-2 focus-visible:outline-offset-2',
                }),
              )}
            >
              <Text className={cn('font-body-semibold', selected ? 'text-background' : 'text-foreground')}>{category.name}</Text>
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}
