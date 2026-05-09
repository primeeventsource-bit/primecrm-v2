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
                mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
            },
            colors: {
                // Dialer palette — high-contrast, fast-scanning UI
                dialer: {
                    bg: '#0b1220',
                    panel: '#111a2e',
                    accent: '#22c55e',     // green = connected
                    danger: '#ef4444',     // red = end / drop
                    warning: '#f59e0b',    // amber = wrap-up
                    muted: '#1f2a44',
                },
            },
            animation: {
                'pulse-call': 'pulse-call 1.5s ease-in-out infinite',
            },
            keyframes: {
                'pulse-call': {
                    '0%, 100%': { opacity: '1' },
                    '50%': { opacity: '0.6' },
                },
            },
        },
    },
    plugins: [require('@tailwindcss/forms')],
};
