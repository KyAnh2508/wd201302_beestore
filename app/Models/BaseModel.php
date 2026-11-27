<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use InvalidArgumentException;

/**
 * Lớp nền cho các model thao tác với một bảng trong database.
 *
 * Model con cần khai báo $table, $primaryKey và $fillable.
 */
abstract class BaseModel extends Model
{
    protected string $table;
    protected string $primaryKey = 'id';

    /** @var list<string> */
    protected array $fillable = [];

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->quoteIdentifier($this->table));
        $statement = $this->db()->query($sql);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int|string $id): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :id LIMIT 1',
            $this->quoteIdentifier($this->table),
            $this->quoteIdentifier($this->primaryKey)
        );

        $statement = $this->db()->prepare($sql);
        $statement->execute(['id' => $id]);
        $result = $statement->fetch();

        return $result === false ? null : $result;
    }

    /** @return list<array<string, mixed>> */
    public function findBy(string $column, mixed $value): array
    {
        $this->assertAllowedColumn($column);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :value',
            $this->quoteIdentifier($this->table),
            $this->quoteIdentifier($column)
        );

        $statement = $this->db()->prepare($sql);
        $statement->execute(['value' => $value]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function firstBy(string $column, mixed $value): ?array
    {
        $this->assertAllowedColumn($column);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :value LIMIT 1',
            $this->quoteIdentifier($this->table),
            $this->quoteIdentifier($column)
        );

        $statement = $this->db()->prepare($sql);
        $statement->execute(['value' => $value]);
        $result = $statement->fetch();

        return $result === false ? null : $result;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): string
    {
        $data = $this->filterFillable($data);
        $this->assertDataIsNotEmpty($data, 'Không có dữ liệu hợp lệ để thêm.');

        $columns = array_keys($data);
        $quotedColumns = array_map($this->quoteIdentifier(...), $columns);
        $placeholders = array_map(
            static fn (string $column): string => ':' . $column,
            $columns
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($this->table),
            implode(', ', $quotedColumns),
            implode(', ', $placeholders)
        );

        $statement = $this->db()->prepare($sql);
        $statement->execute($data);

        return $this->db()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int|string $id, array $data): bool
    {
        $data = $this->filterFillable($data);
        unset($data[$this->primaryKey]);
        $this->assertDataIsNotEmpty($data, 'Không có dữ liệu hợp lệ để cập nhật.');

        $assignments = array_map(
            fn (string $column): string => sprintf(
                '%s = :%s',
                $this->quoteIdentifier($column),
                $column
            ),
            array_keys($data)
        );

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :_primary_key',
            $this->quoteIdentifier($this->table),
            implode(', ', $assignments),
            $this->quoteIdentifier($this->primaryKey)
        );

        $data['_primary_key'] = $id;
        $statement = $this->db()->prepare($sql);

        return $statement->execute($data);
    }

    public function delete(int|string $id): bool
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = :id',
            $this->quoteIdentifier($this->table),
            $this->quoteIdentifier($this->primaryKey)
        );

        $statement = $this->db()->prepare($sql);

        return $statement->execute(['id' => $id]);
    }

    /**
     * Chỉ giữ lại các cột mà model con cho phép ghi.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function filterFillable(array $data): array
    {
        $allowed = array_fill_keys($this->fillable, true);

        return array_intersect_key($data, $allowed);
    }

    private function assertAllowedColumn(string $column): void
    {
        if ($column !== $this->primaryKey && !in_array($column, $this->fillable, true)) {
            throw new InvalidArgumentException("Cột '{$column}' không được phép truy vấn.");
        }
    }

    /** @param array<string, mixed> $data */
    private function assertDataIsNotEmpty(array $data, string $message): void
    {
        if ($data === []) {
            throw new InvalidArgumentException($message);
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Tên bảng hoặc cột '{$identifier}' không hợp lệ.");
        }

        return '`' . $identifier . '`';
    }
}
