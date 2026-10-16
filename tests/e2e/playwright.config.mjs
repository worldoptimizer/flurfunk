// End-to-end tests against a running WordPress with Flurfunk active.
//   FF_BASE        site URL (default http://localhost:9400)
//   FF_TEAM, FF_TEAM_PASS, FF_GUEST, FF_GUEST_PASS   two accounts (team and guest with an own post)
//   FF_QUERY       extra query for admin URLs, e.g. "desktop_mode_classic=1" to skip OpenStation
import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: '.',
	timeout: 180_000,
	expect: { timeout: 30_000 },
	workers: 1,
	reporter: [ [ 'list' ] ],
	use: { baseURL: process.env.FF_BASE || 'http://localhost:9400', locale: 'de-DE', viewport: { width: 1280, height: 800 }, actionTimeout: 30_000 },
} );
