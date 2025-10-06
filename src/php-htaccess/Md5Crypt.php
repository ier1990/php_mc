<?php

/**
 * Md5Crypt2 – MD5‑based password hashing compatible with Unix `crypt(3)` and Apache `.htpasswd`.
 *
 * The implementation follows the original FreeBSD algorithm.
 * It is intentionally written in a straightforward, procedural style so that it can be
 * understood without having to read the original C source.
 *
 * @author Dennis Riehle <selfhtml@riehle-web.com>
 */
class Md5Crypt2
{
    /**
     * Base64 character set used by the algorithm.
     *
     * @var string
     */
    public static $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /* --------------------------------------------------------------------
     * Public API
     * -------------------------------------------------------------------- */

    /**
     * Generate an Apache compatible password hash.
     *
     * @param string      $password Plain‑text password.
     * @param string|null $salt     Optional salt. If omitted a random one is generated.
     *
     * @return string The resulting hash in the form `$apr1$<salt>$<hash>`.
     */
    public static function apache(string $password, ?string $salt = null): string
    {
        return self::unix($password, $salt, '$apr1$');
    }

    /**
     * Generate a Unix `crypt(3)` compatible password hash.
     *
     * @param string      $password   Plain‑text password.
     * @param string|null $salt       Optional salt. If omitted a random one is generated.
     * @param string      $magic      Magic prefix (`$1$` for MD5‑crypt, `$apr1$` for Apache).
     *
     * @return string The resulting hash in the form `<magic><salt>$<hash>`.
     */
    public static function unix(string $password, ?string $salt = null, string $magic = '$1$'): string
    {
        // Normalise and create salt if necessary.
        $salt = self::prepareSalt($salt, $magic);

        // 1. Initial context: password + magic + salt
        $ctx = $password . $magic . $salt;

        // 2. First hash (pw + salt + pw)
        $final = pack('H*', md5($password . $salt . $password));

        // 3. Append the first hash to the context as many times as needed
        for ($pl = strlen($password); $pl > 0; $pl -= 16) {
            $ctx .= substr($final, 0, min(16, $pl));
        }

        // 4. Weird transformation loop (adds password length bits)
        self::weirdTransformLoop($ctx, $password);

        // 5. Second hash of the context
        $final = pack('H*', md5($ctx));

        // 6. Iterated hashing – 1000 rounds
        for ($i = 0; $i < 1000; ++$i) {
            $final = self::iteratedHash(
                $password,
                $salt,
                $final,
                $i
            );
        }

        // 7. Final base64‑like transformation to produce the hash string.
        $hash = self::finalTransform($final);

        return $magic . $salt . '$' . $hash;
    }

    /* --------------------------------------------------------------------
     * Internal helpers
     * -------------------------------------------------------------------- */

    /**
     * Normalise the supplied salt or generate a random one.
     *
     * @param string|null $salt   Provided salt (may contain magic prefix).
     * @param string      $magic  Magic prefix to strip if present.
     *
     * @return string The final 8‑character salt.
     */
    protected static function prepareSalt(?string $salt, string $magic): string
    {
        if ($salt !== null) {
            // Strip magic prefix if it is part of the supplied salt.
            if (strncmp($salt, $magic, strlen($magic)) === 0) {
                $salt = substr($salt, strlen($magic));
            }

            // Salt may contain additional `$` characters – keep only the first part.
            $parts = explode('$', $salt, 2);
            return substr($parts[0], 0, 8);
        }

        // No salt supplied – generate a random one of length 8.
        $itoa64 = self::$itoa64;
        $randomSalt = '';
        @mt_srand((float) (microtime(true) * 10000000));

        while (strlen($randomSalt) < 8) {
            $randomSalt .= $itoa64[mt_rand(0, strlen($itoa64) - 1)];
        }

        return $randomSalt;
    }

    /**
     * Perform the "weird" transformation loop that mixes password length bits.
     *
     * @param string &$ctx      The context string to be appended to.
     * @param string $password  The original password.
     */
    protected static function weirdTransformLoop(string &$ctx, string $password): void
    {
        for ($i = strlen($password); $i > 0; $i >>= 1) {
            if ($i & 1) {
                // Append a null byte.
                $ctx .= pack('C', 0);
            } else {
                // Append the first character of the password.
                $ctx .= $password[0];
            }
        }
    }

    /**
     * Perform one round of the iterative hashing algorithm.
     *
     * @param string $password The original password.
     * @param string $salt     The salt used for this hash.
     * @param string $final    The current intermediate hash (binary).
     * @param int    $round    Current round number (0‑based).
     *
     * @return string New intermediate hash (binary).
     */
    protected static function iteratedHash(string $password, string $salt, string $final, int $round): string
    {
        $ctx = '';

        // 1. If the round is odd, start with the password; otherwise with the previous hash.
        $ctx .= ($round & 1) ? $password : substr($final, 0, 16);

        // 2. Append salt on rounds where (round % 3) != 0
        if (($round % 3) !== 0) {
            $ctx .= $salt;
        }

        // 3. Append password on rounds where (round % 7) != 0
        if (($round % 7) !== 0) {
            $ctx .= $password;
        }

        // 4. Finish with the other part again.
        $ctx .= ($round & 1) ? substr($final, 0, 16) : $password;

        return pack('H*', md5($ctx));
    }

    /**
     * Convert a binary hash into the final base64‑like string used by MD5‑crypt.
     *
     * @param string $final The 16‑byte binary digest from the last round.
     *
     * @return string The encoded hash part (22 characters).
     */
    protected static function finalTransform(string $final): string
    {
        // Helper to convert three bytes into four base64 chars (PHP 7.4 compatible).
        $to64 = static function (int $value, int $count): string {
            return self::to64($value, $count);
        };

        // Build the 22‑character string in the order specified by the algorithm.
        return
            $to64((ord($final[0]) << 16) | (ord($final[6]) << 8) | ord($final[12]), 4) .
            $to64((ord($final[1]) << 16) | (ord($final[7]) << 8) | ord($final[13]), 4) .
            $to64((ord($final[2]) << 16) | (ord($final[8]) << 8) | ord($final[14]), 4) .
            $to64((ord($final[3]) << 16) | (ord($final[9]) << 8) | ord($final[15]), 4) .
            $to64((ord($final[4]) << 16) | (ord($final[10]) << 8) | ord($final[5]), 4) .
            $to64(ord($final[11]), 2);
    }

    /**
     * Convert a value to the custom base64 alphabet.
     *
     * @param int $value The integer value to encode.
     * @param int $count Number of characters to produce.
     *
     * @return string Encoded string.
     */
    protected static function to64(int $value, int $count): string
    {
        $itoa64 = self::$itoa64;
        $result = '';

        while (--$count >= 0) {
            $result .= $itoa64[$value & 0x3F];
            $value >>= 6;
        }

        return $result;
    }
}
