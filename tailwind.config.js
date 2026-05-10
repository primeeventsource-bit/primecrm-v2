/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{vue,ts}',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                mono: ['"JetBrains Mono"', '"IBM Plex Mono"', 'ui-monospace', 'monospace'],
            },
            colors: {
                // Floor OS — dark "command deck" palette. Used for the
                // sales-floor chrome and the Dashboard. Keep it tight:
                // one hot accent (amber), neutral surfaces, semantic
                // hits only where they earn their keep.
                deck: {
                    bg: '#0a0d14',          // page bg
                    surface: '#11151f',     // primary card
                    raised: '#161b29',      // hover / popover
                    line: '#1f2636',        // borders / dividers
                    muted: '#252c3e',       // very-muted bg fills
                    text: '#e6e9f2',        // primary text
                    soft: '#a3acc4',        // secondary text
                    dim: '#6c7793',         // tertiary text
                },
                floor: {
                    accent: '#f59e0b',      // amber-500 — the brand hit
                    accentHi: '#fbbf24',    // amber-400 — hover
                    accentLo: '#b45309',    // amber-700 — pressed
                    win: '#10b981',         // emerald — closed_won
                    lose: '#f43f5e',        // rose — closed_lost / DNC
                    info: '#38bdf8',        // sky — neutral data
                    onCall: '#10b981',
                    idle: '#a3acc4',
                    wrap: '#f59e0b',
                },
                // Dialer palette — kept for the dialer console (dark
                // already, semantically distinct from "deck").
                dialer: {
                    bg: '#0b1220',
                    panel: '#111a2e',
                    accent: '#22c55e',
                    danger: '#ef4444',
                    warning: '#f59e0b',
                    muted: '#1f2a44',
                },
            },
            animation: {
                'pulse-call': 'pulse-call 1.5s ease-in-out infinite',
                'ticker': 'ticker 60s linear infinite',
                'pulse-dot': 'pulse-dot 2s ease-in-out infinite',
            },
            keyframes: {
                'pulse-call': {
                    '0%, 100%': { opacity: '1' },
                    '50%': { opacity: '0.6' },
                },
                'pulse-dot': {
                    '0%, 100%': { transform: 'scale(1)', opacity: '1' },
                    '50%': { transform: 'scale(1.3)', opacity: '0.7' },
                },
                'ticker': {
                    '0%': { transform: 'translateY(0)' },
                    '100%': { transform: 'translateY(-50%)' },
                },
            },
        },
    },
    plugins: [require('@tailwindcss/forms')],
};
