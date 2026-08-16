import { defineConfig } from 'vite';

/**
 * Four bundles, eight passes.
 *
 * They are separate because they load on entirely different schedules: `form`
 * ships to every visitor on a page with a form on it, `builder` only to somebody
 * editing one, `entries` only to somebody reading submissions, and `widget` only
 * to somebody who has put the widget on their desktop. A single bundle would
 * make every visitor download the form builder.
 *
 * Each target builds twice: `--mode development` emits the readable file
 * WordPress serves under `SCRIPT_DEBUG`, `--mode production` the minified one.
 * `emptyOutDir` is off so the second pass does not delete the first pass's
 * output — and so `builder` does not delete `form`.
 *
 * Which target a pass builds comes from `ATF_TARGET`, because Vite's library
 * mode takes one entry per config.
 */
const TARGETS = {
	form: {
		entry: 'src/form.ts',
		fileBase: 'form',
		iifeName: 'allTerrainFormsFront',
	},
	builder: {
		entry: 'src/builder.ts',
		fileBase: 'builder',
		iifeName: 'allTerrainFormsBuilder',
	},
	entries: {
		entry: 'src/entries.ts',
		fileBase: 'entries',
		iifeName: 'allTerrainFormsEntries',
	},
	widget: {
		entry: 'src/widget.ts',
		fileBase: 'widget',
		iifeName: 'allTerrainFormsWidget',
	},
	analytics: {
		entry: 'src/analytics.ts',
		fileBase: 'analytics',
		iifeName: 'allTerrainFormsAnalytics',
	},
	dock: {
		entry: 'src/dock.ts',
		fileBase: 'dock',
		iifeName: 'allTerrainFormsDock',
	},
};

export default defineConfig( ( { mode } ) => {
	const name = process.env.ATF_TARGET || 'form';
	const target = TARGETS[ name ];

	if ( ! target ) {
		throw new Error(
			`Unknown ATF_TARGET "${ name }". Expected one of: ${ Object.keys( TARGETS ).join( ', ' ) }.`
		);
	}

	const isProd = mode === 'production';

	return {
		build: {
			outDir: 'assets/js',
			emptyOutDir: false,
			target: 'es2020',
			minify: isProd ? 'esbuild' : false,
			sourcemap: false,
			lib: {
				entry: target.entry,
				formats: [ 'iife' ],
				name: target.iifeName,
				fileName: () => `${ target.fileBase }${ isProd ? '.min' : '' }.js`,
			},
		},
		test: {
			environment: 'jsdom',
			include: [ 'tests/vitest/**/*.test.ts' ],
		},
	};
} );
