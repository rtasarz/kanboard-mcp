<?php
declare(strict_types=1);

// McpTokenModel stores only a hash of the token and validates in constant time.
// Run: php tests/McpTokenModelTest.php

namespace Kanboard\Core {
    class Base
    {
        protected $container;

        public function __construct($container)
        {
            $this->container = $container;
        }

        public function __get($name)
        {
            return $this->container[$name];
        }
    }
}

namespace {

use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

require __DIR__ . '/../Model/McpTokenModel.php';

$checks = 0;
$failures = 0;

function check(bool $condition, string $label): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

// Minimal stand-in for the PicoDb table API used by the model.
final class FakeDb
{
    public array $rows = [];
    public int $lastId = 0;

    public function table(string $name): FakeTable
    {
        return new FakeTable($this);
    }
}

final class FakeTable
{
    private array $conditions = [];
    private ?array $columns = null;

    public function __construct(private FakeDb $db)
    {
    }

    public function eq(string $column, $value): self
    {
        $this->conditions[] = [$column, $value];
        return $this;
    }

    public function columns(string ...$columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    private function matches(array $row): bool
    {
        foreach ($this->conditions as [$column, $value]) {
            if (($row[$column] ?? null) != $value) {
                return false;
            }
        }
        return true;
    }

    private function project(array $row): array
    {
        return $this->columns === null ? $row : array_intersect_key($row, array_flip($this->columns));
    }

    public function findAll(): array
    {
        $out = [];
        foreach ($this->db->rows as $row) {
            if ($this->matches($row)) {
                $out[] = $this->project($row);
            }
        }
        return $out;
    }

    public function findOne(): ?array
    {
        $all = $this->findAll();
        return $all[0] ?? null;
    }

    public function findAllByColumn(string $column): array
    {
        return array_column($this->findAll(), $column);
    }

    public function exists(): bool
    {
        return $this->findAll() !== [];
    }

    public function update(array $values): bool
    {
        foreach ($this->db->rows as $i => $row) {
            if ($this->matches($row)) {
                $this->db->rows[$i] = $values + $row;
            }
        }
        return true;
    }

    public function persist(array $values): bool
    {
        $this->db->rows[] = $values + ['id' => ++$this->db->lastId];
        return true;
    }
}

$db = new FakeDb();
$model = new McpTokenModel(new ArrayObject(['db' => $db]));

check($model->tokenExists() === false, 'no token at start');
check($model->getActiveToken() === null, 'no active token row at start');
check($model->validateToken('anything') === false, 'nothing validates without a token');

$token = $model->generateToken();
check(is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1, 'generateToken returns a 64-hex token');
check(count($db->rows) === 1 && $db->rows[0]['token'] !== $token, 'the token itself is not stored');
check($db->rows[0]['token'] === hash('sha256', $token), 'stored value is the SHA-256 hash');
check($model->validateToken($token) === true, 'the token validates');
check($model->validateToken(hash('sha256', $token)) === false, 'the stored hash does not validate as a token');
check($model->validateToken(strtoupper($token)) === false, 'case-changed token is rejected');
check($model->validateToken('') === false && $model->validateToken(null) === false && $model->validateToken(['x']) === false, 'empty/null/array input is rejected');
check($model->tokenExists() === true, 'tokenExists after generation');
$row = $model->getActiveToken();
check(is_array($row) && !array_key_exists('token', $row) && isset($row['created_at']), 'getActiveToken returns no secret');

$second = $model->generateToken();
check(is_string($second) && $second !== $token, 'regeneration produces a different token');
check($model->validateToken($token) === false, 'old token is deactivated');
check($model->validateToken($second) === true, 'new token validates');
check(count(array_filter($db->rows, fn($r) => (int) $r['is_active'] === 1)) === 1, 'exactly one active row');
check(($model->findActiveTokenRow($second)['user_id'] ?? null) === 0, 'token generated without a user is unrestricted (user_id 0)');
check($model->findActiveTokenRow('nope') === null, 'findActiveTokenRow is null for a wrong token');

$bound = $model->generateToken(5);
check(is_string($bound) && ($model->findActiveTokenRow($bound)['user_id'] ?? null) === 5, 'token bound to user 5 reports user_id 5');
check(($model->getActiveToken()['user_id'] ?? null) === 5, 'getActiveToken exposes the bound user id');
check(!array_key_exists('token', $model->getActiveToken()), 'getActiveToken still exposes no secret');

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
}
