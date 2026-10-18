import { useCallback, useEffect, useState, useSyncExternalStore } from "react";
import { CounterApp } from "./CounterApp";
import { CounterFlow } from "./counterFlow";
import { FakeClubServer, type Fault, type Operation, type ServerLogEntry } from "./fakeServer";
import { PreviewTabStorage } from "./previewSession";
import { abortableSleep } from "./previewTransport";
import { FAULT_LABELS, OPERATION_LABELS, SCENARIOS, type Scenario } from "./scenarios";
import "./counterPreview.css";

/**
 * UI-04O LOCAL-ONLY integrated staff counter-flow preview (page body).
 *
 * The tooling around the staff screens (scenario picker, steps, simulation
 * tools, the fake server's log and the tab store) is deliberately outside
 * the product chrome. Every scenario builds a fresh fake world: a new fake
 * server, an empty tab store and the real flow. Nothing leaves the page.
 */

const LATENCY_MS = 700;
const DEVICE_REF = "0000000000000000000000000000c0de";

function randomHex(bytes: number): string {
  const a = new Uint8Array(bytes);
  crypto.getRandomValues(a);
  return [...a].map((b) => b.toString(16).padStart(2, "0")).join("");
}

async function sha256Hex(text: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

/** Seconds from `now` to the next midnight in Europe/Athens. */
function secondsToAthensMidnight(now: Date): number {
  const parts = new Intl.DateTimeFormat("en-GB", { timeZone: "Europe/Athens", hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23" }).formatToParts(now);
  const get = (t: string) => Number(parts.find((p) => p.type === t)?.value ?? 0);
  return 86_400 - (get("hour") * 3600 + get("minute") * 60 + get("second"));
}

/** The preview's clock: real time plus a scenario / tool offset (e.g. "+1 minute", "before midnight"). */
class PreviewClock {
  private offset = 0;
  now = (): Date => new Date(Date.now() + this.offset);
  advance(ms: number): void {
    this.offset += ms;
  }
  /** Moves to `seconds` before the next Athens midnight. */
  beforeMidnight(seconds: number): void {
    this.offset += (secondsToAthensMidnight(this.now()) - seconds) * 1000;
  }
}

interface World {
  id: number;
  clock: PreviewClock;
  scenario: Scenario;
  server: FakeClubServer;
  storage: PreviewTabStorage;
  flow: CounterFlow;
}

let worlds = 0;

function createWorld(scenario: Scenario): World {
  const clock = new PreviewClock();
  const now = clock.now;
  const server = new FakeClubServer({ now });
  const storage = new PreviewTabStorage();
  scenario.setup?.({
    server,
    storage,
    setBeforeMidnight: (seconds) => clock.beforeMidnight(seconds),
  });
  const flow = new CounterFlow({
    server,
    storage,
    now,
    newId: () => crypto.randomUUID(),
    newSessionRef: () => randomHex(16),
    sha256Hex,
    latencyMs: LATENCY_MS,
    sleep: abortableSleep,
    deviceRef: DEVICE_REF,
  });
  void flow.start();
  return { id: ++worlds, clock, scenario, server, storage, flow };
}

const short = (id: string) => `…${id.slice(-6)}`;

function LogLine({ e }: { e: ServerLogEntry }) {
  return (
    <li>
      <span>#{e.seq}</span>
      <span>
        <strong>{OPERATION_LABELS[e.operation]}</strong> · {e.status === "lost" ? <span className="evc-cf__lost">χάθηκε</span> : e.status} · {e.outcome}
        {e.ids.length > 0 && (
          <>
            {" "}
            · <code>{e.ids.map(short).join(" / ")}</code>
          </>
        )}
        {e.fault && <> · προσομοίωση: {FAULT_LABELS[e.fault]}</>}
      </span>
    </li>
  );
}

const FAULT_OPERATIONS: Operation[] = ["redeem", "status", "handoff", "lookup"];

export function CounterPreview() {
  const [world, setWorld] = useState<World>(() => createWorld(SCENARIOS[0]));
  const [panel, setPanel] = useState<"steps" | "tools" | null>("steps");
  const [faultOp, setFaultOp] = useState<Operation>("redeem");
  const [fault, setFault] = useState<Fault>("lose_reply_after_commit");
  const state = useSyncExternalStore(world.flow.subscribe, world.flow.getState);
  const serverVersion = useSyncExternalStore(
    useCallback((l: () => void) => world.server.subscribe(l), [world]),
    () => world.server.log().length + world.server.pendingFaults().length * 1000,
  );
  const [, setTick] = useState(0);
  const refresh = () => setTick((t) => t + 1);
  const today = world.server.businessDate();

  useEffect(() => {
    document.documentElement.classList.remove("dark");
    document.title = "Ταμείο Club — τοπική προεπισκόπηση";
  }, []);

  const log = serverVersion >= 0 ? [...world.server.log()].reverse().slice(0, 12) : [];
  const pending = world.server.pendingFaults();
  const stored = world.storage.recoveryValue();

  const choose = (id: string) => {
    const scenario = SCENARIOS.find((s) => s.id === id) ?? SCENARIOS[0];
    setWorld(createWorld(scenario));
    setPanel("steps");
    window.scrollTo({ top: 0 });
  };

  const groups = [...new Set(SCENARIOS.map((s) => s.group))];

  return (
    <div className="evc-cf evc-ds" data-evc-preview="counter-flow-local">
      <div className="evc-cf__bar">
        <span className="evc-cf__tag">ΤΟΠΙΚΗ ΠΡΟΕΠΙΣΚΟΠΗΣΗ</span>
        <span className="evc-cf__claim">Ψεύτικα μέλη και προσομοιωμένος διακομιστής. Καμία σύνδεση με WordPress, καμία πραγματική καταχώρηση ή πληρωμή.</span>
        <label htmlFor="evc-cf-scenario" className="sr-only">Σενάριο</label>
        <select id="evc-cf-scenario" className="evc-cf__control evc-cf__select" value={world.scenario.id} onChange={(e) => choose(e.target.value)}>
          {groups.map((g) => (
            <optgroup key={g} label={g}>
              {SCENARIOS.filter((s) => s.group === g).map((s) => (
                <option key={s.id} value={s.id}>{s.title}</option>
              ))}
            </optgroup>
          ))}
        </select>
        <button type="button" className="evc-cf__control" onClick={() => choose(world.scenario.id)}>Από την αρχή</button>
        <button type="button" className="evc-cf__control" aria-expanded={panel === "steps"} onClick={() => setPanel(panel === "steps" ? null : "steps")}>Οδηγίες</button>
        <button type="button" className="evc-cf__control" aria-expanded={panel === "tools"} onClick={() => setPanel(panel === "tools" ? null : "tools")}>Εργαλεία</button>
      </div>

      {panel !== null && (
        <div className="evc-cf__panel" aria-label="Προσομοίωση">
          {panel === "steps" ? (
            <section>
              <h2>Τι να πατήσετε</h2>
              <ol className="evc-cf__steps">
                {world.scenario.steps.map((step) => (
                  <li key={step}>{step}</li>
                ))}
              </ol>
              <p className="evc-cf__expect"><strong>Τι πρέπει να ισχύει:</strong> {world.scenario.expect}</p>
            </section>
          ) : (
            <section>
              <h2>Εργαλεία προσομοίωσης</h2>
              <div className="evc-cf__tools">
                <button type="button" className="evc-cf__tool" onClick={() => void world.flow.reload()}>Ανανέωση σελίδας</button>
                <button type="button" className="evc-cf__tool" onClick={() => void world.flow.lock()}>Κλείδωμα οθόνης</button>
                <button type="button" className="evc-cf__tool" onClick={() => void world.flow.expireSession()}>Λήξη συνεδρίας</button>
                <button
                  type="button"
                  className="evc-cf__tool"
                  onClick={() => {
                    world.clock.advance(60_000);
                    refresh();
                  }}
                >
                  +1 λεπτό
                </button>
                <button
                  type="button"
                  className="evc-cf__tool"
                  aria-pressed={!world.storage.failRecoveryWrites}
                  onClick={() => {
                    world.storage.setFailRecoveryWrites(!world.storage.failRecoveryWrites);
                    refresh();
                  }}
                >
                  Αποθήκευση καρτέλας: {world.storage.failRecoveryWrites ? "αποτυγχάνει" : "λειτουργεί"}
                </button>
              </div>
              <div className="evc-cf__fault">
                <label className="sr-only" htmlFor="evc-cf-fault-op">Αίτημα</label>
                <select id="evc-cf-fault-op" value={faultOp} onChange={(e) => setFaultOp(e.target.value as Operation)}>
                  {FAULT_OPERATIONS.map((op) => (
                    <option key={op} value={op}>{OPERATION_LABELS[op]}</option>
                  ))}
                </select>
                <label className="sr-only" htmlFor="evc-cf-fault">Τι θα συμβεί</label>
                <select id="evc-cf-fault" value={fault} onChange={(e) => setFault(e.target.value as Fault)}>
                  {(Object.keys(FAULT_LABELS) as Fault[]).map((f) => (
                    <option key={f} value={f}>{FAULT_LABELS[f]}</option>
                  ))}
                </select>
                <button type="button" className="evc-cf__tool" onClick={() => world.server.schedule(faultOp, fault)}>Στο επόμενο αίτημα</button>
              </div>
              <p className="evc-cf__meta">
                Ημέρα διακομιστή (ώρα Ελλάδας): {today}
                {pending.length > 0 && <> · Προγραμματισμένα: {pending.map((p) => `${OPERATION_LABELS[p.operation]}: ${FAULT_LABELS[p.fault]}`).join("; ")}</>}
              </p>
            </section>
          )}
          {panel === "tools" && (
          <section aria-label="Αρχείο προσομοιωμένου διακομιστή">
            <h2>Αρχείο διακομιστή</h2>
            {log.length === 0 ? <p>Κανένα αίτημα ακόμη.</p> : <ul className="evc-cf__log">{log.map((e) => <LogLine key={e.seq} e={e} />)}</ul>}
          </section>
          )}
          {panel === "tools" && (
          <section aria-label="Αποθήκευση καρτέλας">
            <h2>Αποθήκευση καρτέλας (μόνο αναγνωριστικά)</h2>
            <p>Ό,τι κρατά η καρτέλα για ανάκτηση μετά από ανανέωση. Ποτέ όνομα, τηλέφωνο, QR, απόδειξη αναζήτησης ή παραγγελία.</p>
            <code className="evc-cf__store">{stored ?? "— κενό —"}</code>
          </section>
          )}
        </div>
      )}

      <CounterApp key={`${world.id}-${state.generation}`} flow={world.flow} state={state} server={world.server} today={today} />
    </div>
  );
}
