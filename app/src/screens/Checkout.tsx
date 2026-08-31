import { useMemo, useState } from "react";
import { useApp } from "../AppContext";
import { store } from "../data/menu";
import { fmt, isStoreClosedNow, pickupSlots } from "../lib/format";

const inputClass =
  "w-full rounded-xl border border-espresso/20 bg-white px-3.5 py-3.5 text-sm font-[Commissioner,sans-serif]";

export function Checkout() {
  const {
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
    cartTotal,
    canSubmitOrder,
    submitOrder,
  } = useApp();

  const isDelivery = fulfillment === "delivery";
  const isPayCard = payment === "card";
  const slots = useMemo(() => pickupSlots(store.hours), []);
  const storeClosedNow = useMemo(() => isStoreClosedNow(store.hours), []);
  const [cakeMin] = useState(() => new Date(Date.now() + 24 * 3600 * 1000).toISOString().slice(0, 16));
  const total = cartTotal + (isDelivery ? store.deliveryFee : 0);

  return (
    <main className="px-4 pt-4.5 pb-6">
      <div className="mb-5 flex gap-2">
        <button
          onClick={() => setFulfillment("pickup")}
          className="flex-1 rounded-xl border-2 bg-white py-3 text-sm font-semibold text-espresso"
          style={{ borderColor: !isDelivery ? "#5F5335" : "#E5DFD1" }}
        >
          Παραλαβή
        </button>
        <button
          onClick={() => setFulfillment("delivery")}
          className="flex-1 rounded-xl border-2 bg-white py-3 text-sm font-semibold text-espresso"
          style={{ borderColor: isDelivery ? "#5F5335" : "#E5DFD1" }}
        >
          Delivery στην Καβάλα
        </button>
      </div>

      {!isDelivery && (
        <>
          {storeClosedNow && (
            <div className="mb-3.5 rounded-xl bg-white px-3.5 py-3 text-[13px] text-espresso/80">
              Το κατάστημα είναι κλειστό τώρα. Διάλεξε ώρα παραλαβής μέσα στο ωράριο μας, {store.hours}.
            </div>
          )}
          <label className="mb-2 block text-[13px] font-semibold">Ώρα παραλαβής</label>
          <select
            value={pickupTime}
            onChange={(e) => setPickupTime(e.target.value)}
            required
            className={`${inputClass} mb-4.5`}
          >
            <option value="">Διάλεξε ώρα</option>
            {slots.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        </>
      )}

      {isDelivery && (
        <>
          <div className="mb-3.5 rounded-xl bg-white px-3.5 py-3 text-[13px]">
            <div>
              Ελάχιστη παραγγελία: <strong>{fmt(store.deliveryMinOrder)}</strong>
            </div>
            <div>
              Μεταφορικά: <strong>{fmt(store.deliveryFee)}</strong>
            </div>
          </div>
          <div className="mb-4.5 flex flex-col gap-2.5">
            <input
              value={address.street}
              onChange={(e) => setAddressField("street", e.target.value)}
              required
              placeholder="Διεύθυνση, αριθμός"
              className={inputClass}
            />
            <div className="flex gap-2.5">
              <input
                value={address.floor}
                onChange={(e) => setAddressField("floor", e.target.value)}
                placeholder="Όροφος"
                className={`flex-1 ${inputClass}`}
              />
              <input
                value={address.bell}
                onChange={(e) => setAddressField("bell", e.target.value)}
                placeholder="Κουδούνι"
                className={`flex-1 ${inputClass}`}
              />
            </div>
            <textarea
              value={address.notes}
              onChange={(e) => setAddressField("notes", e.target.value)}
              placeholder="Σχόλια για την παράδοση (προαιρετικό)"
              rows={2}
              className={`${inputClass} resize-y`}
            />
          </div>
        </>
      )}

      {hasCakeInCart && (
        <div className="mb-4.5 rounded-[14px] bg-white p-4">
          <h3 className="m-0 mb-3 text-[15px] font-semibold">Στοιχεία για την τούρτα</h3>
          <label className="mb-1.5 block text-[13px] font-semibold">
            Ημερομηνία και ώρα παραλαβής (τουλάχιστον 24 ώρες πριν)
          </label>
          <input
            type="datetime-local"
            value={cakeDateTime}
            onChange={(e) => setCakeDateTime(e.target.value)}
            min={cakeMin}
            required
            className={`${inputClass} mb-3`}
          />
          <label className="mb-1.5 block text-[13px] font-semibold">Μήνυμα πάνω στην τούρτα (προαιρετικό)</label>
          <input
            value={cakeMessage}
            onChange={(e) => setCakeMessage(e.target.value)}
            maxLength={40}
            placeholder="π.χ. Χρόνια πολλά Μαρία"
            className={`${inputClass} mb-3`}
          />
          <label className="mb-1.5 block text-[13px] font-semibold">Κεράκια</label>
          <div className="flex items-center gap-3.5">
            <button onClick={decCandles} className="h-8.5 w-8.5 rounded-full border border-bronze bg-white">
              −
            </button>
            <span className="min-w-5 text-center font-semibold">{candles}</span>
            <button onClick={incCandles} className="h-8.5 w-8.5 rounded-full border border-bronze bg-white">
              +
            </button>
          </div>
        </div>
      )}

      <h3 className="m-0 mb-3 text-[15px] font-semibold">Πληρωμή</h3>
      <div className="mb-3.5 flex gap-2">
        <button
          onClick={() => setPayment("cash")}
          className="flex-1 rounded-xl border-2 bg-white py-3 text-sm font-semibold"
          style={{ borderColor: payment === "cash" ? "#5F5335" : "#E5DFD1" }}
        >
          Μετρητά στην παράδοση
        </button>
        <button
          onClick={() => setPayment("card")}
          className="flex-1 rounded-xl border-2 bg-white py-3 text-sm font-semibold"
          style={{ borderColor: payment === "card" ? "#5F5335" : "#E5DFD1" }}
        >
          Κάρτα
        </button>
      </div>

      {isPayCard && (
        <>
          <div className="mb-2 flex flex-col gap-2.5">
            <input
              value={card.name}
              onChange={(e) => setCardName(e.target.value)}
              required
              placeholder="Όνομα κατόχου"
              className={inputClass}
            />
            <input
              value={card.number}
              onChange={(e) => setCardNumber(e.target.value)}
              required
              inputMode="numeric"
              maxLength={19}
              placeholder="0000 0000 0000 0000"
              className={inputClass}
            />
            <div className="flex gap-2.5">
              <input
                value={card.expiry}
                onChange={(e) => setCardExpiry(e.target.value)}
                required
                maxLength={5}
                placeholder="ΜΜ/ΕΕ"
                className={`flex-1 ${inputClass}`}
              />
              <input
                value={card.cvv}
                onChange={(e) => setCardCvv(e.target.value)}
                required
                inputMode="numeric"
                maxLength={3}
                placeholder="CVV"
                className={`flex-1 ${inputClass}`}
              />
            </div>
          </div>
          <div className="mb-4.5 text-xs text-espresso/55">
            Demo — δεν πραγματοποιείται πραγματική χρέωση σε αυτή τη φάση.
          </div>
        </>
      )}

      <div className="my-4.5 rounded-[14px] bg-white p-4">
        <div className="flex justify-between py-1.5">
          <span className="opacity-65">Υποσύνολο</span>
          <span>{fmt(cartTotal)}</span>
        </div>
        {isDelivery && (
          <div className="flex justify-between py-1.5">
            <span className="opacity-65">Μεταφορικά</span>
            <span>{fmt(store.deliveryFee)}</span>
          </div>
        )}
        <div className="mt-1.5 flex justify-between border-t border-cream pt-2.5 text-base font-bold">
          <span>Σύνολο</span>
          <span className="font-literata">{fmt(total)}</span>
        </div>
      </div>

      <button
        onClick={submitOrder}
        disabled={!canSubmitOrder}
        style={{ opacity: canSubmitOrder ? 1 : 0.55 }}
        className="w-full rounded-xl border-none bg-bronze-dark py-4 text-[15px] font-semibold text-white"
      >
        Ολοκλήρωση παραγγελίας
      </button>
    </main>
  );
}
