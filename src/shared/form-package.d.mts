export const MAX_PACKAGE_BYTES: number;
export function parsePackage( text: string ): unknown;
export function stringifyPackage( value: unknown ): string;
export function packageValidator( schema: object ): ( value: unknown ) => void;
export function packageObjects( value: unknown, schema: object, root?: object ): unknown;
