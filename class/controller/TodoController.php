<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Controller;

use Nene\Database as Database;
use Nene\Xion\ControllerBase;

/**
 * TODO REST controller for the sample application.
 */
class TodoController extends ControllerBase
{
    /**
     * Get TODO items.
     *
     * @return array<string,mixed> TODO response.
     */
    public function indexGetRest(): array
    {
        $userId = $this->getLoginUserId();
        $todoMapper = new Database\TodoMapper();
        return $this->API_RESPONSE->success([
            'todos' => $this->normalizeRows($todoMapper->findRowsByUserId($userId)),
        ]);
    }

    /**
     * Create a TODO item.
     *
     * @return array<string,mixed> TODO response.
     */
    public function indexPostRest(): array
    {
        $userId = $this->getLoginUserId();
        $title = trim((string)($this->REQUEST_JSON['title'] ?? ''));
        if ($title === '') {
            return $this->API_RESPONSE->failure('TODO-TITLE-REQUIRED');
        }
        $todoMapper = new Database\TodoMapper();
        return $this->API_RESPONSE->success([
            'todo' => $this->normalizeRow($todoMapper->createForUser($userId, $title)),
        ]);
    }

    /**
     * Read a single TODO item by id for the signed-in user.
     *
     * @return array<string,mixed> TODO response.
     */
    public function itemGetRest(): array
    {
        $userId = $this->getLoginUserId();
        $id = $this->getTodoId();
        if ($id === null) {
            return $this->API_RESPONSE->failure('TODO-ID-REQUIRED');
        }
        $todoMapper = new Database\TodoMapper();
        $todo = $todoMapper->findRowByUserIdAndId($userId, $id);
        if ($todo === null) {
            return $this->API_RESPONSE->failure('TODO-NOT-FOUND');
        }
        return $this->API_RESPONSE->success([
            'todo' => $this->normalizeRow($todo),
        ]);
    }

    /**
     * Update a TODO item.
     *
     * @return array<string,mixed> TODO response.
     */
    public function itemPutRest(): array
    {
        $userId = $this->getLoginUserId();
        $id = $this->getTodoId();
        if ($id === null) {
            return $this->API_RESPONSE->failure('TODO-ID-REQUIRED');
        }
        $title = array_key_exists('title', $this->REQUEST_JSON)
            ? trim((string)$this->REQUEST_JSON['title'])
            : null;
        $isCompleted = array_key_exists('is_completed', $this->REQUEST_JSON)
            ? filter_var($this->REQUEST_JSON['is_completed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        if ($title === '') {
            return $this->API_RESPONSE->failure('TODO-TITLE-REQUIRED');
        }
        $todoMapper = new Database\TodoMapper();
        $todo = $todoMapper->updateForUser($userId, $id, $title, $isCompleted);
        if ($todo === null) {
            return $this->API_RESPONSE->failure('TODO-NOT-FOUND');
        }
        return $this->API_RESPONSE->success([
            'todo' => $this->normalizeRow($todo),
        ]);
    }

    /**
     * Delete a TODO item.
     *
     * @return array<string,mixed> TODO response.
     */
    public function itemDeleteRest(): array
    {
        $userId = $this->getLoginUserId();
        $id = $this->getTodoId();
        if ($id === null) {
            return $this->API_RESPONSE->failure('TODO-ID-REQUIRED');
        }
        $todoMapper = new Database\TodoMapper();
        if (!$todoMapper->deleteForUser($userId, $id)) {
            return $this->API_RESPONSE->failure('TODO-NOT-FOUND');
        }
        return $this->API_RESPONSE->success([
            'id' => $id,
        ]);
    }

    /**
     * Get TODO id from URL parameters.
     *
     * @return int|null TODO id.
     */
    private function getTodoId(): ?int
    {
        $id = $this->request->getParam('id');
        if ($id === null || !ctype_digit((string)$id)) {
            return null;
        }
        return (int)$id;
    }

    /**
     * Normalize TODO rows for JSON output.
     *
     * @param array<int,array<string,mixed>> $rows TODO rows.
     *
     * @return array<int,array<string,mixed>> Normalized TODO rows.
     */
    private function normalizeRows(array $rows): array
    {
        return array_map([$this, 'normalizeRow'], $rows);
    }

    /**
     * Normalize a TODO row for JSON output.
     *
     * @param array<string,mixed> $row TODO row.
     *
     * @return array<string,mixed> Normalized TODO row.
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'title' => (string)$row['title'],
            'is_completed' => (bool)$row['is_completed'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }
}
