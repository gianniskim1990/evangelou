import { createRequire } from "node:module";
import { join } from "node:path";

/**
 * jsdom test harness (UI-04N). react-dom looks at `window` when it LOADS, so
 * the DOM globals are installed first and react-dom/client is imported only
 * afterwards (dynamic import). Everything is local and deterministic: no
 * network, no timers, no wall clock.
 */

const appDir = process.env.EVC_APP_DIR;

export interface DomHarness {
  window: Window & typeof globalThis;
  document: Document;
  stage: HTMLElement;
  /** Renders `ui` into the stage inside act(). */
  render(ui: unknown): Promise<void>;
  /** Unmounts and starts a fresh root (one DOM per test file; react-dom loads once). */
  reset(): Promise<void>;
  click(el: Element): Promise<void>;
  focus(el: HTMLElement): Promise<void>;
  /** Network probes: every call is recorded, none is performed. */
  network: { fetch: unknown[]; xhr: unknown[]; beacon: unknown[]; websocket: unknown[] };
  dispose(): void;
}

const GLOBAL_KEYS = ["window", "document", "navigator", "HTMLElement", "Node", "Event", "MouseEvent", "KeyboardEvent", "FocusEvent", "getComputedStyle", "IS_REACT_ACT_ENVIRONMENT"];

export async function createDom(): Promise<DomHarness> {
  if (!appDir) throw new Error("EVC_APP_DIR is set by the test runner");
  const { JSDOM } = createRequire(join(appDir, "package.json"))("jsdom") as typeof import("jsdom");
  const dom = new JSDOM('<!doctype html><html lang="el"><body><div id="root"></div></body></html>', { url: "http://localhost/" });
  const win = dom.window as unknown as Window & typeof globalThis;
  const g = globalThis as Record<string, unknown>;
  const saved = new Map<string, PropertyDescriptor | undefined>();
  for (const key of GLOBAL_KEYS) saved.set(key, Object.getOwnPropertyDescriptor(globalThis, key));
  const install = (key: string, value: unknown) => Object.defineProperty(globalThis, key, { value, configurable: true, writable: true });
  for (const key of GLOBAL_KEYS) if (key !== "IS_REACT_ACT_ENVIRONMENT") install(key, (win as unknown as Record<string, unknown>)[key]);
  install("IS_REACT_ACT_ENVIRONMENT", true);

  const network = { fetch: [] as unknown[], xhr: [] as unknown[], beacon: [] as unknown[], websocket: [] as unknown[] };
  const probe = (list: unknown[]) => (...args: unknown[]) => {
    list.push(args);
    throw new Error("network access is forbidden in the recovery screen tests");
  };
  const savedNetwork = { fetch: Object.getOwnPropertyDescriptor(globalThis, "fetch"), xhr: Object.getOwnPropertyDescriptor(globalThis, "XMLHttpRequest"), ws: Object.getOwnPropertyDescriptor(globalThis, "WebSocket") };
  install("fetch", probe(network.fetch));
  install("XMLHttpRequest", function XhrProbe() {
    network.xhr.push([]);
    throw new Error("network access is forbidden in the recovery screen tests");
  });
  install("WebSocket", function WsProbe() {
    network.websocket.push([]);
    throw new Error("network access is forbidden in the recovery screen tests");
  });
  (win as unknown as Record<string, unknown>).fetch = g.fetch;
  (win.navigator as unknown as Record<string, unknown>).sendBeacon = probe(network.beacon);

  // React's act() opens a MessageChannel for every flush and never closes it; the ref'd
  // ports would keep the Node test process alive after the last test. Track every channel
  // and close them in reset() / dispose().
  const RealChannel = globalThis.MessageChannel;
  const channels: MessageChannel[] = [];
  class TrackedChannel extends RealChannel {
    constructor() {
      super();
      channels.push(this);
    }
  }
  install("MessageChannel", TrackedChannel);
  const closeChannels = () => {
    for (const ch of channels.splice(0)) {
      ch.port1.close();
      ch.port2.close();
    }
  };
  const { act } = await import("react");
  const { createRoot } = await import("react-dom/client");
  const stage = win.document.getElementById("root") as HTMLElement;
  let root = createRoot(stage);

  return {
    window: win,
    document: win.document,
    stage,
    network,
    async render(ui) {
      await act(async () => {
        root.render(ui as never);
      });
    },
    async reset() {
      await act(async () => {
        root.unmount();
      });
      stage.textContent = "";
      closeChannels();
      root = createRoot(stage);
      network.fetch.length = network.xhr.length = network.beacon.length = network.websocket.length = 0;
    },
    async click(el) {
      await act(async () => {
        (el as HTMLElement).dispatchEvent(new win.MouseEvent("click", { bubbles: true, cancelable: true }));
      });
    },
    async focus(el) {
      await act(async () => {
        el.focus();
      });
    },
    dispose() {
      for (const [key, descriptor] of saved) {
        if (descriptor) Object.defineProperty(globalThis, key, descriptor);
        else delete g[key];
      }
      const restore = (key: string, d: PropertyDescriptor | undefined) => (d ? Object.defineProperty(globalThis, key, d) : delete g[key]);
      restore("fetch", savedNetwork.fetch);
      restore("XMLHttpRequest", savedNetwork.xhr);
      restore("WebSocket", savedNetwork.ws);
      closeChannels();
      install("MessageChannel", RealChannel);
      win.close();
    },
  };
}
