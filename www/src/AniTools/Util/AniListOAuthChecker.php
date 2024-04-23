<?php

declare(strict_types=1);

namespace AniTools\Util;

final class AniListOAuthChecker
{
    /** @var array<int, User> */
    private static array $verified = [];
    private static ?AniListClient $aniListClient = null;

    /** @param array<int, string> $authorizationHeader */
    public static function verify(array $authorizationHeader): User
    {
        if (self::$aniListClient === null) {
            self::$aniListClient = new AniListClient();
        }

        // Split token and "Bearer"
        $exp = explode(' ', $authorizationHeader[0]);
        $token = $exp[1];
        $parts = explode('.', $token);
        $payload = json_decode(base64_decode($parts[1]), true);
        $userId = (int) $payload['sub'];
        // Skip requests to AL if the token has already been verified
        if (isset(self::$verified[$userId])) {
            return self::$verified[$userId];
        }

        // Check with AL API whether the token is legit and retrieve the username
        $result = self::$aniListClient->request('query { Viewer { name } }', [], $token);
        // In case of an error, forward it
        if (isset($result['errors'])) {
            throw new \UnexpectedValueException($result['errors'][0]['message']);
        }

        $u = new User();
        $u->id = $userId;
        $u->userName = $result['data']['Viewer']['name'];

        self::$verified[$userId] = $u;

        return $u;
    }
}
