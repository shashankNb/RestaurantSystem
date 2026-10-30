<?php

namespace App\Delivery\DoorDash;

/**
 * The token every Drive API call carries: a JWT signed with HS256, keyed with the access
 * key's signing secret (which DoorDash hands out base64url-encoded), naming the developer and
 * the key, with DoorDash's own "dd-ver" header. Each one lasts 5 minutes.
 */
final class DoorDashJwt
{
    public static function make(string $developerId, string $keyId, string $signingSecret, int $issuedAt): string
    {
        $header = self::encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'dd-ver' => 'DD-JWT-V1']));
        $claims = self::encode((string) json_encode([
            'aud' => 'doordash',
            'iss' => $developerId,
            'kid' => $keyId,
            'exp' => $issuedAt + 300,
            'iat' => $issuedAt,
        ]));
        $key = (string) base64_decode(strtr($signingSecret, '-_', '+/'));

        return "{$header}.{$claims}.".self::encode(hash_hmac('sha256', "{$header}.{$claims}", $key, true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
