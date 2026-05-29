<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Per-user address book with multiple addresses and a default flag.
 *
 * Stores named postal/shipping addresses for a user.
 * Each user may have many addresses; at most one is the default.
 *
 * ## Usage
 *
 * ```php
 * $book = new AddressBook($pdo);
 *
 * // Add
 * $id = $book->add('user-1', [
 *     'label'      => 'Home',
 *     'name'       => 'Taro Yamada',
 *     'line1'      => '1-2-3 Shinjuku',
 *     'line2'      => '',
 *     'city'       => 'Shinjuku-ku',
 *     'state'      => 'Tokyo',
 *     'postal'     => '160-0022',
 *     'country'    => 'JP',
 *     'phone'      => '+81-90-0000-0000',
 *     'is_default' => true,
 * ]);
 *
 * // List
 * $addresses = $book->list('user-1');
 *
 * // Set default
 * $book->setDefault('user-1', $id);
 *
 * // Update
 * $book->update('user-1', $id, ['label' => 'Work']);
 *
 * // Remove
 * $book->remove('user-1', $id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE addresses (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     label      VARCHAR(100) NOT NULL DEFAULT '',
 *     name       VARCHAR(255) NOT NULL DEFAULT '',
 *     line1      VARCHAR(255) NOT NULL DEFAULT '',
 *     line2      VARCHAR(255) NOT NULL DEFAULT '',
 *     city       VARCHAR(100) NOT NULL DEFAULT '',
 *     state      VARCHAR(100) NOT NULL DEFAULT '',
 *     postal     VARCHAR(20)  NOT NULL DEFAULT '',
 *     country    VARCHAR(2)   NOT NULL DEFAULT '',
 *     phone      VARCHAR(50)  NOT NULL DEFAULT '',
 *     is_default TINYINT(1)   NOT NULL DEFAULT 0,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AddressBook
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a new address for a user.
     *
     * If `is_default` is true the previous default (if any) is cleared atomically.
     *
     * @param  string                $userId  User identifier.
     * @param  array<string, mixed>  $data    Address fields (see schema above).
     * @return int                   New address ID.
     * @throws \InvalidArgumentException if required fields are empty.
     */
    public function add(string $userId, array $data): int
    {
        $db = $this->db();

        $label   = trim((string)($data['label']   ?? ''));
        $name    = trim((string)($data['name']    ?? ''));
        $line1   = trim((string)($data['line1']   ?? ''));
        $city    = trim((string)($data['city']    ?? ''));
        $country = trim((string)($data['country'] ?? ''));

        if ($line1 === '') {
            throw new \InvalidArgumentException('Address line1 must not be empty.');
        }
        if ($country === '') {
            throw new \InvalidArgumentException('Country must not be empty.');
        }

        $isDefault = !empty($data['is_default']);

        $db->beginTransaction();
        try {
            if ($isDefault) {
                $this->clearDefault($db, $userId);
            }

            $stmt = $db->prepare(
                'INSERT INTO addresses
                    (user_id, label, name, line1, line2, city, state, postal, country, phone, is_default, updated_at)
                 VALUES
                    (:user_id, :label, :name, :line1, :line2, :city, :state, :postal, :country, :phone, :is_default, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                ':user_id'    => $userId,
                ':label'      => $label,
                ':name'       => $name,
                ':line1'      => $line1,
                ':line2'      => trim((string)($data['line2'] ?? '')),
                ':city'       => $city,
                ':state'      => trim((string)($data['state'] ?? '')),
                ':postal'     => trim((string)($data['postal'] ?? '')),
                ':country'    => $country,
                ':phone'      => trim((string)($data['phone'] ?? '')),
                ':is_default' => $isDefault ? 1 : 0,
            ]);

            $id = (int)$db->lastInsertId();
            $db->commit();
            return $id;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Update fields of an existing address.
     *
     * Only the keys present in `$data` are updated.
     * If `is_default` is set to true the previous default is cleared.
     *
     * @param string               $userId    Owner user ID.
     * @param int                  $addressId Address to update.
     * @param array<string, mixed> $data      Fields to change.
     * @return bool                True if a row was updated.
     * @throws \InvalidArgumentException if line1/country would be set to empty.
     */
    public function update(string $userId, int $addressId, array $data): bool
    {
        $db = $this->db();

        $allowed = ['label', 'name', 'line1', 'line2', 'city', 'state', 'postal', 'country', 'phone', 'is_default'];
        $sets    = [];
        $params  = [':user_id' => $userId, ':id' => $addressId];

        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            if ($col === 'is_default') {
                continue; // handled separately
            }
            $value = trim((string)$data[$col]);
            if ($col === 'line1' && $value === '') {
                throw new \InvalidArgumentException('Address line1 must not be empty.');
            }
            if ($col === 'country' && $value === '') {
                throw new \InvalidArgumentException('Country must not be empty.');
            }
            $sets[":$col"] = $value;
        }

        $isDefaultSet = array_key_exists('is_default', $data);
        $isDefault    = $isDefaultSet && !empty($data['is_default']);

        if (empty($sets) && !$isDefaultSet) {
            return false;
        }

        $db->beginTransaction();
        try {
            if ($isDefault) {
                $this->clearDefault($db, $userId);
                $sets[':is_default'] = 1;
            } elseif ($isDefaultSet) {
                $sets[':is_default'] = 0;
            }

            $setClauses = implode(
                ', ',
                array_map(
                    static fn (string $k): string => ltrim($k, ':') . ' = ' . $k,
                    array_keys($sets)
                )
            );

            $sql = "UPDATE addresses SET {$setClauses}, updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = :user_id AND id = :id";

            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge($sets, $params));

            $affected = $stmt->rowCount();
            $db->commit();
            return $affected > 0;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Remove an address (hard delete).
     *
     * If the deleted address was the default, no other address is auto-promoted.
     *
     * @return bool True if a row was deleted.
     */
    public function remove(string $userId, int $addressId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM addresses WHERE user_id = :user_id AND id = :id'
        );
        $stmt->execute([':user_id' => $userId, ':id' => $addressId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a single address by ID (must belong to the user).
     *
     * @return array<string, mixed>|null
     */
    public function get(string $userId, int $addressId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM addresses WHERE user_id = :user_id AND id = :id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId, ':id' => $addressId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all addresses for a user, default first then by id ASC.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM addresses WHERE user_id = :user_id ORDER BY is_default DESC, id ASC'
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Set one address as the default for a user.
     *
     * Clears any existing default within a transaction.
     *
     * @return bool True if the address exists and was updated.
     */
    public function setDefault(string $userId, int $addressId): bool
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $this->clearDefault($db, $userId);

            $stmt = $db->prepare(
                'UPDATE addresses SET is_default = 1, updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id AND id = :id'
            );
            $stmt->execute([':user_id' => $userId, ':id' => $addressId]);
            $affected = $stmt->rowCount();
            $db->commit();
            return $affected > 0;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Return the default address for a user, or null if none set.
     *
     * @return array<string, mixed>|null
     */
    public function getDefault(string $userId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM addresses WHERE user_id = :user_id AND is_default = 1 LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function clearDefault(PDO $db, string $userId): void
    {
        $stmt = $db->prepare(
            'UPDATE addresses SET is_default = 0, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND is_default = 1'
        );
        $stmt->execute([':user_id' => $userId]);
    }
}
