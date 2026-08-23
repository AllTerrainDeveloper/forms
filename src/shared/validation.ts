/**
 * Validation presets, browser side.
 *
 * A "pattern" box asks a form builder to write a regular expression, which is
 * asking the wrong person the wrong question: nobody building an RSVP form
 * knows — or should need to know — what `^[0-9]{5}(-[0-9]{4})?$` means. A
 * preset asks the question they can answer: *what should the answer look
 * like?* An email address. A phone number. A ZIP code.
 *
 * The table here is the browser's half of a twin: `alltfo_validation_presets()`
 * in `includes/validation.php` carries the same slugs and the same patterns,
 * and `tests/fixtures/validation-cases.json` is read by both suites so the two
 * cannot drift apart silently. The browser check is a courtesy — instant, as
 * the visitor types — and the server check is the law.
 *
 * Every pattern is anchored and compiled with the `u` flag on both sides
 * (`/u` in PHP), which is what lets "letters" mean *letters* — María, Zoë,
 * Łukasz — rather than A to Z.
 */

/** One named answer shape. */
export interface ValidationPreset {
	/** The stored identifier — `field.validation` holds one of these. */
	slug: string;
	/** What the picker calls it. */
	label: string;
	/** The picker's optgroup. */
	group: string;
	/** A passing value, shown as "e.g. …" so the label needs no manual. */
	example: string;
	/** Anchored regular expression, no delimiters, compiled with `u`. */
	pattern: string;
	/** The default error when the answer does not match. */
	message: string;
	/** True when the digits must also survive the Luhn checksum. */
	luhn?: boolean;
}

/** The picker's optgroup headings, in order. */
export const VALIDATION_GROUPS = [ 'Contact', 'Numbers & codes', 'Text shape', 'Web' ];

/**
 * Every built-in preset.
 *
 * An array rather than a map because the order is the order the picker offers
 * them in, grouped by `group` in `VALIDATION_GROUPS` order.
 */
export const VALIDATION_PRESETS: ValidationPreset[] = [
	{
		slug: 'email',
		label: 'An email address',
		group: 'Contact',
		example: 'jane@example.com',
		pattern: '^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$',
		message: 'That does not look like an email address.',
	},
	{
		slug: 'phone',
		label: 'A phone number',
		group: 'Contact',
		example: '+34 612 345 678',
		pattern: '^(?=(?:[^0-9]*[0-9]){5,})\\+?[0-9 ().-]{5,24}$',
		message: 'That does not look like a phone number.',
	},
	{
		slug: 'handle',
		label: 'A username or @handle',
		group: 'Contact',
		example: '@yourname',
		pattern: '^@?[A-Za-z0-9_]{2,30}$',
		message: 'That does not look like a username.',
	},
	{
		slug: 'digits',
		label: 'Numbers only',
		group: 'Numbers & codes',
		example: '12345',
		pattern: '^[0-9]+$',
		message: 'Numbers only, please.',
	},
	{
		slug: 'decimal',
		label: 'A number, decimals allowed',
		group: 'Numbers & codes',
		example: '3.14',
		pattern: '^-?[0-9]+([.,][0-9]+)?$',
		message: 'That does not look like a number.',
	},
	{
		slug: 'price',
		label: 'A price',
		group: 'Numbers & codes',
		example: '19.99',
		pattern: '^[0-9]+([.,][0-9]{1,2})?$',
		message: 'That does not look like a price.',
	},
	{
		slug: 'zip_us',
		label: 'A ZIP code (US)',
		group: 'Numbers & codes',
		example: '90210',
		pattern: '^[0-9]{5}(-[0-9]{4})?$',
		message: 'That does not look like a ZIP code.',
	},
	{
		slug: 'postcode_uk',
		label: 'A postcode (UK)',
		group: 'Numbers & codes',
		example: 'SW1A 1AA',
		pattern: '^[A-Za-z]{1,2}[0-9][A-Za-z0-9]? ?[0-9][A-Za-z]{2}$',
		message: 'That does not look like a postcode.',
	},
	{
		slug: 'iban',
		label: 'An IBAN',
		group: 'Numbers & codes',
		example: 'DE89 3704 0044 0532 0130 00',
		pattern: '^[A-Za-z]{2}[0-9]{2}(?: ?[A-Za-z0-9]){10,32}$',
		message: 'That does not look like an IBAN.',
	},
	{
		slug: 'credit_card',
		label: 'A card number',
		group: 'Numbers & codes',
		example: '4242 4242 4242 4242',
		pattern: '^[0-9](?:[0-9 -]{9,21})?[0-9]$',
		message: 'That does not look like a card number.',
		luhn: true,
	},
	{
		slug: 'letters',
		label: 'Letters only',
		group: 'Text shape',
		example: 'María López',
		pattern: "^[\\p{L}\\p{M} .'’-]+$",
		message: 'Letters only, please.',
	},
	{
		slug: 'alphanumeric',
		label: 'Letters and numbers only',
		group: 'Text shape',
		example: 'abc123',
		pattern: '^[A-Za-z0-9]+$',
		message: 'Letters and numbers only, please.',
	},
	{
		slug: 'no_spaces',
		label: 'One word, no spaces',
		group: 'Text shape',
		example: 'one-word',
		pattern: '^\\S+$',
		message: 'No spaces allowed.',
	},
	{
		slug: 'url',
		label: 'A web address',
		group: 'Web',
		example: 'https://example.com',
		pattern: '^(https?://)?([A-Za-z0-9-]+\\.)+[A-Za-z]{2,}([/?#]\\S*)?$',
		message: 'That does not look like a web address.',
	},
	{
		slug: 'ip',
		label: 'An IP address',
		group: 'Web',
		example: '192.168.0.1',
		pattern:
			'^((25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\\.){3}(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])$',
		message: 'That does not look like an IP address.',
	},
	{
		slug: 'slug',
		label: 'A URL slug',
		group: 'Web',
		example: 'my-page-title',
		pattern: '^[a-z0-9]+(-[a-z0-9]+)*$',
		message: 'Lowercase letters, numbers and dashes only.',
	},
	{
		slug: 'hex_color',
		label: 'A hex colour',
		group: 'Web',
		example: '#3366ff',
		pattern: '^#?([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$',
		message: 'That does not look like a colour code.',
	},
];

