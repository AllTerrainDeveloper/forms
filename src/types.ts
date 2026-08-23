/**
 * The wire shapes.
 *
 * Each interface here is the TypeScript twin of a PHP structure: `FormSchema`
 * mirrors `alltfo_normalize_schema()`, `Field` mirrors `alltfo_normalize_field()`,
 * `Entry` mirrors `alltfo_prepare_entry()`. When one changes the other has to, and
 * the PHPUnit suite asserts the PHP side's keys so the pair cannot drift
 * silently.
 */

/** A conditional-logic rule. */
export interface LogicRule {
	field: string;
	operator: LogicOperator;
	value: string;
}

export type LogicOperator =
	| 'is'
	| 'is_not'
	| 'contains'
	| 'not_contains'
	| 'starts_with'
	| 'ends_with'
	| 'greater'
	| 'less'
	| 'greater_equal'
	| 'less_equal'
	| 'empty'
	| 'not_empty';

/** A conditional-logic block. The same shape on fields, notifications and actions. */
export interface Logic {
	enabled: boolean;
	action: 'show' | 'hide';
	match: 'all' | 'any';
	rules: LogicRule[];
}

/** One option in a choice field. */
export interface Choice {
	label: string;
	value: string;
	selected?: boolean;
	image?: number;
	points?: number;
	price?: number;
}

/** One field in a form. */
export interface Field {
	id: string;
	type: string;
	label: string;
	placeholder: string;
	hint: string;
	required: boolean;
	width: 'full' | 'half' | 'third' | 'two-thirds' | 'quarter';
	cssClass: string;
	default: FieldValue;
	choices: Choice[];
	logic: Logic;
	messages: Record< string, string >;
	prefill: string;

	/** Type-specific settings, merged from the field type's own defaults. */
	[ key: string ]: unknown;
}

/** Anything a field can hold. */
export type FieldValue = string | number | boolean | string[] | Record< string, unknown > | number[] | null;

/** Field id => value. */
export type Values = Record< string, FieldValue >;

export interface Notification {
	id: string;
	enabled: boolean;
	name: string;
	to: string;
	cc: string;
	bcc: string;
	replyTo: string;
	fromName: string;
	fromEmail: string;
	subject: string;
	message: string;
	attachFiles: boolean;
	logic: Logic;
}

/** The style keys the success screen renderer knows. */
export type SuccessStyle =
	| 'plain'
	| 'simple'
	| 'minimal'
	| 'card'
	| 'check'
	| 'confetti'
	| 'fireworks'
	| 'sparkles'
	| 'typewriter';

/** How a message confirmation looks and behaves once the form succeeds. */
export interface SuccessScreen {
	style: SuccessStyle;
	title: string;
	/** An emoji glyph; empty means the style's own default. */
	icon: string;
	/** A hex accent colour; empty means the theme's accent. */
	accent: string;
	intensity: 'low' | 'medium' | 'high';
	showButton: boolean;
	buttonLabel: string;
}

export interface Confirmation {
	id: string;
	enabled: boolean;
	name: string;
	type: 'message' | 'redirect' | 'page';
	message: string;
	url: string;
	pageId: number;
	query: string;
	success: SuccessScreen;
	logic: Logic;
}

export interface FormAction {
	id: string;
	type: string;
	enabled: boolean;
	logic: Logic;
	settings: Record< string, unknown >;
}

export interface FormSettings {
	theme: string;
	themeOverrides: Record< string, string >;
	submitLabel: string;
	labelPosition: string;
	ajax: boolean;
	progressBar: 'steps' | 'bar' | 'none';
	requireLogin: boolean;
	roles: string[];
	loginMessage: string;
	schedule: { start: string; end: string; message: string };
	limit: { total: number; perUser: number; message: string };
	spam: {
		honeypot: boolean;
		timeTrap: number;
		rateLimit: number;
		blocklist: string;
		akismet: boolean;
		challenge: boolean;
	};
	storage: {
		entries: boolean;
		ip: boolean;
		userAgent: boolean;
		retention: number;
		anonymise: boolean;
	};
	analytics: { enabled: boolean; tech: boolean };
	resume: { enabled: boolean; days: number };
	quiz: { enabled: boolean; passMark: number; showScore: boolean };
}

export interface FormSchema {
	version: number;
	fields: Field[];
	settings: FormSettings;
	notifications: Notification[];
	confirmations: Confirmation[];
	actions: FormAction[];
}

/** A form, as `/forms/<id>` returns it. */
export interface Form {
	id: number;
	title: string;
	status: string;
	modified: string;
	schema: FormSchema;
	shortcode: string;
	entries: number;
	/** Nonced front-end preview URL — where the title bar's eye button points. */
	previewUrl: string;
}

/** A row in the forms list. */
export interface FormSummary {
	id: number;
	title: string;
	status: string;
	modified: string;
	fields: number;
	theme: string;
	entries: number;
	unread: number;
	views: number;
	submissions: number;
	shortcode: string;
}

