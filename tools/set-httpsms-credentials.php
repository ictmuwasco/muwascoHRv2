<?php

declare(strict_types=1);

/**
 * Set httpSMS credentials in .env without echoing the secrets.
 *
 *   php tools/set-httpsms-credentials.php
 *
 * httpSMS turns an Android phone into the SMS gateway, so it needs BOTH the API
 * key from the httpsms.com dashboard and the number registered as the sender.
 * Until both are present, HttpSmsProvider refuses every send before it reaches
 * the network.
 *
 * The values are read with hidden input and written only to .env - they are
 * never printed, never sent anywhere, and never stored in this repository.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$envPath = dirname(__DIR__) . '/.env';

if (!is_file($envPath) || !is_writable($envPath)) {
    fwrite(STDERR, "Cannot write to {$envPath}\n");
    exit(1);
}

/** Read a value without echoing it. */
$askHidden = static function (string $prompt): string {
    echo $prompt;
    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows console: disable echo for the duration of the read.
        $read = @shell_exec('powershell -NoProfile -Command "$s = Read-Host -AsSecureString;'
            . ' $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s);'
            . ' [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)"');
        echo PHP_EOL;
        return trim((string) $read);
    }
    fwrite(STDOUT, $hidden = shell_exec('stty -echo'));
    $value = trim((string) fgets(STDIN));
    fwrite(STDOUT, $hidden);
    echo PHP_EOL;
    return $value;
};

/** Replace KEY=... in place, or append the key when absent. */
$setEnv = static function (string $path, string $key, string $value): void {
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $found = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
            $lines[$i] = $key . '=' . $value;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $lines[] = $key . '=' . $value;
    }
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
};

echo "httpSMS credential setup\n";
echo "Get the API key from https://dash.httpsms.com and the sender number from\n";
echo "the Devices page (E.164 format, e.g. +254712345678).\n\n";

$key  = $askHidden('HTTPSMS_API_KEY: ');
$from = $askHidden('HTTPSMS_SENDER_PHONE: ');

$key  = trim($key);
$from = trim($from);

if ($key === '' || $from === '') {
    fwrite(STDERR, "Both values are required. Nothing was written.\n");
    exit(1);
}

$setEnv($envPath, 'HTTPSMS_API_KEY', $key);
$setEnv($envPath, 'HTTPSMS_SENDER_PHONE', $from);

echo "\nWritten to .env (values not echoed).\n\n";
echo "Next:\n";
echo "  php tools/verify-notification-channels.php --probe-sms\n";