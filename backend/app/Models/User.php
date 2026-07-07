<?php

declare(strict_types=1);

namespace App\Models;

/**
 * User Model
 *
 * Handles database operations for the users table.
 * Follows MVC pattern — data access layer for user authentication and management.
 *
 * Place: backend/app/Models/User.php
 */
class User
{
    /**
     * Find a user by email address.
     *
     * @param string $email
     * @return array|null  User record or null if not found
     */
    public function findByEmail(string $email): ?array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $stmt = $conn->prepare("
            SELECT u.id, u.email, u.password, u.role, u.first_name, u.last_name,
                   u.designation, u.is_active, e.employee_status, e.employee_id
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE u.email = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }

    /**
     * Find a user by their ID.
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $stmt = $conn->prepare("
            SELECT id, email, role, first_name, last_name, designation,
                   is_active, employee_id
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }

    /**
     * Update the user's password hash.
     *
     * @param int    $id
     * @param string $newHash
     */
    public function updatePassword(int $id, string $newHash): void
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $newHash, $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get employee name from the employees table as a fallback.
     *
     * @param string $email
     * @param string|null $employeeId
     * @return array|null  ['first_name' => ..., 'last_name' => ...]
     */
    public function getEmployeeName(string $email, ?string $employeeId = null): ?array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        if ($employeeId) {
            $stmt = $conn->prepare(
                "SELECT first_name, last_name FROM employees WHERE email = ? OR employee_id = ? LIMIT 1"
            );
            $stmt->bind_param("ss", $email, $employeeId);
        } else {
            $stmt = $conn->prepare(
                "SELECT first_name, last_name FROM employees WHERE email = ? LIMIT 1"
            );
            $stmt->bind_param("s", $email);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $data   = $result->fetch_assoc();
        $stmt->close();

        return $data ?: null;
    }

    /**
     * Get all users (for admin management).
     *
     * @return array
     */
    public function getAll(): array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $result = $conn->query("
            SELECT u.id, u.email, u.role, u.first_name, u.last_name,
                   u.designation, u.is_active, u.created_at,
                   e.employee_status, e.employee_id
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            ORDER BY u.created_at DESC
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
