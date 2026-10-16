export interface OutputComparison {
  same: boolean;
  onlyA: string[];
  onlyB: string[];
  changed: string[];
}
export function compare(a: Map<string, string>, b: Map<string, string>): OutputComparison;
export function selectors(css: string): Set<string>;
export function unlinkLinkOnly(path: string): boolean;
export function removeTempTree(dir: string, link: string): boolean;
