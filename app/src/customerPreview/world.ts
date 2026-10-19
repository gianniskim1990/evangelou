import { FakeCustomerServer } from "./customerServer";
import { SCENARIO_START, type Scenario } from "./scenarios";

/* CUSTOMER-01 LOCAL-ONLY customer journey: one scenario world (simulated clock + simulated server). */

/** The simulated clock: starts at the scenario instant and moves only with the tools. */
export class SimClock {
  private t: number;
  constructor(start: number) {
    this.t = start;
  }
  now = (): number => this.t;
  set(t: number): void {
    this.t = t;
  }
  advance(ms: number): void {
    this.t += ms;
  }
}

export type World = { id: number; scenario: Scenario; clock: SimClock; server: FakeCustomerServer };

let worlds = 0;

export function createWorld(scenario: Scenario): World {
  const clock = new SimClock(SCENARIO_START);
  const server = new FakeCustomerServer(clock.now, scenario.seed());
  return { id: ++worlds, scenario, clock, server };
}
