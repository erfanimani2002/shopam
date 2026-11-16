<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Login rate limiting on the login_attempts table.
 *  - per ACCOUNT (shop slug + email/phone): max N failures within the lock window
 *  - per IP: max M failures within the same window (Iranian mobile users often
 *    share one IP, so the IP limit is much higher than the account limit)
 * Successful login deletes that account's failure rows ("release").
 */
final class RateLimiter
{
    /**
     * Throws AUTH_LOCKED when the account or the IP is currently locked.
     */
    public static function assertCanAttempt(string $scopeKey, string $identifier, string $ip): void
    {
        $db = Database::get();
        $windowStart = date('Y-m-d H:i:s', time() - self::lockMinutes() * 60);

        $accountKey = self::accountKey($scopeKey, $identifier);
        $accountFails = (int) $db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND success = 0 AND created_at >= ?',
            [$accountKey, $windowStart]
        );
        if ($accountFails >= self::maxPerAccount()) {
            throw new ApiException('AUTH_LOCKED', 429);
        }

        $ipFails = (int) $db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?',
            [$ip, $windowStart]
        );
        if ($ipFails >= self::maxPerIp()) {
            throw new ApiException('AUTH_LOCKED', 429);
        }
    }

    /** Record an attempt; on success, release the account's failure rows. */
    public static function record(string $scopeKey, string $identifier, string $ip, bool $success): void
    {
        $db = Database::get();
        $db->insert('login_attempts', [
            'identifier' => self::accountKey($scopeKey, $identifier),
            'ip'         => $ip,
            'success'    => $success ? 1 : 0,
        ]);
        if ($success) {
            $db->execute(
                'DELETE FROM login_attempts WHERE identifier = ? AND success = 0',
                [self::accountKey($scopeKey, $identifier)]
            );
        }
        self::cleanup();
    }

    /** Opportunistic purge of old rows (called on every record). */
    private static function cleanup(): void
    {
        $days = (int) Config::get('auth.attempt_history_days', 30);
        Database::get()->execute(
            'DELETE FROM login_attempts WHERE created_at < ?',
            [date('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }

    private static function accountKey(string $scopeKey, string $identifier): string
    {
        return $scopeKey . '|' . $identifier;
    }

    private static function lockMinutes(): int
    {
        return (int) Config::get('auth.account_lock_minutes', 15);
    }

    private static function maxPerAccount(): int
    {
        return (int) Config::get('auth.max_attempts_per_account', 5);
    }

    private static function maxPerIp(): int
    {
        return (int) Config::get('auth.max_attempts_per_ip', 30);
    }
}
