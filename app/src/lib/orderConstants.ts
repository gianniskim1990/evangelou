import type { Fulfillment, OrderStatus, Payment } from "../types";

export const ORDER_STATUSES: OrderStatus[] = ["new", "in_progress", "completed", "cancelled"];

export const ORDER_STATUS_LABELS_EL: Record<OrderStatus, string> = {
  new: "Νέα",
  in_progress: "Σε εξέλιξη",
  completed: "Ολοκληρωμένη",
  cancelled: "Ακυρωμένη",
};

export const FULFILLMENT_LABELS_EL: Record<Fulfillment, string> = {
  pickup: "Παραλαβή",
  delivery: "Delivery",
};

export const PAYMENT_LABELS_EL: Record<Payment, string> = {
  cash: "Μετρητά",
  card: "Κάρτα",
};

export function formatAthensDateTime(iso: string): string {
  return new Date(iso).toLocaleString("el-GR", {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}
