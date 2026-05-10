<?php

declare(strict_types=1);

/**
 * Prime Connect — video calling configuration.
 *
 * Voice telephony lives in config/telephony.php; Prime Connect lives
 * here so a video-only outage / config change doesn't risk the dialer
 * and so storage/retention can diverge (e.g. video composed MP4s have
 * higher per-minute storage cost than voice WAV).
 */
return [

    /*
    | Access tokens
    |
    | Twilio Video JWTs expire on a fixed clock — the room can outlast
    | the token, so the frontend silently re-mints when the expiry
    | approaches. 60 minutes is a comfortable middle ground (long enough
    | for a typical sales call, short enough that a leaked token isn't
    | a multi-hour incident).
    */
    'token' => [
        'ttl_minutes' => (int) env('PRIME_CONNECT_TOKEN_TTL_MINUTES', 60),
    ],

    /*
    | Recording storage
    |
    | Voice and video deliberately use SEPARATE disks so per-product
    | retention and billing are independent. Voice recordings live on
    | CALL_RECORDING_DISK (config/telephony.php); video compositions on
    | PRIME_CONNECT_RECORDING_DISK. Both default to s3 in production.
    |
    | retention_days is enforced by ArchiveCompositionFromTwilioJob
    | (deletes from Twilio after 24h) and a separate scheduled cleanup
    | task (deletes from our disk after retention_days). 90 days is the
    | TCPA-conservative floor; longer if your compliance posture asks
    | for it, shorter never.
    */
    'recording' => [
        'disk' => env('PRIME_CONNECT_RECORDING_DISK', 's3'),
        'path' => env('PRIME_CONNECT_RECORDING_PATH', 'prime-connect/recordings'),
        'retention_days' => (int) env('PRIME_CONNECT_RECORDING_RETENTION_DAYS', 90),
        // Hours after a room ends before we tell Twilio to delete its
        // copy. We keep the window so transient S3 upload failures can
        // be retried against the canonical Twilio source.
        'twilio_archive_after_hours' => (int) env('PRIME_CONNECT_TWILIO_ARCHIVE_HOURS', 24),
    ],

    /*
    | Webhook receiver
    |
    | Twilio Video status callbacks land at:
    |   {webhook_base}/webhooks/twilio/video
    | The base URL is shared with voice (set in config/telephony.php
    | under providers.twilio.webhook_base_url) since both need the same
    | tunnel/ngrok hostname during local development.
    */
    'webhook' => [
        'verify_signature' => (bool) env('PRIME_CONNECT_VERIFY_SIGNATURE', true),
    ],

    /*
    | Room defaults applied at create time. Group rooms are required
    | (peer-to-peer rooms can't record). recordParticipantsOnConnect
    | enables per-track recording from the moment each participant joins
    | so we never miss the first words.
    */
    'room' => [
        'type' => 'group',
        'record_participants_on_connect' => true,
        'max_participants' => (int) env('PRIME_CONNECT_MAX_PARTICIPANTS', 8),
        'status_callback_method' => 'POST',
    ],

    /*
    | Resilience knobs for the Twilio API client. The TwilioRoomService
    | wraps SDK calls with exponential-backoff retry and a cache-based
    | circuit breaker so transient 5xx from Twilio doesn't break the
    | lobby. Tune in production based on observed Twilio reliability.
    */
    'resilience' => [
        'retry_attempts' => 3,
        'retry_initial_delay_ms' => 200,
        'circuit_breaker' => [
            'failure_threshold' => 5,        // consecutive failures before opening
            'window_seconds' => 30,          // counted within this rolling window
            'cooldown_seconds' => 15,        // open → half-open after this
        ],
    ],

];
