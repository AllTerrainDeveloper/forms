/** Validate a portable form without WordPress or a browser. */
import { readFileSync } from 'node:fs';
import { parsePackage, packageValidator } from '../src/shared/form-package.mjs';

try {
	const path = process.argv[ 2 ];
	if ( ! path ) throw new Error( 'Usage: npm run validate:form -- path/to/form.yaml' );
	const schema = JSON.parse( readFileSync( new URL( '../schemas/form-package-v1.schema.json', import.meta.url ), 'utf8' ) );
	packageValidator( schema )( parsePackage( readFileSync( path, 'utf8' ) ) );
	process.stdout.write( 'Valid form package. Import also checks installed field types, token safety and site dependencies.\n' );
} catch ( error ) {
	process.stderr.write( `${ error.message }\n` );
	process.exitCode = 1;
}