/** One submission. */
export interface Entry {
	id: number;
	formId: number;
	formTitle: string;
	title: string;
	status: string;
	date: string;
	dateHuman: string;
	starred: boolean;
	notes: number;
	values: Values;
	fields: Array< {
		id: string;
		label: string;
		type: string;
		value: FieldValue;
		formatted: string;
	} >;
	ip: string;
	userAgent: string;
	referrer: string;
	userId: number;
	spam: string;
	quiz: { score: number; total: number; percent: number; passed: boolean } | null;
	canDelete: boolean;
}

/** A field type, as the palette reads it. */
export interface FieldType {
	type: string;
	label: string;
	description: string;
	group: string;
	icon: string;
	input: boolean;
	value: string;
	choices: boolean;
	supports: string[];
	settings: Record< string, unknown >;

	/** For a composite type, the parts it can be told to show. */
	parts?: Array< { key: string; label: string } >;
}

/** A design token and the control that edits it. */
export interface ThemeToken {
	token: string;
	default: string;
	control: 'color' | 'length' | 'select' | 'text';
	options?: string[];
	unit?: string;
	label: string;
	group: string;
}

export interface Theme {
	slug: string;
	label: string;
	description: string;
	custom: boolean;
	dark: boolean;
	id: number;
	tokens: Record< string, string >;
	resolved: Record< string, string >;
}

export interface Template {
	slug: string;
	label: string;
	description: string;
	icon: string;
}

/** Everything the builder needs, from `/config`. */
/** One subscribable MailPoet list. */
export interface MailPoetList {
	id: number;
	name: string;
}

/** What `/mailpoet` answers: MailPoet's presence and what it offers. */
export interface MailPoetInfo {
	active: boolean;
	lists: MailPoetList[];
	/** The bundled MailPoet wordmark. */
	logo: string;
	/** The bundled MailPoet symbol, for compact badges. */
	symbol: string;
	/** MailPoet's lists admin when installed, the plugin installer when not. */
	adminUrl: string;
}

export interface BuilderConfig {
	fieldTypes: FieldType[];
	groups: Record< string, string >;
	tokens: ThemeToken[];
	templates: Template[];
	operators: Record< string, string >;
	countries: Record< string, string >;
	roles: Record< string, string >;
	canDelete: boolean;
	adminUrl: string;
}

/** The blob PHP prints as `window.allTerrainForms`. */
export interface RuntimeConfig {
	restUrl: string;
	wpRestUrl: string;
	nonce: string;
	adminUrl: string;
	version: string;
	canEdit: boolean;
	canRead: boolean;
	/**
	 * OpenStation's per-user developer-mode preference.
	 *
	 * A preference, never an authorisation: it decides whether a developer
	 * surface is *shown*. Everything it reveals is checked again on the server,
	 * against a capability as well as this.
	 */
	devMode: boolean;
	locale: string;
	i18n: Record< string, string >;
}

/** What `/submit` answers with. */
export interface SubmissionResult {
	success: boolean;
	errors: Record< string, string >;
	message: string;
	entry_id: number;
	confirmation: {
		type?: 'message' | 'redirect' | 'page';
		message?: string;
		url?: string;
		success?: SuccessScreen;
	};
	preview?: boolean;
}

/* -------------------------------------------------------------------------- */
/* OpenStation                                                                 */
/* -------------------------------------------------------------------------- */

/**
 * The slice of OpenStation this plugin touches.
 *
 * Declared structurally rather than imported from the `openstation` package,
 * because the shell is *optional* here: importing its types would be harmless,
 * but importing its runtime -- which is what `import { … } from 'openstation'`
 * does, since the component barrel registers every tag as a side effect --
 * would bundle the shell's component kit into a plugin that must also run on
 * sites where the shell is not installed at all.
 *
 * Everything is optional and every call site null-checks. That is the price of
 * degrading instead of throwing.
 */
export interface ShellApi {
	dragManager?: DragManagerApi;
	openWindow?: ( id: string, opts?: { source?: string } ) => boolean;
	notify?: ( opts: { title?: string; body?: string; type?: string } ) => () => void;
	confirm?: ( opts: {
		title?: string;
		message?: string;
		confirmLabel?: string;
		danger?: boolean;
	} ) => Promise< boolean >;
	fetch?: ( input: string, init?: RequestInit, opts?: { source?: string; silent?: boolean } ) => Promise< Response >;
	broadcast?: < T >( topic: string, payload: T ) => void;
	subscribe?: ( topic: string, cb: ( payload: unknown ) => void ) => () => void;
	isActive?: () => boolean;
	windowManager?: {
		open?: ( config: { id: string; baseId?: string; url: string; title: string; icon?: string } ) => unknown;
	};
	files?: {
		registerType?: ( def: Record< string, unknown > ) => void;
	};
}

/** `wp.os.dragManager`, narrowed to what this plugin uses. */
export interface DragManagerApi {
	start( opts: DragStartOpts ): DragSession | null;
	registerDropTarget( target: DropTarget ): () => void;
	isDragging(): boolean;
	recentlyEndedDrag( withinMs?: number ): boolean;
}

