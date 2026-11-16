<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Shop users (owner/staff). Password changes go through the scoped update
 * inherited from BaseModel.
 */
final class UserModel extends BaseModel
{
    protected string $table = 'users';

    /**
     * Pre-auth login lookup. This is the ONLY model query that filters by a
     * tenant reference that is not the session tenant id: at login time no
     * session exists, and the tenant was resolved from the shop slug
     * (validated, unique). Both binding names are distinct because placeholder
     * reuse is not allowed (emulation off).
     */
    public function findByTenantLogin(int $tenantId, string $emailOrPhone): ?array
    {
        return $this->db->one(
            'SELECT * FROM `users`
             WHERE tenant_id = :tenant_id
               AND is_active = 1
               AND (email = :ident_email OR phone = :ident_phone)',
            [
                'tenant_id'    => $tenantId,
                'ident_email'  => $emailOrPhone,
                'ident_phone'  => $emailOrPhone,
            ]
        );
    }
}
