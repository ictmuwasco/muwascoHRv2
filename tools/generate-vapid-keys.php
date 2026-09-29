<?php

declare(strict_types=1);

/**
 * Generate a VAPID keypair for Web Push.
 *
 *   php tools/generate-vapid-keys.php
 *
 * Prints the two lines to paste into .env. The PUBLIC key is safe to ship to
 * browsers (the app exposes it via GET /api/push/vapid-public-key); the
 * PRIVATE key must never leave the server.
 *
 * Only needed when VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY are unset or malformed.
 * Regenerating INVALIDATES every existing browser subscription: a subscription
 * is bound to the key that created it, so all staff would have to re-subscribe.
 */

require_once __DIR__ . '/../backend/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

/** base64url without padding - the encoding VAPID requires. */
$b64url = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

// On Windows, this PHP build's OpenSSL cannot find a config file at all: even
// RSA keygen fails with "error:02001003:system library:fopen:No such process",
// and EC keygen fails regardless of curve name unless a config path is passed
// EXPLICITLY. XAMPP ships one but does not put it on the default search path.
// (SMTP still works because PHPMailer uses stream sockets, not openssl_pkey_new.)
$opensslConfig = null;
foreach ([
    'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
    'C:\\xampp\\apache\\conf\\openssl.cnf',
    'C:\\Program Files\\PHP\\extras\\ssl\\openssl.cnf',
] as $candidate) {
    if (is_file($candidate)) {
        $opensslConfig = $candidate;
        break;
    }
}

// openssl_pkey_new gives us a real P-256 key; the public point is extracted in
// its uncompressed 0x04||X||Y form, which is exactly what VAPID expects.
$keyOptions = [
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'      => 'prime256v1',
];
if ($opensslConfig !== null) {
    $keyOptions['config'] = $opensslConfig;
}
$key = openssl_pkey_new($keyOptions);

if ($key === false) {
    fwrite(STDERR, "Could not generate a P-256 key: " . openssl_error_string() . "\n");
    exit(1);
}

$details = openssl_pkey_get_details($key);
if ($details === false || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
    fwrite(STDERR, "OpenSSL did not return usable EC parameters.\n");
    exit(1);
}

$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$d = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);

$publicKey  = $b64url("\x04" . $x . $y);
$privateKey = $b64url($d);

echo "VAPID keypair generated.\n\n";
echo "Paste into .env (replace any existing values):\n\n";
echo "VAPID_PUBLIC_KEY={$publicKey}\n";
echo "VAPID_PRIVATE_KEY={$privateKey}\n";
echo "VAPID_SUBJECT=mailto:hr@muwasco.co.ke\n\n";

printf("public key : %d chars (decodes to %d bytes, expect 65)\n", strlen($publicKey), strlen(base64_decode(strtr($publicKey, '-_', '+/'), true)));
printf("private key: %d chars (decodes to %d bytes, expect 32)\n", strlen($privateKey), strlen(base64_decode(strtr($privateKey, '-_', '+/'), true)));

// Prove the pair actually signs before the user commits it to .env.
try {
    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject'    => 'mailto:hr@muwasco.co.ke',
            'publicKey'  => $publicKey,
            'privateKey' => $privateKey,
        ],
    ]);
    $sub = \Minishlink\WebPush\Subscription::create([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/verify-only',
        'keys'     => [
            'p256dh' => 'BCpKq3vR2wJ8mN1xQ0eY7uI4oP6aS5dF3gH1jK2lZ9xC8vB0nM7qR4tU6wX1yZ3',
            'auth'   => 'k_9xQmR2vB8nL4pT7wY1zC6dF0gH3jK5sA8eU2iO7qW4rT6yU1vB3nM5',
        ],
    ]);
    $webPush->queueNotification($sub, '{"title":"verify"}');
    echo "\nself-check: the pair signed a VAPID token successfully.\n";
} catch (\Throwable $e) {
    echo "\nself-check FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nNOTE: regenerating invalidates every existing push subscription;\n";
echo "      all staff would have to re-subscribe from Settings > Notifications.\n";
