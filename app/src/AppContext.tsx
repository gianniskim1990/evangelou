import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { FREE_TOPPINGS, TOPPING_EXTRA_PRICE, bases, chocolates, sizes } from "./data/menu";
import { useSettings } from "./SettingsContext";
import type {
  Address,
  CardDetails,
  CartItem,
  CustomProfiteroleConfig,
  ConfiguratorState,
  Customer,
  Fulfillment,
  NewOrderInput,
  OrderSnapshot,
  OrderStatus,
  Payment,
  Screen,
} from "./types";

const EMPTY_CFG: ConfiguratorState = { step: 1, size: null, choc: null, base: null, toppings: [] };
const EMPTY_ADDRESS: Address = { street: "", floor: "", bell: "", notes: "" };
const EMPTY_CARD: CardDetails = { name: "", number: "", expiry: "", cvv: "" };
const EMPTY_CUSTOMER: Customer = { name: "", phone: "" };

interface AppContextValue {
  screen: Screen;
  goHome: () => void;
  goCatalog: () => void;
  goConfigurator: () => void;
  goCheckout: () => void;

  cartOpen: boolean;
  openCart: () => void;
  closeCart: () => void;

  cart: CartItem[];
  cartCount: number;
  cartTotal: number;
  addToCart: (name: string, unitPrice: number, meta?: string, isCake?: boolean) => void;
  incCartItem: (id: string) => void;
  decCartItem: (id: string) => void;
  editConfiguredCartItem: (id: string) => void;

  activeGroup: string | null;
  setActiveGroup: (id: string) => void;

  cfg: ConfiguratorState;
  cfgTotal: number;
  cfgCanProceed: boolean;
  selectSize: (id: string) => void;
  selectChoc: (id: string) => void;
  selectBase: (id: string) => void;
  toggleTopping: (id: string) => void;
  cfgNext: () => void;
  cfgPrev: () => void;
  addConfiguredToCart: () => void;
  isEditingConfiguredItem: boolean;

  customer: Customer;
  setCustomerName: (v: string) => void;
  setCustomerPhone: (v: string) => void;

  fulfillment: Fulfillment;
  setFulfillment: (f: Fulfillment) => void;
  pickupTime: string;
  setPickupTime: (t: string) => void;
  address: Address;
  setAddressField: (field: keyof Address, value: string) => void;

  hasCakeInCart: boolean;
  cakeDateTime: string;
  setCakeDateTime: (v: string) => void;
  cakeMessage: string;
  setCakeMessage: (v: string) => void;
  candles: number;
  incCandles: () => void;
  decCandles: () => void;

  payment: Payment;
  setPayment: (p: Payment) => void;
  card: CardDetails;
  setCardName: (v: string) => void;
  setCardNumber: (v: string) => void;
  setCardExpiry: (v: string) => void;
  setCardCvv: (v: string) => void;

  canSubmitOrder: boolean;
  submitting: boolean;
  submitOrder: () => void;

  order: OrderSnapshot | null;
  goStatus: () => void;
}

const AppContext = createContext<AppContextValue | null>(null);

