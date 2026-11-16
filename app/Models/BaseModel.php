<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use PDOException;
use RuntimeException;

/**
 * THE central tenant-scoping helper.
 *
 * Every tenant-owned table has tenant_id, and every query through a model is
 * scoped by tenant_id taken from the authenticated session (Auth::tenantId()).
 * Client input can NEVER supply tenant_id:
 *   - scoped() refuses a caller-passed tenant_id param,
 *   - insert() overwrites/ rejects any tenant_id in the payload,
 *   - update()/delete() always append "tenant_id = :tenant_id".
 * On top of this, schema.sql uses composite foreign keys (tenant_id, id) so
 * cross-tenant references are impossible at the database level.
 */
abstract class BaseModel
{
    protected Database $db;
    protected string $table = '';
    protected bool $tenantScoped = true;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /* ---------------------------------------------------------------------
     * Custom queries — the pattern every model method uses.
     * The SQL MUST contain the :tenant_id placeholder; the helper binds it
     * from the session and refuses tenant_id coming from callers.
     * ------------------------------------------------------------------- */

    /** @return array<int, array<string, mixed>> */
    protected function scoped(string $sql, array $params = []): array
    {
        return $this->db->all($sql, $this->withTenant($sql, $params));
    }

    /** @return array<string, mixed>|null */
    protected function scopedOne(string $sql, array $params = []): ?array
    {
        return $this->db->one($sql, $this->withTenant($sql, $params));
    }

    /** Row count for scoped UPDATE/DELETE statements. */
    protected function scopedExec(string $sql, array $params = []): int
    {
        return $this->db->execute($sql, $this->withTenant($sql, $params));
    }

    private function withTenant(string $sql, array $params): array
    {
        if (!$this->tenantScoped) {
            return $params;
        }
        if (!str_contains($sql, ':tenant_id')) {
            throw new RuntimeException('Model query is not tenant-scoped: ' . $this->table);
        }
        if (array_key_exists('tenant_id', $params)) {
            throw new RuntimeException('tenant_id must come from the session, never from caller input.');
        }
        $params['tenant_id'] = Auth::tenantId();
        return $params;
    }

    /* ---------------------------------------------------------------------
     * Generic scoped CRUD (used by thin models; richer logic lives in subclasses).
     * ------------------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->scopedOne(
            "SELECT * FROM `{$this->table}` WHERE id = :id AND tenant_id = :tenant_id",
            ['id' => $id]
        );
    }

    /** Insert one row; tenant_id is forced from the session. Returns new id. */
    public function insert(array $data): int
    {
        if (!$this->tenantScoped) {
            throw new RuntimeException('Use a dedicated method for non-tenant tables: ' . $this->table);
        }
        if (array_key_exists('tenant_id', $data)) {
            throw new RuntimeException('tenant_id must come from the session, never from caller input.');
        }
        $data['tenant_id'] = Auth::tenantId();
        return $this->db->insert($this->table, $data);
    }

    /** Update by id, always scoped to the session tenant. Returns affected rows. */
    public function update(int $id, array $data): int
    {
        unset($data['tenant_id']);
        return $this->db->update(
            $this->table,
            $data,
            "{$this->pk()} = :row_id AND tenant_id = :tenant_id",
            ['row_id' => $id, 'tenant_id' => Auth::tenantId()]
        );
    }

    /** Hard delete by id, always scoped to the session tenant. Returns affected rows. */
    public function delete(int $id): int
    {
        return $this->scopedExec(
            "DELETE FROM `{$this->table}` WHERE id = :row_id AND tenant_id = :tenant_id",
            ['row_id' => $id]
        );
    }

    private function pk(): string
    {
        return 'id';
    }

    /** Cross-tenant reference attempts surface as FK violations (23000). */
    protected function expectForeignKeyGuard(PDOException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
