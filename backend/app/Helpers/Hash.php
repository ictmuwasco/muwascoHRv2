<?php

declare(strict_types=1);

namespace App\Helpers;

class Hash
{
    private static ?Hash $instance = null;
    
    /**
     * Hashing algorithm to use
     * Options: PASSWORD_DEFAULT, PASSWORD_BCRYPT, PASSWORD_ARGON2I, PASSWORD_ARGON2ID
     */
    private string $algorithm = PASSWORD_DEFAULT;
    
    /**
     * Algorithm options (cost factor, memory cost, etc.)
     */
    private array $options = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Hash a password with automatic salt generation
     * 
     * @param string $password The plain text password to hash
     * @return string The hashed password (includes salt)
     */
    public function make(string $password): string
    {
        // Use Argon2ID if available (PHP 7.2+), otherwise use bcrypt
        if (defined('PASSWORD_ARGON2ID')) {
            $this->algorithm = PASSWORD_ARGON2ID;
            $this->options = [
                'memory_cost' => 65536, // 64 MB
                'time_cost' => 4,       // 4 iterations
                'threads' => 1
            ];
        } else {
            $this->algorithm = PASSWORD_BCRYPT;
            $this->options = [
                'cost' => 12  // Higher cost = more secure but slower
            ];
        }
        
        return password_hash($password, $this->algorithm, $this->options);
    }

    /**
     * Verify a password against a hash
     * 
     * @param string $password The plain text password
     * @param string $hash The stored hash
     * @return bool True if password matches hash
     */
    public function check(string $password, string $hash): bool
    {
        if (empty($hash) || strlen($hash) < 60) {
            return false;
        }
        
        return password_verify($password, $hash);
    }

    /**
     * Check if the hash needs to be rehashed (e.g., after algorithm upgrade)
     * 
     * @param string $hash The stored hash
     * @return bool True if rehash is needed
     */
    public function needsRehash(string $hash): bool
    {
        if (empty($hash) || strlen($hash) < 60) {
            return true;
        }
        
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /**
     * Get information about a hash
     * 
     * @param string $hash The hash to inspect
     * @return array Hash information
     */
    public function getInfo(string $hash): array
    {
        return password_get_info($hash);
    }

    private function __clone(): void {}

    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}

function hash_password(string $password): string
{
    return Hash::getInstance()->make($password);
}

function verify_password(string $password, string $hash): bool
{
    return Hash::getInstance()->check($password, $hash);
}