# PHP crypto gotchas on this stack (muwascoHRv2)

Verified 2026-09-30 against the local XAMPP build (PHP 8.0.30 ZTS, OpenSSL 1.1.1t).

## 1. `openssl_decrypt` and `openssl_encrypt` have DIFFERENT argument orders

This is the single most dangerous trap in this codebase's crypto. Confirmed with
`ReflectionFunction`:

```
openssl_decrypt: 0=data 1=cipher 2=key 3=options 4=iv 5=tag 6=aad
openssl_encrypt: 0=data 1=cipher 2=key 3=options 4=iv 5=tag 6=aad 7=tag_length
```

Both put `$tag` at index 5 and `$aad` at index 6. The trap is that `$tag` is
**by-reference on encrypt** (`&$tag`) and **by-value on decrypt**, and encrypt
has the extra `tag_length` parameter. Writing the natural
`encrypt($d,$c,$k,RAW,$iv,$aad,16)` / `decrypt($d,$c,$k,RAW,$iv,$aad,$tag)` pair
puts the AAD where the tag is expected, and PHP raises
`TypeError: Argument #5 ($tag) must be of type string, int given` — or, worse,
silently fails authentication at decrypt time.

Correct form used by `App\Helpers\StorageEncryption`:

```php
$tag = null;
$ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
// write $iv . $ct . $tag
$pt = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
```

Never guess this order — re-check with ReflectionFunction after a PHP upgrade.

## 2. libsodium is NOT available

`extension_loaded('sodium') === false` here, so
`sodium_crypto_secretstream_*` does not exist. Any plan that assumes secretstream
must fall back to OpenSSL AES-256-GCM with a chunked container. See
`App\Helpers\StorageEncryption` for that container format.

## 3. Why StorageEncryption stores ciphertext+tag concatenated

`openssl_encrypt` with `OPENSSL_RAW_DATA` does NOT append the GCM tag to the
ciphertext, and there is no `$tag` out-parameter. The helper therefore writes
`iv || ciphertext || tag` itself and splits them back out on read. A round-trip
that "works" without the explicit tag append is a false positive: GCM is
unauthenticated (it degrades to CTR) whenever the tag is dropped.

## 4. Migration SQL string escaping

When a migration builds DDL inside `SET @sql := IF(cond, '...', 'SELECT 1')`,
an embedded `COMMENT 'text'` must be written `COMMENT ''text''` — and a third
apostrophe (`DEFAULT ''active'''`) silently closes the outer string early,
producing a syntax error partway down the CREATE TABLE. Prefer **no inline
COMMENT clauses** in guarded DDL and document columns in the section header
instead; that is what migration 105 does and why.
