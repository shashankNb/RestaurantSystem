import type { Order, OrderStatus } from '@/lib/api/schemas';
import { describeDay, formatTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';

export type StatusTone = 'waiting' | 'active' | 'good' | 'bad';

/** A status in a word or two, for lists such as the account's order history. */
export const STATUS_LABEL: Record<OrderStatus, string> = {
  pending_payment: 'Awaiting payment',
  placed: 'Waiting to be accepted',
  accepted: 'Accepted',
  preparing: 'Being prepared',
  ready: 'Ready',
  out_for_delivery: 'On its way',
  completed: 'Completed',
  rejected: 'Not accepted',
  cancelled: 'Cancelled',
};

/** Where an order is, in the customer's words: "Your order is being prepared". */
export function describeOrder(
  order: Order,
  timeZone: string,
  now: Date = new Date(),
): { title: string; detail: string | null; tone: StatusTone } {
  const pickup = order.fulfilment_type === 'pickup';
  const dineIn = order.fulfilment_type === 'dine_in';
  const when = (iso: string) => {
    const day = describeDay(iso, timeZone, now);

    return day === 'today' ? formatTime(iso, timeZone) : `${day} at ${formatTime(iso, timeZone)}`;
  };
  const ready = order.estimated_ready_at
    ? pickup
      ? `Ready for pickup at about ${when(order.estimated_ready_at)}.`
      : dineIn
        ? `We’ll bring it to table ${order.table ?? ''} at about ${when(order.estimated_ready_at)}.`
        : `Out for delivery at about ${when(order.estimated_ready_at)}.`
    : null;

  switch (order.status) {
    case 'pending_payment':
      return { title: 'Confirming your payment', detail: 'This usually takes a few seconds.', tone: 'waiting' };
    case 'placed':
      return {
        title: `Waiting for ${order.restaurant.name} to accept your order`,
        detail: order.scheduled_for
          ? `You asked for it ${pickup ? 'to be ready' : 'to arrive'} ${when(order.scheduled_for)}.`
          : 'This usually takes a few minutes.',
        tone: 'waiting',
      };
    case 'accepted':
      return { title: 'Your order is accepted', detail: ready, tone: 'active' };
    case 'preparing':
      return { title: 'Your order is being prepared', detail: ready, tone: 'active' };
    case 'ready':
      return pickup
        ? { title: 'Ready for pickup', detail: 'Collect it from the counter.', tone: 'good' }
        : dineIn
          ? { title: 'On its way to your table', detail: `A member of staff is bringing it to table ${order.table ?? ''}.`, tone: 'good' }
          : { title: 'Ready to go out', detail: 'It will be on its way shortly.', tone: 'good' };
    case 'out_for_delivery':
      return {
        title: 'On its way',
        detail: order.delivery?.suburb ? `Your order has left for ${order.delivery.suburb}.` : 'Your order has left the restaurant.',
        tone: 'good',
      };
    case 'completed':
      return dineIn
        ? { title: 'Served', detail: 'Enjoy your meal.', tone: 'good' }
        : { title: pickup ? 'Collected' : 'Delivered', detail: 'Thanks for your order.', tone: 'good' };
    case 'rejected':
      return {
        title: `${order.restaurant.name} couldn’t take your order`,
        detail: [order.rejection_reason ? `Reason: ${order.rejection_reason}.` : null, moneyBack(order)].filter(Boolean).join(' '),
        tone: 'bad',
      };
    case 'cancelled':
      return { title: 'Order cancelled', detail: moneyBack(order), tone: 'bad' };
  }
}

/** What happened to the customer's money after a rejection or cancellation. */
function moneyBack(order: Order): string {
  const total = formatMoney(order.total_cents);

  switch (order.payment_status) {
    case 'refunded':
      return `We’ve refunded ${total}. It can take 5 to 10 business days to show in your account.`;
    case 'paid':
      return `Your refund of ${total} is on its way.`;
    default:
      return 'You haven’t been charged.';
  }
}

export interface OrderStep {
  status: OrderStatus;
  label: string;
  /** When it happened, if it has. */
  at: string | null;
  state: 'done' | 'current' | 'upcoming';
}

/**
 * The steps from "Order placed" to "Collected", "Served" or "Delivered", with the ones reached so
 * far. A step the kitchen skipped (straight from accepted to ready) counts as done.
 */
export function orderSteps(order: Order): OrderStep[] {
  const type = order.fulfilment_type;
  const steps: { status: OrderStatus; label: string }[] = [
    { status: 'placed', label: 'Order placed' },
    { status: 'accepted', label: 'Accepted' },
    { status: 'preparing', label: 'Preparing' },
    { status: 'ready', label: type === 'pickup' ? 'Ready for pickup' : 'Ready' },
    ...(type === 'delivery' ? [{ status: 'out_for_delivery' as const, label: 'On its way' }] : []),
    { status: 'completed', label: type === 'pickup' ? 'Collected' : type === 'dine_in' ? 'Served' : 'Delivered' },
  ];
  const reached = steps.findIndex((step) => step.status === order.status);
  const finished = order.status === 'completed';

  return steps.map((step, index) => ({
    ...step,
    at: order.timeline.findLast((event) => event.status === step.status)?.at ?? null,
    state: index < reached || finished ? 'done' : index === reached ? 'current' : 'upcoming',
  }));
}
