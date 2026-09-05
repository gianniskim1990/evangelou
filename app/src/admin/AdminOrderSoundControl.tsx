import { useAdminOrderAlerts } from "./AdminOrderAlertsProvider";

export function AdminOrderSoundControl() {
  const { soundEnabled, toggleSound, testChime } = useAdminOrderAlerts();

  return (
    <div className="fixed right-5 bottom-5 z-20 flex items-center gap-2.5 rounded-full border border-espresso/12 bg-white px-4 py-2.5 shadow-[0_4px_18px_rgba(30,24,18,0.12)]">
      <span className="text-[13px] font-semibold text-espresso">
        🔊 Ήχος παραγγελιών: <span style={{ color: soundEnabled ? "#86764F" : "#7A2E3B" }}>{soundEnabled ? "Ενεργός" : "Ανενεργός"}</span>
      </span>
      <button onClick={testChime} className="rounded-full border border-espresso/20 px-3 py-1.5 text-xs font-semibold">
        Δοκιμή ήχου
      </button>
      <button onClick={toggleSound} className="rounded-full border border-espresso/20 px-3 py-1.5 text-xs font-semibold">
        {soundEnabled ? "Απενεργοποίηση" : "Ενεργοποίηση"}
      </button>
    </div>
  );
}
