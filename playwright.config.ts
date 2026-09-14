import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: './tests/e2e',
	timeout: 60000,
	workers: 1,
	use: {
		baseURL: process.env.ATF_E2E_URL || 'http://localhost:8889',
		viewport: { width: 1600, height: 1000 },
	},
} );
