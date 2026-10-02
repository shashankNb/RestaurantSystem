import type { Metadata } from 'expo-router/server';
import type { ReactNode } from 'react';
import { ScrollView, View } from 'react-native';

import { FormError } from '@/components/form-error';
import { PageHead } from '@/components/page-head';
import { ErrorState, LoadingState } from '@/components/states';
import { SwitchRow } from '@/components/switch-row';
import { Text } from '@/components/ui/text';
import { useKitchen } from '@/kitchen/kitchen-context';
import { errorMessage } from '@/lib/api/client';
import { useMenu } from '@/lib/api/menu';
import { useRestaurant } from '@/lib/api/restaurant';
import type { Menu, ModifierGroup } from '@/lib/api/schemas';
import { useSetAcceptingOrders, useSetItemAvailable, useSetOptionAvailable } from '@/lib/api/staff';
import { privatePage, toMetadata } from '@/lib/page-meta';

const PAGE = privatePage('Kitchen settings');

/** Web: the page's title in the server's HTML. It stays out of search results. */
export function generateMetadata(): Metadata {
  return toMetadata(PAGE);
}

export default function KitchenSettingsPage() {
  return (
    <>
      <PageHead page={PAGE} />
      <KitchenSettings />
    </>
  );
}

/**
 * The kitchen's switches: pause online ordering, and mark dishes or choices (such as a
 * filling) sold out. Customers see each change on the menu straight away.
 */
function KitchenSettings() {
  const { started } = useKitchen();
  const restaurant = useRestaurant();
  const menu = useMenu();
  const setAccepting = useSetAcceptingOrders();
  const setItem = useSetItemAvailable();
  const setOption = useSetOptionAvailable();
  const failure = setAccepting.error ?? setItem.error ?? setOption.error;

  if (restaurant.isPending || menu.isPending) {
    return <LoadingState label="Loading the menu" />;
  }

  if (restaurant.isError || menu.isError) {
    const retry = () => {
      void restaurant.refetch();
      void menu.refetch();
    };

    return (
      <ErrorState
        title="We couldn’t load the menu"
        message={errorMessage(restaurant.error ?? menu.error)}
        onRetry={retry}
        retrying={restaurant.isFetching || menu.isFetching}
      />
    );
  }

  const accepting = restaurant.data.status.is_accepting_orders;

  return (
    <ScrollView contentContainerClassName="w-full max-w-2xl gap-10 self-center px-4 pb-12 pt-6">
      {started ? null : (
        <Text className="text-muted-foreground">Alerts for new orders are off until you start your shift on the Orders screen.</Text>
      )}
      <FormError message={failure ? `That didn’t save. ${errorMessage(failure)}` : undefined} />

      <Section title="Online ordering">
        <SwitchRow
          label="Taking orders"
          value={accepting}
          onChange={(value) => setAccepting.mutate(value)}
          detail={accepting ? 'Customers can order for as soon as possible.' : 'Paused. Customers can still order ahead for later.'}
        />
      </Section>

      <Section title="Dishes" description="Turn a dish off when it runs out. Customers see it as sold out straight away.">
        {menu.data.categories.map((category) => (
          <View key={category.id}>
            <Text variant="item" className="pb-1">
              {category.name}
            </Text>
            {category.items.map((item) => (
              <SwitchRow
                key={item.id}
                label={item.name}
                value={item.is_available}
                onChange={(available) => setItem.mutate({ id: item.id, available })}
                detail={item.is_available ? undefined : 'Sold out'}
              />
            ))}
          </View>
        ))}
      </Section>

      <Section title="Choices" description="Choices several dishes share, such as fillings. Turning one off takes it off every dish.">
        {groupsOf(menu.data).map((group) => (
          <View key={group.id}>
            <Text variant="item" className="pb-1">
              {group.name}
            </Text>
            {group.options.map((option) => (
              <SwitchRow
                key={option.id}
                label={option.name}
                value={option.is_available}
                onChange={(available) => setOption.mutate({ id: option.id, available })}
                detail={option.is_available ? undefined : 'Sold out'}
              />
            ))}
          </View>
        ))}
      </Section>
    </ScrollView>
  );
}

function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return (
    <View className="gap-4">
      <View className="gap-1">
        <Text variant="heading">{title}</Text>
        {description ? <Text className="text-muted-foreground">{description}</Text> : null}
      </View>
      {children}
    </View>
  );
}

/** Each option group once, though the menu repeats it under every dish that offers it. */
function groupsOf(menu: Menu): ModifierGroup[] {
  const groups = new Map<number, ModifierGroup>();

  for (const category of menu.categories) {
    for (const item of category.items) {
      for (const group of item.modifier_groups) {
        if (!groups.has(group.id)) {
          groups.set(group.id, group);
        }
      }
    }
  }

  return [...groups.values()];
}
