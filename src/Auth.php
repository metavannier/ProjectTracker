<?php
declare(strict_types=1);

namespace ProjectTracker;

/**
 * Password login with server-side sessions stored in the database.
 *
 *  - Sessions survive a restart of the container (the previous version kept
 *    them in memory, so every restart logged everybody out).
 *  - Same rule as before: a session expires after SESSION_LIFETIME_HOURS
 *    (default 8 h) without activity. The automatic background refresh of the
 *    project list does NOT count as activity.
 *  - The cookie is HttpOnly + SameSite=Strict; only an HMAC of the token is
 *    stored in the database.
 */
final class Auth
{
    public const COOKIE = 'pt_session';

    public function __construct(private readonly Database $db, private readonly Config $config)
    {
    }

    /** @param bool $extend true when the request is a user action (restarts the inactivity countdown) */
    public function check(bool $extend): bool
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $pdo = $this->db->pdo();
        if ($extend) {
            $stmt = $pdo->prepare(
                'UPDATE sessions SET expires_at = UTC_TIMESTAMP() + INTERVAL ? SECOND
                  WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()'
            );
            $stmt->execute([$this->config->sessionLifetime, $this->hash($token)]);
            $valid = $stmt->rowCount() > 0;
            if ($valid) {
                $this->sendCookie($token, time() + $this->config->sessionLifetime);
            }
            return $valid;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM sessions WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()');
        $stmt->execute([$this->hash($token)]);
        return $stmt->fetchColumn() !== false;
    }

    public function login(string $password): bool
    {
        // Constant-time comparison to avoid timing attacks
        if (!hash_equals($this->config->password, $password)) {
            return false;
        }
        $token = bin2hex(random_bytes(32));
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO sessions (token_hash, created_at, expires_at)
             VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? SECOND)'
        )->execute([$this->hash($token), $this->config->sessionLifetime]);
        // Housekeeping: forget sessions that expired more than a day ago
        $pdo->exec('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');

        $this->sendCookie($token, time() + $this->config->sessionLifetime);
        return true;
    }

    public function logout(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($token) && $token !== '') {
            $this->db->pdo()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$this->hash($token)]);
        }
        $this->sendCookie('', time() - 3600);
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->config->sessionSecret);
    }

    private function sendCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function isHttps(): bool
    {
        return match ($this->config->cookieSecure) {
            'true' => true,
            'false' => false,
            default => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
        };
    }
}