export function AppProvider({ children }: { children: ReactNode }) {
  const { settings } = useSettings();
  const [screen, setScreen] = useState<Screen>("home");
  const [cartOpen, setCartOpen] = useState(false);
  const [cart, setCart] = useState<CartItem[]>([]);
  const [activeGroup, setActiveGroup] = useState<string | null>(null);

  const [cfg, setCfg] = useState<ConfiguratorState>(EMPTY_CFG);
  const [editingConfiguredItemId, setEditingConfiguredItemId] = useState<string | null>(null);

  const [customer, setCustomer] = useState<Customer>(EMPTY_CUSTOMER);
  const [fulfillment, setFulfillment] = useState<Fulfillment>("pickup");
  const [pickupTime, setPickupTime] = useState("");
  const [address, setAddress] = useState<Address>(EMPTY_ADDRESS);
  const [cakeDateTime, setCakeDateTime] = useState("");
  const [cakeMessage, setCakeMessage] = useState("");
  const [candles, setCandles] = useState(1);
  const [payment, setPayment] = useState<Payment>("cash");
  const [card, setCard] = useState<CardDetails>(EMPTY_CARD);

  const [order, setOrder] = useState<OrderSnapshot | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const statusTimer = useRef<number | null>(null);

  useEffect(() => {
    return () => {
      if (statusTimer.current) window.clearInterval(statusTimer.current);
    };
  }, []);

  const goHome = useCallback(() => {
    setEditingConfiguredItemId(null);
    setScreen("home");
  }, []);
  const goCatalog = useCallback(() => {
    setEditingConfiguredItemId(null);
    setScreen("catalog");
  }, []);
  const goConfigurator = useCallback(() => {
    setEditingConfiguredItemId(null);
    setCfg(EMPTY_CFG);
    setScreen("configurator");
  }, []);
  const goCheckout = useCallback(() => {
    setEditingConfiguredItemId(null);
    setCartOpen(false);
    setScreen("checkout");
  }, []);

  const openCart = useCallback(() => setCartOpen(true), []);
  const closeCart = useCallback(() => setCartOpen(false), []);

  const addToCart = useCallback((name: string, unitPrice: number, meta = "", isCake = false) => {
    setCart((prev) => [
      ...prev,
      { id: Math.random().toString(36).slice(2), name, unitPrice, qty: 1, meta, isCake },
    ]);
  }, []);

  const incCartItem = useCallback((id: string) => {
    setCart((prev) => prev.map((it) => (it.id === id ? { ...it, qty: it.qty + 1 } : it)));
  }, []);
  const decCartItem = useCallback((id: string) => {
    setCart((prev) =>
      prev.flatMap((it) => {
        if (it.id !== id) return [it];
        return it.qty <= 1 ? [] : [{ ...it, qty: it.qty - 1 }];
      }),
    );
  }, []);

  const editConfiguredCartItem = useCallback(
    (id: string) => {
      const item = cart.find((it) => it.id === id);
      if (!item?.customConfig) return;

      setCfg({
        step: 1,
        size: item.customConfig.size,
        choc: item.customConfig.choc,
        base: item.customConfig.base,
        toppings: [...item.customConfig.toppings],
      });
      setEditingConfiguredItemId(id);
      setCartOpen(false);
      setScreen("configurator");
    },
    [cart],
  );

  const cartCount = cart.reduce((s, it) => s + it.qty, 0);
  const cartTotal = cart.reduce((s, it) => s + it.qty * it.unitPrice, 0);

  // --- Configurator ---
  const sizeObj = sizes.find((s) => s.id === cfg.size) ?? null;
  const chocObj = chocolates.find((c) => c.id === cfg.choc) ?? null;
  const baseObj = bases.find((b) => b.id === cfg.base) ?? null;
  const extraToppings = Math.max(0, cfg.toppings.length - FREE_TOPPINGS);
  const isEditingConfiguredItem = editingConfiguredItemId !== null;
  const cfgTotal = (sizeObj ? sizeObj.price : 0) + (baseObj ? baseObj.extra : 0) + extraToppings * TOPPING_EXTRA_PRICE;
  const cfgCanProceed =
    (cfg.step === 1 && !!cfg.size) || (cfg.step === 2 && !!cfg.choc) || (cfg.step === 3 && !!cfg.base) || cfg.step === 4;

  const selectSize = useCallback((id: string) => setCfg((c) => ({ ...c, size: id })), []);
  const selectChoc = useCallback((id: string) => setCfg((c) => ({ ...c, choc: id })), []);
  const selectBase = useCallback((id: string) => setCfg((c) => ({ ...c, base: id })), []);
  const toggleTopping = useCallback((id: string) => {
    setCfg((c) => ({
      ...c,
      toppings: c.toppings.includes(id) ? c.toppings.filter((x) => x !== id) : [...c.toppings, id],
    }));
  }, []);
  const cfgNext = useCallback(() => {
    setCfg((c) => {
      const canProceed =
        (c.step === 1 && !!c.size) || (c.step === 2 && !!c.choc) || (c.step === 3 && !!c.base) || c.step === 4;
      return canProceed ? { ...c, step: c.step + 1 } : c;
    });
  }, []);
  const cfgPrev = useCallback(() => setCfg((c) => ({ ...c, step: c.step - 1 })), []);

  const addConfiguredToCart = useCallback(() => {
    if (!sizeObj || !chocObj || !baseObj) return;

    const meta = `${sizeObj.name} · ${chocObj.name} · ${baseObj.name}`;
    const customConfig: CustomProfiteroleConfig = {
      size: sizeObj.id,
      choc: chocObj.id,
      base: baseObj.id,
      toppings: [...cfg.toppings],
    };

    setCart((prev) => {
      if (editingConfiguredItemId) {
        const found = prev.some((it) => it.id === editingConfiguredItemId && it.customConfig);
        if (found) {
          return prev.map((it) =>
            it.id === editingConfiguredItemId
              ? {
                  ...it,
                  name: "Το προφιτερόλ σου",
                  unitPrice: cfgTotal,
                  meta,
                  isCake: false,
                  customConfig,
                }
              : it,
          );
        }
      }

      return [
        ...prev,
        {
          id: Math.random().toString(36).slice(2),
          name: "Το προφιτερόλ σου",
          unitPrice: cfgTotal,
          qty: 1,
          meta,
          isCake: false,
          customConfig,
        },
      ];
    });

    const wasEditing = editingConfiguredItemId !== null;
    setEditingConfiguredItemId(null);
    setCfg(EMPTY_CFG);
    setScreen("home");
    if (wasEditing) setCartOpen(true);
  }, [sizeObj, chocObj, baseObj, cfg.toppings, cfgTotal, editingConfiguredItemId]);

  // --- Checkout ---
  const setCustomerName = useCallback((v: string) => setCustomer((c) => ({ ...c, name: v })), []);
  const setCustomerPhone = useCallback((v: string) => setCustomer((c) => ({ ...c, phone: v })), []);

  const setAddressField = useCallback((field: keyof Address, value: string) => {
    setAddress((a) => ({ ...a, [field]: value }));
  }, []);

  const hasCakeInCart = cart.some((it) => it.isCake);

  const incCandles = useCallback(() => setCandles((c) => Math.min(99, c + 1)), []);
  const decCandles = useCallback(() => setCandles((c) => Math.max(0, c - 1)), []);

  const setCardNumber = useCallback((value: string) => {
    const digits = value.replace(/\D/g, "").slice(0, 16);
    const grouped = digits.replace(/(.{4})/g, "$1 ").trim();
    setCard((c) => ({ ...c, number: grouped }));
  }, []);
  const setCardExpiry = useCallback((value: string) => {
    let v = value.replace(/\D/g, "").slice(0, 4);
    if (v.length > 2) v = v.slice(0, 2) + "/" + v.slice(2);
    setCard((c) => ({ ...c, expiry: v }));
  }, []);
  const setCardCvv = useCallback((value: string) => {
    setCard((c) => ({ ...c, cvv: value.replace(/\D/g, "").slice(0, 3) }));
  }, []);
  const setCardName = useCallback((value: string) => setCard((c) => ({ ...c, name: value })), []);

  const isDelivery = fulfillment === "delivery";
  const canSubmitOrder =
    customer.name.trim().length > 0 &&
    customer.phone.trim().length > 0 &&
    (!isDelivery || address.street.trim().length > 0) &&
    (fulfillment !== "pickup" || pickupTime.length > 0) &&
    (!hasCakeInCart || cakeDateTime.length > 0) &&
    (payment !== "card" || (card.name.length > 0 && card.number.length > 0 && card.expiry.length > 0 && card.cvv.length > 0));

  const submitOrder = useCallback(async () => {
    const total = cartTotal + (isDelivery ? settings.deliveryFee : 0);
    const payload: NewOrderInput = {
      items: cart,
      subtotal: cartTotal,
      deliveryFee: isDelivery ? settings.deliveryFee : 0,
      total,
      fulfillment,
      pickupTime,
      address: isDelivery ? address : null,
      hasCake: hasCakeInCart,
      cakeDateTime,
      cakeMessage,
      candles,
      payment,
      customer,
    };

    setSubmitting(true);
    let orderNumber = "EV-" + Math.floor(100000 + Math.random() * 900000);
    let synced = false;
    try {
      const res = await fetch("/api/orders", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      if (res.ok) {
        const data = await res.json();
        orderNumber = data.order.orderNumber;
        synced = true;
      }
    } catch {
      // No backend reachable (e.g. local dev without `vercel dev`) — the
      // demo still completes locally, it just won't show up in /admin.
    }
    setSubmitting(false);

    setOrder({
      orderNumber,
      items: cart,
      subtotal: cartTotal,
      deliveryFee: payload.deliveryFee,
      total,
      fulfillment,
      pickupTime,
      hasCake: hasCakeInCart,
      cakeDateTime,
      status: "new",
      synced,
    });
    setCart([]);
    setScreen("confirmation");
  }, [cart, cartTotal, isDelivery, settings.deliveryFee, fulfillment, pickupTime, address, hasCakeInCart, cakeDateTime, cakeMessage, candles, payment, customer]);

  const goStatus = useCallback(() => {
    const orderNumber = order?.orderNumber;
    const synced = order?.synced;
    setScreen("status");
    if (statusTimer.current) window.clearInterval(statusTimer.current);
    if (!orderNumber) return;

    let localStage = 0;
    statusTimer.current = window.setInterval(async () => {
      if (synced) {
        try {
          const res = await fetch(`/api/orders/${orderNumber}`);
          if (!res.ok) throw new Error(String(res.status));
          const data = await res.json();
          const nextStatus = data.order.status as OrderStatus;
          setOrder((prev) => (prev ? { ...prev, status: nextStatus } : prev));
          if ((nextStatus === "completed" || nextStatus === "cancelled") && statusTimer.current) {
            window.clearInterval(statusTimer.current);
          }
          return;
        } catch {
          // fall through to the local simulation below
        }
      }
      localStage = Math.min(localStage + 1, 2);
      const simulated: OrderStatus = localStage === 0 ? "new" : localStage === 1 ? "in_progress" : "completed";
      setOrder((prev) => (prev ? { ...prev, status: simulated } : prev));
      if (localStage >= 2 && statusTimer.current) window.clearInterval(statusTimer.current);
    }, 4000);
  }, [order]);

  const value: AppContextValue = {
    screen,
    goHome,
    goCatalog,
    goConfigurator,
    goCheckout,
    cartOpen,
    openCart,
    closeCart,
    cart,
    cartCount,
    cartTotal,
    addToCart,
    incCartItem,
    decCartItem,
    editConfiguredCartItem,
    activeGroup,
    setActiveGroup,
    cfg,
    cfgTotal,
    cfgCanProceed,
    selectSize,
    selectChoc,
    selectBase,
    toggleTopping,
    cfgNext,
    cfgPrev,
    addConfiguredToCart,
    isEditingConfiguredItem,
    customer,
    setCustomerName,
    setCustomerPhone,
    fulfillment,
    setFulfillment,
    pickupTime,
    setPickupTime,
    address,
    setAddressField,
    hasCakeInCart,
    cakeDateTime,
    setCakeDateTime,
    cakeMessage,
    setCakeMessage,
    candles,
    incCandles,
    decCandles,
    payment,
    setPayment,
    card,
    setCardName,
    setCardNumber,
    setCardExpiry,
    setCardCvv,
    canSubmitOrder,
    submitting,
    submitOrder,
    order,
    goStatus,
  };

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}

export function useApp(): AppContextValue {
  const ctx = useContext(AppContext);
  if (!ctx) throw new Error("useApp must be used within AppProvider");
  return ctx;
}
