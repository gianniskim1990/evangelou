import { useApp } from "../AppContext";
import { useSettings } from "../SettingsContext";

const STEP_STATUSES = ["new", "in_progress", "completed"] as const;

export function Status() {
  const { order } = useApp();
  const { settings } = useSettings();

  if (!order) return null;

  if (order.status === "cancelled") {
    return (
      <main className="px-5 pt-9 pb-9 text-center">
        <div className="mb-7.5">
          <div className="text-[13px] opacity-60">Παραγγελία</div>
          <div className="font-literata text-[19px] font-semibold">{order.orderNumber}</div>
        </div>
        <div className="mb-2 font-literata text-lg font-semibold text-maroon">Η παραγγελία ακυρώθηκε</div>
        <div className="mb-6.5 text-sm text-espresso/70">
          Αν αυτό είναι απρόσμενο, καλέσε μας για να δούμε τι έγινε.
        </div>
        <div className="text-[13.5px] opacity-70">
          Για οποιαδήποτε ερώτηση: <a href={`tel:${settings.phoneHref}`}>{settings.phone}</a>
        </div>
      </main>
    );
  }

  const isDelivery = order.fulfillment === "delivery";
  const stage = Math.max(0, STEP_STATUSES.indexOf(order.status as (typeof STEP_STATUSES)[number]));
  const labels = ["Ελήφθη", "Ετοιμάζεται", isDelivery ? "Στον δρόμο" : "Έτοιμη για παραλαβή"];
  const notes = [
    "Το μαγαζί έλαβε την παραγγελία σου.",
    "Ο ζαχαροπλάστης ετοιμάζει την παραγγελία σου.",
    isDelivery ? "Ο διανομέας είναι καθ᾽ οδόν." : "Η παραγγελία σου σε περιμένει στο μαγαζί.",
  ];

  return (
    <main className="px-5 pt-9 pb-9">
      <div className="mb-7.5 text-center">
        <div className="text-[13px] opacity-60">Παραγγελία</div>
        <div className="font-literata text-[19px] font-semibold">{order.orderNumber}</div>
      </div>

      <div className="flex flex-col">
        {labels.map((label, i) => {
          const done = i < stage;
          const active = i === stage;
          const hasLine = i < labels.length - 1;
          const circleBg = i <= stage ? "#5F5335" : "#E5DFD1";
          const lineBg = i < stage ? "#5F5335" : "#E5DFD1";
          return (
            <div key={label} className="flex gap-3.5">
              <div className="flex flex-col items-center">
                <div
                  className="flex h-[30px] w-[30px] flex-none items-center justify-center rounded-full text-white"
                  style={{ background: circleBg }}
                >
                  {done && (
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M4 12l5 5L20 6" />
                    </svg>
                  )}
                </div>
                {hasLine && <div className="min-h-8.5 w-0.5 flex-1" style={{ background: lineBg }} />}
              </div>
              <div className="pb-8.5">
                <div className="text-[15px] font-semibold text-espresso">{label}</div>
                {active && <div className="mt-0.5 text-[12.5px] text-bronze">{notes[i]}</div>}
              </div>
            </div>
          );
        })}
      </div>

      <div className="mt-2.5 text-center text-[13.5px] opacity-70">
        Για οποιαδήποτε ερώτηση: <a href={`tel:${settings.phoneHref}`}>{settings.phone}</a>
      </div>
    </main>
  );
}
