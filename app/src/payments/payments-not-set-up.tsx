import { CircleAlert } from 'lucide-react-native';
import { View } from 'react-native';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';

/** Shown in place of the payment form when this build has no Stripe publishable key. */
export function PaymentsNotSetUp() {
  return (
    <View className="gap-3">
      <Alert icon={CircleAlert} variant="destructive">
        <AlertDescription>
          Payments aren’t set up in this build, so orders can’t be placed yet. Add the Stripe publishable key to
          app/.env and restart Expo.
        </AlertDescription>
      </Alert>
      <Button size="lg" disabled>
        <Text>Place order</Text>
      </Button>
    </View>
  );
}