export interface DragPayload {
	type: string;
	source: HTMLElement;
	data: Record< string, unknown >;
	ghost?: {
		element?: HTMLElement;
		offsetX: number;
		offsetY: number;
		hint?: { hidden?: boolean; accept?: string; reject?: string; neutral?: string };
	};
}

export interface DragSession {
	readonly payload: DragPayload;
	isFinished(): boolean;
	cancel( reason?: string ): void;
}

export interface DragStartOpts {
	payload: DragPayload;
	origin: PointerEvent;
	onClickOnly?: () => void;
	onCancel?: ( reason: string ) => void;
	onCommit?: ( target: DropTarget ) => void;
}

export interface DropTarget {
	id: string;
	element: HTMLElement;
	accept( payload: DragPayload ): boolean;
	onEnter?( session: DragSession ): void;
	onLeave?( session: DragSession ): void;
	onDrop( session: DragSession, ev: { clientX: number; clientY: number } ): void | Promise< void >;
	acceptLabel?: string;
}

/**
 * The drag payload slugs this plugin emits.
 *
 * Exported so another plugin can register a drop target that accepts one --
 * drop an entry on a task board to make a task of it, drop a form onto a page
 * editor to embed it. That is the whole reason the builder uses the shell's drag
 * manager instead of its own pointer handlers.
 */
export const FIELD_PAYLOAD_TYPE = 'allterrain-forms/field';
export const FORM_PAYLOAD_TYPE = 'allterrain-forms/form';
export const ENTRY_PAYLOAD_TYPE = 'allterrain-forms/entry';

/** WP Explorer's payload for a media item, which image fields accept. */
export const MEDIA_PAYLOAD_TYPES = [ 'openstation/file', 'desktop-mode/file', 'openstation/attachment' ];

/**
 * One value the builder can offer to drop into an email or a confirmation.
 *
 * `sample` is what it resolves to on this site right now — the whole reason the
 * catalogue is fetched rather than hardcoded.
 */
export interface MergeTag {
	tag: string;
	label: string;
	hint: string;
	sample: string;
	type?: string;
}

/** Merge tags that belong together in the picker. */
export interface MergeTagGroup {
	id: string;
	label: string;
	items: MergeTag[];
	/** Shown in place of an empty list, e.g. a form with no questions yet. */
	empty?: string;
}

/* -------------------------------------------------------------------------- */
/* Analytics                                                                   */
/* -------------------------------------------------------------------------- */

/** Summary statistics for one numeric question. */
export interface NumberSummary {
	count: number;
	mean: number;
	median: number;
	min: number;
	max: number;
	/** Answer => how many gave it. Keys are strings because JSON objects are. */
	distribution: Record< string, number >;
}

/**
 * Net Promoter Score for a 0–10 question.
 *
 * Not an average — the score is the percentage of promoters minus the percentage
 * of detractors, and the passives in the middle count for nothing.
 */
export interface NpsSummary {
	responses: number;
	promoters: number;
	passives: number;
	detractors: number;
	/** -100 to 100. */
	score: number;
}

/** One question's report. */
export interface FieldReport {
	id: string;
	label: string;
	type: string;
	answered: number;
	rate: number;
	average?: number | null;
	/**
	 * What `answered` is measured against, when it is not the sampled entries.
	 * A repeater sub-field is answered per *row*, and rows outnumber people.
	 */
	of?: number;
	/** What one unit of `of` is called — "rows" — when it is not people. */
	unit?: string;
	choices: Array< { label: string; value: string; count: number; percent: number } >;
	numbers?: NumberSummary | null;
	nps?: NpsSummary | null;
}

/** Every numeric answer, grouped by one categorical answer. */
export interface Breakdown {
	id: string;
	label: string;
	groups: Array< {
		value: string;
		label: string;
		count: number;
		metrics: Array< { id: string; label: string; mean: number; nps: number | null } >;
	} >;
}

/** One class in a technology tally: a browser, a device kind, an OS. */
export interface TechRow {
	id: string;
	label: string;
	views: number;
	submissions: number;
	/** Percent of the facet's total, 0-100. */
	share: number;
	/** Percent conversion for this class, or null when no views were tallied. */
	conversion: number | null;
}

/** The aggregate device / browser / OS tallies. */
export interface TechReport {
	device: TechRow[];
	browser: TechRow[];
	os: TechRow[];
}

/** A form's whole report. */
export interface AnalyticsReport {
	views: number;
	starts: number;
	submissions: number;
	conversion: number;
	completion: number;
	unread: number;
	spam: number;
	sampled: number;
	fields: FieldReport[];
	timeline: Array< { date: string; count: number } >;
	dimensions: Array< { id: string; label: string } >;
	breakdown: Breakdown | null;
	tech: TechReport | null;
}

/** What demo data exists right now. */
export interface DemoStatus {
	formId: number;
	title: string;
	entries: number;
	target: number;
	remaining: number;
}
