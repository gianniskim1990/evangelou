export const STORAGE_ALLOWED_FILE: string;
export const STORAGE_ALLOWED_APIS: string[];
export const STORAGE_APIS: string[];
export function stripComments(src: string): string;
export function storageProblems(path: string, src: string): string[];
export function storageSelfTest(): string[];
export function normalizeSource(src: string): string;
export const APP_ONLY_APIS: string[];
