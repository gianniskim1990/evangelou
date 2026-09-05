import { useState } from "react";
import { ThemeToggle } from "../components/ThemeToggle";
import { storePassword } from "./adminApi";

export function AdminLogin({ onSuccess }: { onSuccess: () => void }) {
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError("");
    try {
      const res = await fetch("/api/admin-login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ password }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        setError(data.error ?? "Κάτι πήγε στραβά.");
        return;
      }
      storePassword(password);
      onSuccess();
    } catch {
      setError("Δεν ήταν δυνατή η σύνδεση με τον server.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="relative flex min-h-screen items-center justify-center bg-cream px-4">
      <ThemeToggle className="absolute top-4 right-4" />
      <form onSubmit={submit} className="w-full max-w-[360px] rounded-2xl bg-surface p-8 shadow-[0_4px_24px_rgba(30,24,18,0.1)]">
        <img src="/logo-evaggelou-color.png" alt="Ζαχαροπλαστική Ευαγγέλου" className="mb-6 h-10 object-contain" />
        <h1 className="font-literata mb-1 text-lg font-semibold">Διαχείριση καταλόγου</h1>
        <p className="mb-5 text-sm text-espresso/60">Βάλε τον κωδικό διαχειριστή για να συνεχίσεις.</p>
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          placeholder="Κωδικός"
          autoFocus
          className="mb-3 w-full rounded-xl border border-espresso/20 px-3.5 py-3 text-sm"
        />
        {error && <div className="mb-3 text-sm text-maroon">{error}</div>}
        <button
          type="submit"
          disabled={submitting || password.length === 0}
          className="w-full rounded-xl border-none bg-bronze-dark py-3 text-sm font-semibold text-white disabled:opacity-50"
        >
          {submitting ? "Έλεγχος…" : "Σύνδεση"}
        </button>
      </form>
    </div>
  );
}
