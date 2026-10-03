import { View } from 'react-native';

import { CircleAlert } from '@/components/icons';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';

/**
 * Shown in place of the payment form while the restaurant can't take payments online: its
 * owner hasn't entered all its Stripe keys or connected its Square account yet (back office,
 * Restaurant settings → Payments).
 */
export function PaymentsNotSetUp() {
  return (
    <View className="gap-3">
      <Alert icon={CircleAlert} variant="destructive">
        <AlertDescription>
          This restaurant isn’t taking payments online yet, so orders can’t be placed here. Call the restaurant to
          order.
        </AlertDescription>
      </Alert>
      <Button size="lg" disabled>
        <Text>Place order</Text>
      </Button>
    </View>
  );
}
