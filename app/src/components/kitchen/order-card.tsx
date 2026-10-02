import { useId, useState } from 'react';
import { View } from 'react-native';

import { ChoiceRow } from '@/components/choice-row';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Text } from '@/components/ui/text';
import { ago, at, minutesUntil } from '@/kitchen/time';
import { errorMessage } from '@/lib/api/client';
import type { StaffOrder } from '@/lib/api/schemas';
import { fulfilmentLabel } from '@/lib/fulfilment';
import { useAcceptOrder, useAdvanceOrder, useRejectOrder } from '@/lib/api/staff';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

/** The one-tap prep times for accepting an order. */
const PREP_TIMES = [10, 15, 20, 30];

const REJECT_REASONS = ['We’ve run out of an ingredient', 'We’re too busy right now', 'We’re closing soon'];

type NextStatus = 'preparing' | 'ready' | 'out_for_delivery' | 'completed' | 'cancelled';

interface CardProps {
  order: StaffOrder;
  /** From useNow(), so every card's times move together. */
  now: number;
  timeZone: string;
  currency: string;
}

/**
 * An order on the kitchen board: its number, what to make, who it's for and when, and the
 * next step. A new order is outlined in Marigold until it's accepted or rejected.
 */
export function OrderCard({ order, now, timeZone, currency }: CardProps) {
  const accept = useAcceptOrder();
  const reject = useRejectOrder();
  const advance = useAdvanceOrder();
  const [mode, setMode] = useState<'actions' | 'rejecting' | 'cancelling'>('actions');
  const busy = accept.isPending || reject.isPending || advance.isPending;
  const failure = accept.error ?? reject.error ?? advance.error;
  const isNew = order.status === 'placed';
  const number = order.order_number ?? '—';

  const move = (status: NextStatus, note?: string) => advance.mutate({ order, status, note });

  return (
    <View
      role="group"
      aria-label={`Order ${number}${order.table ? `, table ${order.table}` : ''}${isNew ? ', new' : ''}`}
      className={cn('bg-background gap-3 rounded-lg p-4', isNew ? 'border-marigold border-[3px]' : 'border-border border')}
    >
      <View className="flex-row items-start justify-between gap-3">
        <View className="gap-1">
          <Text variant="ticket">{number}</Text>
          {isNew ? (
            <View className="bg-marigold self-start rounded-sm px-2 py-0.5">
              <Text className="text-on-marigold font-body-bold text-sm">New</Text>
            </View>
          ) : null}
        </View>
        <View className="flex-1 items-end gap-0.5">
          {order.fulfilment_type === 'dine_in' ? (
            // Where it goes is what matters: the table, large.
            <Text className="font-display-bold text-heading text-right">Table {order.table}</Text>
          ) : null}
          <Text className="font-body-semibold text-right">
            {fulfilmentLabel(order.fulfilment_type)} ·{' '}
            {order.scheduled_for ? `for ${at(order.scheduled_for, timeZone, now)}` : 'as soon as possible'}
          </Text>
          {order.placed_at ? <Text variant="muted">Ordered {ago(order.placed_at, now)}</Text> : null}
        </View>
      </View>

      <Text>
        <Text className="font-body-semibold">{order.customer.name ?? 'Customer'}</Text>
        {order.customer.phone ? <Text className="text-muted-foreground"> · {order.customer.phone}</Text> : null}
      </Text>

      <View className="border-border gap-2 border-t pt-3">
        {order.items.map((item, index) => (
          <View key={index}>
            <Text className="font-body-semibold text-item">
              {item.quantity} × {item.name}
            </Text>
            {item.modifiers.length > 0 ? (
              <Text className="text-muted-foreground">{item.modifiers.map((modifier) => modifier.name).join(', ')}</Text>
            ) : null}
            {item.notes ? <Text className="font-body-semibold">Note: {item.notes}</Text> : null}
          </View>
        ))}
        {order.notes ? <Text className="font-body-semibold">Order note: {order.notes}</Text> : null}
      </View>

      {order.delivery ? (
        <View className="border-border gap-0.5 border-t pt-3">
          <Text className="font-body-semibold">Deliver to</Text>
          <Text>{[order.delivery.line1, order.delivery.line2].filter(Boolean).join(', ')}</Text>
          <Text>{[order.delivery.suburb, order.delivery.postcode].filter(Boolean).join(' ')}</Text>
          {order.delivery.instructions ? <Text className="text-muted-foreground">For the driver: {order.delivery.instructions}</Text> : null}
        </View>
      ) : null}

      <Timing order={order} now={now} timeZone={timeZone} currency={currency} />

      {failure ? (
        <Text role="alert" className="text-destructive">
          {errorMessage(failure)}
        </Text>
      ) : null}

      {mode === 'rejecting' ? (
        <RejectForm busy={busy} onReject={(reason) => reject.mutate({ order, reason })} onKeep={() => setMode('actions')} />
      ) : mode === 'cancelling' ? (
        <CancelForm number={number} busy={busy} onCancel={(note) => move('cancelled', note)} onKeep={() => setMode('actions')} />
      ) : (
        <Actions
          order={order}
          busy={busy}
          onAccept={(prepMinutes) => accept.mutate({ order, prepMinutes })}
          onMove={move}
          onReject={() => setMode('rejecting')}
          onCancel={() => setMode('cancelling')}
        />
      )}
    </View>
  );
}

