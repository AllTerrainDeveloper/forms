/** Structural adapter for OpenStation PR #816; no dependency on its checkout. */
import type { FormSchema, Form, BuilderConfig, Theme } from '../types';

export interface FormDraft { title: string; schema: FormSchema }
export interface MioArgumentError { code: string; path: string; message: string; suggestion?: string }
export interface MioTurnContext {
	turnId: string;
	windowId?: string;
	revision?: string;
	signal: AbortSignal;
	limits: Readonly< { rounds: number; calls: number; validationFailures: number; repeatedReads: number } >;
	validationFailures: number;
	validationRemaining: number;
}
export interface MioCallContext extends MioTurnContext {
	callId: string;
	idempotencyKey: string;
	effect: 'read' | 'validate' | 'write' | 'none';
}
export interface MioOperation {
	turnId: string; callId: string; idempotencyKey: string;
	ability: string; effect: 'read' | 'validate' | 'write' | 'none';
	status: 'running' | 'completed' | 'rejected' | 'confirmed' | 'unknown';
	receipt?: string;
}
export type MioOperationOutcome =
	| { effect: 'none'; status: 'completed'; data?: unknown }
	| { effect: 'none'; status: 'rejected'; errors: MioArgumentError[]; retryable: boolean; data?: unknown }
	| { effect: 'write'; status: 'confirmed'; receipt: string; data?: unknown }
	| { effect: 'write'; status: 'unknown'; data?: unknown };
export interface MioHistoryEntry { name: string; callId: string; args: unknown; result: unknown }
export interface MioHistory { messages: readonly unknown[]; help: unknown; outcomes: unknown[] }
export interface MioAbility {
	name: string;
	description: string;
	parameters: Record< string, unknown >;
	validate( args: Record< string, unknown >, context?: MioCallContext ): boolean | { ok: false; errors: MioArgumentError[]; retryable: boolean };
	/** The optional context keeps compatibility with the original API. */
	run( args: Record< string, unknown >, signal: AbortSignal, context?: MioCallContext ): unknown | Promise< unknown >;
	effect?: 'read' | 'validate' | 'write' | 'none';
	history?( entry: MioHistoryEntry, context: MioCallContext ): Record< string, unknown >;
	allowed?: () => boolean;
}
export interface MioContext {
	host: HTMLElement;
	title: string;
	windowId?: string;
	revision?(): string;
	onTurnBegin?( context: MioTurnContext ): void;
	onTurnEnd?( context: MioTurnContext ): void;
	onTurnAbort?( context: MioTurnContext ): void;
	responseActions?( context: {
		messageId: string;
		summary: Readonly< MioTurnContext & { status: 'completed' | 'aborted' | 'failed'; unknownWrites: number } >;
		operations: readonly Readonly< MioOperation >[];
	} ): readonly {
		id: string; label: string; ariaLabel?: string; icon?: string;
		emphasis?: 'primary' | 'secondary'; effect: 'read' | 'navigate';
		allowed?: () => boolean;
		run( context: { signal: AbortSignal; messageId: string; turnId: string } ): void | Promise< void >;
	}[];
	operationStatus?( operation: MioOperation, signal: AbortSignal ): Promise< MioOperationOutcome >;
	compactHistory?( history: MioHistory, context: MioTurnContext ): unknown;
	prompt(): string;
	documents: readonly { id: string; title: string; markdown: string; version?: string; topics?: readonly string[]; componentIds?: readonly string[] }[];
	abilities(): readonly MioAbility[];
}
export interface MioLease { dispose(): void; openChat(): Promise< void > }
export interface EditorSnapshot { formId: number; fingerprint: string; draft: FormDraft; busy: boolean; dirty?: boolean }
export type AssistantSavedForm = Form & { operation?: { receipt: string; key: string; revision: string } };
export interface FormAssistantHost {
	root: HTMLElement;
	read(): EditorSnapshot;
	prepare?(): Promise< void >;
	options(): { config: BuilderConfig | null; themes: Theme[] };
	apply( draft: FormDraft, mode: 'create' | 'update', snapshot: EditorSnapshot, revision: string, signal: AbortSignal, operationKey?: string ): Promise< AssistantSavedForm >;
}
