export type Screen =
  | "home"
  | "catalog"
  | "configurator"
  | "checkout"
  | "confirmation"
  | "status";

export interface Chocolate {
  id: string;
  name: string;
  desc: string;
}

export interface Size {
  id: string;
  name: string;
  price: number;
}

export interface Base {
  id: string;
  name: string;
  extra: number;
}

export interface ToppingItem {
  id: string;
  name: string;
}

export interface ToppingGroup {
  id: string;
  name: string;
  items: ToppingItem[];
}

export interface CatalogGroup {
  id: string;
  name: string;
  categories: string[];
}

export interface Product {
  name: string;
  price: number;
  perKilo?: boolean;
  diabetic?: boolean;
}

export interface OfferProduct {
  cat: string;
  name: string;
  price: number;
  was: number;
  perKilo?: boolean;
}

export interface PopularProduct {
  cat: string;
  name: string;
  price: number;
}

export interface Store {
  name: string;
  address: string;
  phone: string;
  phoneHref: string;
  site: string;
  instagram: string;
  hours: string;
  deliveryMinOrder: number;
  deliveryFee: number;
}

export interface CartItem {
  id: string;
  name: string;
  unitPrice: number;
  qty: number;
  meta: string;
  isCake: boolean;
}

export interface ConfiguratorState {
  step: number;
  size: string | null;
  choc: string | null;
  base: string | null;
  toppings: string[];
}

export type Fulfillment = "pickup" | "delivery";
export type Payment = "cash" | "card";

export interface Address {
  street: string;
  floor: string;
  bell: string;
  notes: string;
}

export interface CardDetails {
  name: string;
  number: string;
  expiry: string;
  cvv: string;
}

export interface Customer {
  name: string;
  phone: string;
}

/** weekday: 0=Κυριακή..6=Σάββατο (matches JS Date#getDay()). */
export interface OpeningPeriod {
  weekday: number;
  opensAt: string;
  closesAt: string;
  isClosed: boolean;
}

export interface StoreSettings {
  name: string;
  address: string;
  phone: string;
  phoneHref: string;
  instagram: string;
  deliveryMinOrder: number;
  deliveryFee: number;
  hours: OpeningPeriod[];
  /** Displayed on the confirmation screen for delivery orders, e.g. "35–45 λεπτά". */
  deliveryEtaMinMinutes: number;
  deliveryEtaMaxMinutes: number;
  /** Minimum lead time before a pickup time slot is offered to a customer. */
  pickupPrepMinutes: number;
}

export type OrderStatus = "new" | "in_progress" | "completed" | "cancelled";

/** A brand-new leaf category added from /admin, inside an existing or new group. */
export interface NewCategory {
  id: string;
  name: string;
  groupId: string;
}

/** A brand-new top-level catalog section (sticky-nav group) added from /admin. */
export interface NewGroup {
  id: string;
  name: string;
}

/**
 * Admin-edited deltas on top of the seed data in data/menu.ts, stored
 * server-side (see api/overrides.ts). A category key present here
 * replaces that category's product list entirely; categoryNames are
 * merged key-by-key. productImages/categoryImages map a product slug or
 * category id to the timestamp its custom photo was last uploaded (also
 * used as a cache-busting query value) — the images themselves live in
 * separate Redis keys, served by api/images/*.
 */
export interface MenuOverrides {
  products: Partial<Record<string, Product[]>>;
  categoryNames: Partial<Record<string, string>>;
  newGroups: NewGroup[];
  newCategories: NewCategory[];
  productImages: Partial<Record<string, string>>;
  categoryImages: Partial<Record<string, string>>;
  /** Group/category ids hidden from the customer app and admin (whether
   * they came from data/menu.ts or were admin-created) — union-merged, so
   * once deleted an id stays deleted until "Επαναφορά όλων". */
  deletedGroups: string[];
  deletedCategories: string[];
}

/** The payload a customer's checkout submits to POST /api/orders. */
export interface NewOrderInput {
  items: CartItem[];
  subtotal: number;
  deliveryFee: number;
  total: number;
  fulfillment: Fulfillment;
  pickupTime: string;
  address: Address | null;
  hasCake: boolean;
  cakeDateTime: string;
  cakeMessage: string;
  candles: number;
  payment: Payment;
  customer: Customer;
}

/** What the server stores per order, and returns from GET /api/orders(/:id). */
export interface StoredOrder extends NewOrderInput {
  orderNumber: string;
  status: OrderStatus;
  createdAt: string;
}

export interface OrderSnapshot {
  orderNumber: string;
  items: CartItem[];
  subtotal: number;
  deliveryFee: number;
  total: number;
  fulfillment: Fulfillment;
  pickupTime: string;
  hasCake: boolean;
  cakeDateTime: string;
  status: OrderStatus;
  /** False if the order couldn't be persisted (e.g. local dev without the API) — the
   * confirmation/status screens still work, but the order won't show up in /admin. */
  synced: boolean;
}