/** How long is left: to accept it, or until it's due; red when it's urgent or late. */
function Timing({ order, now, timeZone, currency }: CardProps) {
  let line: string | null = null;
  let urgent = false;

  if (order.status === 'placed' && order.accept_by) {
    const left = minutesUntil(order.accept_by, now);
    line =
      left > 60
        ? // Placed while closed: the clock starts when the restaurant opens.
          `Accept by ${at(order.accept_by, timeZone, now)}, or it’s rejected automatically`
        : left > 0
          ? `Accept within ${left} min, or it’s rejected automatically`
          : 'Accept now: it’s about to be rejected automatically';
    urgent = left <= 3;
  } else if ((order.status === 'accepted' || order.status === 'preparing') && order.estimated_ready_at) {
    const left = minutesUntil(order.estimated_ready_at, now);
    line = left >= 0 ? `Ready by ${at(order.estimated_ready_at, timeZone, now)}` : `Late by ${-left} min`;
    urgent = left < 0;
  } else if (order.status === 'ready' && order.ready_at) {
    const waiting = { pickup: 'Waiting for pickup', delivery: 'Waiting for the driver', dine_in: `Ready to take to table ${order.table ?? ''}` };
    line = `${waiting[order.fulfilment_type]} since ${at(order.ready_at, timeZone, now)}`;
  }

  return (
    <View className="border-border flex-row flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-t pt-3">
      <Text className={cn('flex-1', urgent ? 'text-destructive font-body-semibold' : 'text-muted-foreground')}>{line ?? ''}</Text>
      <Text className="font-body-semibold">
        {formatMoney(order.total_cents, currency)}
        {order.payment_status === 'paid' ? ' · Paid' : ''}
      </Text>
    </View>
  );
}

function Actions({
  order,
  busy,
  onAccept,
  onMove,
  onReject,
  onCancel,
}: {
  order: StaffOrder;
  busy: boolean;
  onAccept: (prepMinutes: number) => void;
  onMove: (status: NextStatus) => void;
  onReject: () => void;
  onCancel: () => void;
}) {
  if (order.status === 'placed') {
    return (
      <View className="gap-2">
        <Text variant="small">Accept order · prep time</Text>
        <View className="flex-row flex-wrap gap-2">
          {PREP_TIMES.map((minutes) => (
            <Button
              key={minutes}
              className="min-w-20 flex-1 px-3"
              onPress={() => onAccept(minutes)}
              disabled={busy}
              aria-label={`Accept order, prep time ${minutes} minutes`}
            >
              <Text>{minutes} min</Text>
            </Button>
          ))}
        </View>
        <Button variant="outline" onPress={onReject} disabled={busy}>
          <Text className="text-destructive">Reject order</Text>
        </Button>
      </View>
    );
  }

  const next: Record<string, { label: string; status: NextStatus } | undefined> = {
    accepted: { label: 'Start preparing', status: 'preparing' },
    preparing: { label: 'Mark ready', status: 'ready' },
    ready:
      order.fulfilment_type === 'delivery'
        ? { label: 'Out for delivery', status: 'out_for_delivery' }
        : { label: order.fulfilment_type === 'dine_in' ? 'Served' : 'Collected', status: 'completed' },
    out_for_delivery: { label: 'Delivered', status: 'completed' },
  };
  const step = next[order.status];

  if (step === undefined) {
    return null;
  }

  return (
    <View className="gap-1">
      <Button size="lg" onPress={() => onMove(step.status)} disabled={busy}>
        <Text>{busy ? 'Saving…' : step.label}</Text>
      </Button>
      <Button variant="link" className="h-11 self-start px-0" onPress={onCancel} disabled={busy}>
        <Text className="text-destructive">Cancel order</Text>
      </Button>
    </View>
  );
}

function RejectForm({ busy, onReject, onKeep }: { busy: boolean; onReject: (reason: string) => void; onKeep: () => void }) {
  const [reason, setReason] = useState('');
  const labelId = useId();
  const valid = reason.trim().length >= 3;

  return (
    <View className="gap-3">
      <Text className="font-body-semibold" nativeID={labelId}>
        Why can’t you take it? The customer will see this.
      </Text>
      <View role="radiogroup" aria-labelledby={labelId}>
        {REJECT_REASONS.map((preset) => (
          <ChoiceRow key={preset} kind="radio" checked={reason === preset} label={preset} onPress={() => setReason(preset)} />
        ))}
      </View>
      <Input
        value={reason}
        onChangeText={setReason}
        placeholder="Or write your own"
        maxLength={200}
        aria-labelledby={labelId}
        accessibilityLabel="Reason for rejecting"
      />
      <View className="flex-row flex-wrap gap-2">
        <Button variant="destructive" onPress={() => onReject(reason.trim())} disabled={!valid || busy}>
          <Text>{busy ? 'Rejecting…' : 'Reject order'}</Text>
        </Button>
        <Button variant="outline" onPress={onKeep} disabled={busy}>
          <Text>Keep order</Text>
        </Button>
      </View>
    </View>
  );
}

function CancelForm({
  number,
  busy,
  onCancel,
  onKeep,
}: {
  number: string;
  busy: boolean;
  onCancel: (note: string | undefined) => void;
  onKeep: () => void;
}) {
  const [note, setNote] = useState('');

  return (
    <View className="gap-3">
      <Text className="font-body-semibold">Cancel order {number}? The customer is refunded in full and told.</Text>
      <Input value={note} onChangeText={setNote} placeholder="Reason (optional)" maxLength={200} accessibilityLabel="Reason for cancelling (optional)" />
      <View className="flex-row flex-wrap gap-2">
        <Button variant="destructive" onPress={() => onCancel(note.trim() || undefined)} disabled={busy}>
          <Text>{busy ? 'Cancelling…' : 'Cancel order'}</Text>
        </Button>
        <Button variant="outline" onPress={onKeep} disabled={busy}>
          <Text>Keep order</Text>
        </Button>
      </View>
    </View>
  );
}
