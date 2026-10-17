import { useState } from "react";
import { describeRecovery } from "../redemptionRecovery/viewModel";
import type { Action } from "../redemptionRecovery/machine";
import { RecoveryScreen } from "./RecoveryScreen";
import { PREVIEW_SCENARIOS } from "./previewScenarios";
import "./previewMain.css";

/**
 * UI-04N LOCAL-ONLY preview page body: a scenario picker, the real
 * RecoveryScreen and a log of the action NAMES its buttons would send. Fake
 * states only; it never makes a request.
 */
export function Preview() {
  const [scenarioId, setScenarioId] = useState(PREVIEW_SCENARIOS[0].id);
  const [log, setLog] = useState<string[]>([]);
  const [dark, setDark] = useState(false);
  const scenario = PREVIEW_SCENARIOS.find((s) => s.id === scenarioId) ?? PREVIEW_SCENARIOS[0];
  const view = describeRecovery(scenario.state);

  const choose = (id: string) => {
    setScenarioId(id);
    setLog([]);
  };
  const toggleDark = () => {
    const next = !dark;
    setDark(next);
    document.documentElement.classList.toggle("dark", next);
  };
  const onAction = (action: Action) => setLog((l) => [`onAction("${action}") — nothing is sent`, ...l].slice(0, 8));

  return (
    <div className="evc-rp">
      <header className="evc-rp__bar">
        <strong>LOCAL PREVIEW — fake data, no network, not part of the app</strong>
        <button type="button" className="evc-rp__toggle" onClick={toggleDark} aria-pressed={dark}>
          Dark mode
        </button>
      </header>
      <nav className="evc-rp__scenarios" aria-label="Preview scenarios">
        {PREVIEW_SCENARIOS.map((s) => (
          <button key={s.id} type="button" className="evc-rp__scenario" aria-pressed={s.id === scenarioId} onClick={() => choose(s.id)}>
            {s.label}
          </button>
        ))}
      </nav>
      <main className="evc-rp__stage">
        <RecoveryScreen view={view} onAction={onAction} />
      </main>
      <aside className="evc-rp__log" aria-label="Callback log">
        <strong>Callback log (mock)</strong>
        {log.length === 0 ? <p>No action pressed yet.</p> : <ol>{log.map((entry, i) => <li key={`${i}-${entry}`}>{entry}</li>)}</ol>}
      </aside>
    </div>
  );
}
