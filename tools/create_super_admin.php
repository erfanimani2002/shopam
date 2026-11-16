<?php

declare(strict_types=1);

/**
 * create_super_admin.php — CLI only, never web accessible (the root .htaccess
 * rewrites /tools/* into /public where it does not exist).
 *
 * Asks for name, email and password interactively; the password input is
 * hidden when the terminal supports it. Credentials are never hardcoded and
 * never echoed/logged by this script.
 *
 * Helper names are prefixed with shopam_ so they can never collide
 * (case-insensitively) with PHP built-in or extension functions such as
 * readline(). Plain input always uses fgets(STDIN) — no readline dependency.
 *
 * Run:  php tools/create_super_admin.php
 */

use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
require $root . '/app/Core/Bootstrap.php';
Bootstrap::start($root);

/** Read one line of plain (visible) input via STDIN. */
function shopam_prompt_line(string $prompt): string
{
    echo $prompt;
    $line = fgets(STDIN);
    return trim((string) $line);
}

/**
 * Hidden password input.
 *
 * Windows: PowerShell Read-Host -AsSecureString (masked, never passed on any
 * command line, so it cannot leak into process lists). The value comes back
 * BASE64-ENCODED on stdout and is decoded here, so the plaintext password
 * never appears on the pipe, the screen, or any log.
 *
 * Unix: stty -echo around fgets(STDIN).
 *
 * If hiding is unavailable the caller is warned loudly; the password is then
 * typed visibly (only acceptable fallback on a trusted local terminal).
 */
function shopam_prompt_hidden(string $prompt): string
{
    echo $prompt;
    if (strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0) {
        $cmd = 'powershell -NoProfile -Command '
            . '"$sec = Read-Host -AsSecureString; '
            . '$ptr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec); '
            . '$plain = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr); '
            . '[System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr); '
            . '[Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes($plain))"';
        $out = shell_exec($cmd);
        if (is_string($out) && trim($out) !== '') {
            $decoded = base64_decode(trim($out), true);
            if ($decoded !== false) {
                echo "\n";
                return $decoded;
            }
        }
        fwrite(STDERR, "\n  (hidden input unavailable; password WILL be echoed)\n");
        return shopam_prompt_line('');
    }
    exec('stty -echo 2>/dev/null', $o, $code);
    $line = trim((string) fgets(STDIN));
    if ($code === 0) {
        exec('stty echo 2>/dev/null');
    }
    echo "\n";
    return $line;
}

$name = shopam_prompt_line('Full name: ');
$email = strtolower(shopam_prompt_line('Email: '));
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid name or email.\n");
    exit(1);
}

$password = shopam_prompt_hidden('Password (min ' . Config::get('auth.min_password_length', 8) . ' chars, hidden): ');
$repeat = shopam_prompt_hidden('Repeat password: ');
$minLength = (int) Config::get('auth.min_password_length', 8);
if (strlen($password) < $minLength || $password !== $repeat) {
    fwrite(STDERR, "Password too short or does not match.\n");
    exit(1);
}

$db = Database::get();
if ($db->one('SELECT id FROM super_admins WHERE email = ?', [$email]) !== null) {
    fwrite(STDERR, "A super admin with this email already exists.\n");
    exit(1);
}

$db->insert('super_admins', [
    'name'          => $name,
    'email'         => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

echo "Super admin created: {$email}\n";
