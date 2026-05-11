/**
 * Tiny shared helpers for the supervisor war room panels.
 *
 * Lives next to the components that use them — they're not generic enough
 * to deserve a top-level utils home, and keeping them here makes the panel
 * directory self-contained.
 */

/**
 * "3m" / "47s" / "1h 12m" — kept short so it fits inline next to a name.
 * A supervisor only cares about magnitude, not seconds-precision.
 */
export function shortDuration(fromIso: string | null | undefined, nowMs?: number): string {
    if (!fromIso) return '—';
    const start = Date.parse(fromIso);
    if (Number.isNaN(start)) return '—';
    const seconds = Math.max(0, Math.floor(((nowMs ?? Date.now()) - start) / 1000));
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    const remMin = minutes - hours * 60;
    return remMin > 0 ? `${hours}h ${remMin}m` : `${hours}h`;
}

/** "10:37" — 24h, no seconds. The ticker and feeds use this. */
export function shortClock(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

/**
 * Up to 2 letters of a display name; falls back to the first 2 chars of
 * the agent UUID so the avatar tile is never blank.
 */
export function initials(name: string | null | undefined, fallback: string): string {
    const source = (name ?? '').trim();
    if (!source) return fallback.slice(0, 2).toUpperCase();
    const parts = source.split(/\s+/).slice(0, 2);
    return parts.map((p) => p[0]).join('').toUpperCase();
}

/**
 * "$3.2k" / "$1.4M" / "$420" — compact dollars for tight tile real estate.
 */
export function moneyShort(value: number | null | undefined): string {
    const n = value ?? 0;
    if (n >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`;
    if (n >= 1_000) return `$${(n / 1_000).toFixed(1)}k`;
    return `$${Math.round(n)}`;
}

/**
 * Stable colour for an avatar circle, derived from the agent id.
 *   Six muted hues that read on the dark deck without competing with the
 *   semantic status colours (which carry the meaningful signal).
 */
const AVATAR_TONES = [
    'bg-sky-700/60 text-sky-100',
    'bg-violet-700/60 text-violet-100',
    'bg-teal-700/60 text-teal-100',
    'bg-fuchsia-700/60 text-fuchsia-100',
    'bg-indigo-700/60 text-indigo-100',
    'bg-cyan-700/60 text-cyan-100',
] as const;

export function avatarTone(agentId: string): string {
    let hash = 0;
    for (let i = 0; i < agentId.length; i++) hash = (hash * 31 + agentId.charCodeAt(i)) >>> 0;
    return AVATAR_TONES[hash % AVATAR_TONES.length];
}
