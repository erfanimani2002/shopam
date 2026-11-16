<?php

declare(strict_types=1);

/**
 * create_super_admin.php — CLI only, never web accessible (the root .htaccess
 * rewrites /tools/* into /public where it does not exist).
 *
 * Asks for name, email and password interactively; the password input is
 * hidden when the terminal supports it. Credentials are never hardcoded.
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

function readLine(string $prompt): string
{
    echo $prompt;
    $line = fgets(STDIN);
    return trim((string) $line);
}

/** Hidden password input: PowerShell SecureString on Windows, stty elsewhere. */
function readHidden(string $prompt): string
{
    echo $prompt;
    if (strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0) {
        $cmd = 'powershell -NoProfile -Command '
            . '"$sec = Read-Host -AsSecureString; '
            . '$ptr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec); '
            . '$plain = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr); '
            . '[System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr); '
            . 'Write-Output $plain"';
        $out = shell_exec($cmd);
        if (is_string($out) && trim($out) !== '') {
            echo "\n";
            return trim($out);
        }
        fwrite(STDERR, "  (hidden input unavailable; password WILL be echoed)\n");
        return trim((string) fgets(STDIN));
    }
    exec('stty -echo 2>/dev/null', $o, $code);
    $line = trim((string) fgets(STDIN));
    if ($code === 0) {
        exec('stty echo 2>/dev/null');
    }
    echo "\n";
    return $line;
}

$name = readLine('Full name: ');
$email = strtolower(readLine('Email: '));
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid name or email.\n");
    exit(1);
}

$password = readHidden('Password (min ' . Config::get('auth.min_password_length', 8) . ' chars, hidden): ');
$repeat = readHidden('Repeat password: ');
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