/**
 * A preset by slug.
 *
 * @param slug The stored identifier.
 * @return The preset, or null for a slug this build does not know.
 */
export function validationPreset( slug: string ): ValidationPreset | null {
	return VALIDATION_PRESETS.find( ( preset ) => preset.slug === slug ) ?? null;
}

/**
 * Whether the digits in a value survive the Luhn checksum.
 *
 * The check that tells a plausible card number from a typo: doubling every
 * second digit from the right and summing must land on a multiple of ten.
 * Spaces and dashes are ignored, because people type card numbers in groups.
 *
 * @param value The value as typed.
 * @return Whether the checksum holds.
 */
export function luhnPasses( value: string ): boolean {
	const digits = value.replace( /[^0-9]/g, '' );

	if ( digits.length < 12 ) {
		return false;
	}

	let sum = 0;
	let double = false;

	for ( let index = digits.length - 1; index >= 0; index-- ) {
		let digit = digits.charCodeAt( index ) - 48;

		if ( double ) {
			digit *= 2;

			if ( digit > 9 ) {
				digit -= 9;
			}
		}

		sum += digit;
		double = ! double;
	}

	return 0 === sum % 10;
}

/**
 * Whether a value satisfies a preset.
 *
 * Null for an unknown slug rather than a verdict, so a form saved with a
 * preset from a newer version degrades to "not checked here" instead of
 * rejecting everything — the server, which does know its own presets, still
 * decides.
 *
 * @param slug  The preset.
 * @param value The value as typed.
 * @return True, false, or null when the slug is not a preset this build knows.
 */
export function presetPasses( slug: string, value: string ): boolean | null {
	const preset = validationPreset( slug );

	if ( ! preset ) {
		return null;
	}

	let expression: RegExp;

	try {
		expression = new RegExp( preset.pattern, 'u' );
	} catch {
		// A preset that does not compile is this table's bug, and the visitor
		// must not pay for it. The server takes the same view.
		return true;
	}

	if ( ! expression.test( value ) ) {
		return false;
	}

	if ( preset.luhn && ! luhnPasses( value ) ) {
		return false;
	}

	return true;
}
