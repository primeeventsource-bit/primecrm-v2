import { defineConfig } from 'vitest/config';
import path from 'node:path';

// Vitest config — kept separate from vite.config.ts so the test runner
// doesn't load the Laravel + Vue Vite plugins (they pull in the dev
// server / HMR machinery we don't need under test, and the Laravel
// plugin errors if `public/hot` isn't writable).
//
// Tests live next to the code they exercise, named `*.test.ts`.
// Today the suite is pure helpers (resources/js/Components/**/*.ts
// algorithmic logic). Component-mounting tests can be added later
// with @vue/test-utils + jsdom; we don't pull them in yet because
// no test needs them and toolchain weight isn't free.
export default defineConfig({
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
        globals: false,
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
});
