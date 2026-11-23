export const SAMPLE_ONLY_MODULES: string[];
export const SAMPLE_QR_MARKERS: string[];
export function rosterMarkers(previewDataSource: string): string[];
export function sampleBundleMarkers(appDir?: string): string[];
export function sampleBundleProblems(fileName: string, text: string, markers: string[]): string[];
export function sampleGraphProblems(inputs: string[]): string[];
export function sampleGuardSelfTest(appDir?: string): string[];
