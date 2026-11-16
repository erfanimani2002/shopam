<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Platform super-admins (no tenant scope). */
final class SuperAdminModel
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one(
            'SELECT * FROM super_admins WHERE email = ?',
            [$email]
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->one(
            'SELECT id, name, email, created_at FROM super_admins WHERE id = ?',
            [$id]
        );
    }
}
