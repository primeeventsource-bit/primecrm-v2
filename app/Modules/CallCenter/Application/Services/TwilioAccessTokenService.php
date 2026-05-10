<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Services;

use App\Modules\CallCenter\Application\DTOs\AccessTokenDto;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VideoGrant;

/**
 * Mints Twilio Video access tokens (JWTs) for the browser SDK.
 *
 * Twilio's JWT minter REQUIRES an API Key SID + Secret pair — the master
 * Account SID + Auth Token cannot mint Video JWTs. The two credential
 * pairs are intentionally separate in config/services.php so a leaked
 * Video API Key can be rotated without revoking the master token.
 *
 * Identity scheme: "{role}:{userId}" where role is a RoomParticipantRole
 * value (agent, customer, supervisor_listen, supervisor_whisper,
 * supervisor_barge). Twilio echoes the identity on every webhook and
 * frontend participant event, so the supervisor controller's
 * audio-routing decisions can read the role straight off the wire
 * without a DB lookup.
 *
 * The service does NOT touch the network — JWT construction is local
 * only. That's why we can unit-test it without a Twilio account.
 */
final class TwilioAccessTokenService
{
    public function __construct(private readonly Config $config) {}

    /**
     * Mint a JWT scoped to one optional room.
     *
     * Pinning to a room (when known) means a leaked token can't be used
     * to join arbitrary rooms — useful for supervisors who should only
     * be able to join the specific call they're supervising.
     */
    public function mint(string $identity, ?string $roomName = null, ?int $ttlMinutes = null): AccessTokenDto
    {
        $accountSid = (string) $this->config->get('services.twilio.account_sid');
        $apiKeySid = (string) $this->config->get('services.twilio.api_key_sid');
        $apiKeySecret = (string) $this->config->get('services.twilio.api_key_secret');

        if ($accountSid === '' || $apiKeySid === '' || $apiKeySecret === '') {
            throw new RuntimeException(
                'Twilio Video credentials are not configured. Set TWILIO_ACCOUNT_SID, '
                .'TWILIO_API_KEY_SID and TWILIO_API_KEY_SECRET in the environment.'
            );
        }

        $ttlMinutes ??= (int) $this->config->get('prime-connect.token.ttl_minutes', 60);
        $ttlSeconds = $ttlMinutes * 60;

        // Twilio's AccessToken takes TTL in seconds; the JWT 'exp' claim
        // is computed at toJWT() time using the system clock.
        $token = new AccessToken(
            $accountSid,
            $apiKeySid,
            $apiKeySecret,
            $ttlSeconds,
            $identity,
        );

        $grant = new VideoGrant();
        if ($roomName !== null && $roomName !== '') {
            $grant->setRoom($roomName);
        }
        $token->addGrant($grant);

        $expiresAt = (new DateTimeImmutable())->modify("+{$ttlSeconds} seconds");

        return new AccessTokenDto(
            jwt: $token->toJWT(),
            identity: $identity,
            expiresAt: $expiresAt,
        );
    }
}
