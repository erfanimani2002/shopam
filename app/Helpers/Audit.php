<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Auth;
use App\Core\Database;
use Throwable;

/**
 * Best-effort audit logging. NEVER breaks the main flow: failures are swallowed.
 * tenant_id NULL rows are platform-level (super-admin) actions.
 */
final class Audit
{
    public static function log(string $action, ?string $entity = null, ?int $entityId = null, ?array $details = null): void
    {
        try {
            $db = Database::get();
            if (session_status() !== PHP_SESSION_ACTIVE) {
                return;
            }
            if (Auth::isSuperAdmin()) {
                $db->insert('audit_log', [
                    'tenant_id'  => null,
                    'actor_type' => 'super_admin',
                    'actor_id'   => (int) ($_SESSION['super_admin_id'] ?? 0),
                    'action'     => $action,
                    'entity'     => $entity,
                    'entity_id'  => $entityId,
                    'details'    => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                ]);
                return;
            }
            if (Auth::isLoggedIn()) {
                $db->insert('audit_log', [
                    'tenant_id'  => Auth::tenantId(),
                    'actor_type' => 'user',
                    'actor_id'   => Auth::userId(),
                    'action'     => $action,
                    'entity'     => $entity,
                    'entity_id'  => $entityId,
                    'details'    => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                ]);
            }
        } catch (Throwable) {
            // audit must never break the request
        }
    }
}
