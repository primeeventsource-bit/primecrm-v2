<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\DTOs;

use DateTimeImmutable;

/**
 * The bundle returned by TwilioAccessTokenService::mint().
 *
 * The frontend stores `jwt` in memory only (never localStorage — JWTs are
 * bearer tokens) and re-mints when `expiresAt` is within ~2 minutes.
 * `identity` is round-tripped so the UI doesn't need to re-derive the
 * "{role}:{userId}" encoding to label the local participant tile.
 */
final class AccessTokenDto
{
    public function __construct(
        public readonly string $jwt,
        public readonly string $identity,
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
