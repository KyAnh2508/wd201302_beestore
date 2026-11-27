<?php
declare(strict_types=1);

namespace App\Models;

use DomainException;

final class CategoryModel extends BaseModel
{
    protected string $table = 'danh_muc';
    protected string $primaryKey = 'id_danh_muc';
    protected array $fillable = ['ten_danh_muc', 'mo_ta', 'trang_thai'];

    public function getAll(bool $visibleOnly = true): array
    {
        $sql = 'SELECT * FROM danh_muc';
        if ($visibleOnly) {
            $sql .= " WHERE trang_thai = 'hien'";
        }

        return $this->db()->query($sql . ' ORDER BY ten_danh_muc ASC, id_danh_muc ASC')->fetchAll();
    }

    public function create(array $data): string
    {
        return parent::create($this->validate($data, true));
    }

    public function update(int|string $id, array $data): bool
    {
        return parent::update($id, $this->validate($data, false));
    }

    private function validate(array $data, bool $creating): array
    {
        if ($creating || array_key_exists('ten_danh_muc', $data)) {
            if (!is_string($data['ten_danh_muc'] ?? null)
                || trim($data['ten_danh_muc']) === ''
                || mb_strlen(trim($data['ten_danh_muc']), 'UTF-8') > 100) {
                throw new DomainException('Tên danh mục phải có từ 1 đến 100 ký tự.');
            }
            $data['ten_danh_muc'] = trim($data['ten_danh_muc']);
        }
        if (array_key_exists('trang_thai', $data) && !in_array($data['trang_thai'], ['hien', 'an'], true)) {
            throw new DomainException('Trạng thái danh mục phải là hien hoặc an.');
        }

        return $data;
    }
}
