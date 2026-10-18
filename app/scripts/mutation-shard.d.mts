export interface Shard {
  index: number;
  count: number;
}
export interface MutantLike {
  id: string;
}
export type RunOutcome = "killed_tests" | "killed_timeout" | "survived" | "infrastructure";
export interface ShardReport {
  shard: string;
  expectedTotal: number | null;
  total: number;
  assigned: number;
  completed: number;
  killedByTests: number;
  killedByTimeout: number;
  killed: number;
  survivors: string[];
  infrastructure: string[];
  sourcesRestored: boolean;
  interrupted: string | null;
  complete: boolean;
  passed: boolean;
  results: Record<string, string>;
}
export function parseShard(text: string): Shard;
export function assertUniqueIds(mutants: MutantLike[]): void;
export function shardOf<T extends MutantLike>(mutants: T[], index: number, count: number): T[];
export function verifyPartition(mutants: MutantLike[], count: number): number[];
export function validatePlan<T extends MutantLike>(mutants: T[], options: { expectTotal?: number; shard?: Shard }): T[];
export function classifyRun(run: { status: number | null; signal?: string | null; error?: { code?: string } | null }): RunOutcome;
export function summarize(input: {
  shardLabel: string;
  expectTotal: number | undefined;
  total: number;
  assigned: number;
  results: Record<string, string>;
  sourcesRestored: boolean;
  interrupted?: string | null;
}): ShardReport;
export function evidenceLine(report: ShardReport): string;
export function checkCoverage(allIds: string[], reports: Array<ShardReport | null>, shardCount: number, expectTotal: number): string[];
