import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import contract from '../../schemas/form-package-v1.schema.json';
import { parsePackage, stringifyPackage, packageValidator, packageObjects, MAX_PACKAGE_BYTES } from '../../src/shared/form-package.mjs';

const example = readFileSync( 'docs/examples/contact-form.yaml', 'utf8' );
const validate = packageValidator( contract );
const fixture = () => parsePackage( example ) as Record< string, any >;

describe( 'portable form YAML', () => {
	it( 'validates the documented model and preserves multiline text, Unicode, booleans and numeric-looking strings', () => {
		const value = fixture();
		value.form.title = 'Café ✨: contact';
		value.form.schema.fields[ 0 ].default = '00123';
		value.form.schema.fields[ 1 ].hint = 'false';
		validate( value );
		const yaml = stringifyPackage( value );
		expect( yaml ).toContain( 'message: |-' );
		expect( yaml ).toContain( 'base: clean' );
		expect( parsePackage( yaml ) ).toEqual( value );
		expect( parsePackage( JSON.stringify( value ) ) ).toEqual( value );
	} );

	it.each( [
		'a: 1\na: 2',
		'a: [broken',
		'---\na: 1\n---\nb: 2',
		'a: &foo hello\nb: *foo',
		'a: !unknown hello',
		'a: !!str hello',
		'a: .nan',
		'a: .inf',
		'a: 9007199254740993',
		'__proto__: { polluted: true }',
		'constructor: { prototype: {} }',
		'%YAML 1.1\n---\na: yes',
	] )( 'rejects ambiguous or unsupported YAML: %s', ( text ) => {
		expect( () => parsePackage( text ) ).toThrow();
	} );

	it( 'rejects oversized and deeply nested documents', () => {
		expect( () => parsePackage( 'x'.repeat( MAX_PACKAGE_BYTES + 1 ) ) ).toThrow( /limit/ );
		expect( () => parsePackage( '['.repeat( 70 ) + '0' + ']'.repeat( 70 ) ) ).toThrow( /deep/ );
	} );

	it.each( [
		( value: any ) => { value.formatVersion = 2; },
		( value: any ) => { value.form.schema.settings.ajax = 'false'; },
		( value: any ) => { value.theme.tokens.accent = 123; },
		( value: any ) => { value.form.schema.fields[ 1 ].id = 'name'; },
		( value: any ) => { value.form.schema.fields[ 0 ].logic = { rules: [ { field: 'missing', operator: 'is' } ] }; },
		( value: any ) => { value.form.schema.fields[ 0 ].choices = 'bad'; },
		( value: any ) => { value.theme.toknes = {}; },
	] )( 'rejects structurally invalid documents', ( change ) => {
		const value = fixture();
		change( value );
		expect( () => validate( value ) ).toThrow();
	} );

	it( 'repairs only schema-declared PHP empty maps, retaining empty field/choice lists', () => {
		const value = fixture();
		value.theme.tokens = [];
		value.form.schema.settings.themeOverrides = [];
		value.form.schema.fields[ 0 ].messages = [];
		value.form.schema.fields[ 0 ].choices = [];
		value.form.schema.actions = [ { id: 'a1', type: 'webhook', settings: [] } ];
		const fixed = packageObjects( value, contract ) as typeof value;
		validate( fixed );
		expect( fixed.theme.tokens ).toEqual( {} );
		expect( fixed.form.schema.fields[ 0 ].messages ).toEqual( {} );
		expect( fixed.form.schema.fields[ 0 ].choices ).toEqual( [] );
		expect( fixed.form.schema.actions[ 0 ].settings ).toEqual( {} );
		expect( fixed.assets ).toEqual( [] );
	} );
} );
