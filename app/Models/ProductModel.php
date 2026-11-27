<?php
declare(strict_types=1);

namespace App\Models;

use DomainException;
use InvalidArgumentException;

final class ProductModel extends BaseModel
{
    protected string $table = 'san_pham';
    protected string $primaryKey = 'id_san_pham';
    protected array $fillable = [
        'id_danh_muc', 'ma_san_pham', 'ten_san_pham', 'mo_ta',
        'gia_san_pham', 'hinh_anh', 'so_luong_kho', 'trang_thai',
    ];

    /**
     * Dùng cho cửa hàng; visibleOnly=false để admin xem cả sản phẩm ẩn.
     * Sắp xếp chỉ nhận các giá trị trong danh sách cho phép.
     */
    public function getAll(
        string $keyword = '',
        ?int $categoryId = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        string $sort = 'newest',
        bool $visibleOnly = true
    ): array {
        $sorts = [
            'newest' => 'p.id_san_pham DESC',
            'price_asc' => 'p.gia_san_pham ASC, p.id_san_pham DESC',
            'price_desc' => 'p.gia_san_pham DESC, p.id_san_pham DESC',
            'name' => 'p.ten_san_pham ASC, p.id_san_pham ASC',
        ];
        if (!isset($sorts[$sort])) {
            throw new InvalidArgumentException('Cách sắp xếp không hợp lệ.');
        }
        foreach ([$minPrice, $maxPrice] as $price) {
            if ($price !== null && (!is_finite($price) || $price < 0)) {
                throw new DomainException('Giá lọc phải là số không âm.');
            }
        }
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            throw new DomainException('Giá thấp nhất không được lớn hơn giá cao nhất.');
        }
        if ($categoryId !== null && $categoryId < 1) {
            throw new DomainException('ID danh mục không hợp lệ.');
        }

        $sql = 'SELECT p.*, d.ten_danh_muc FROM san_pham p
                INNER JOIN danh_muc d ON d.id_danh_muc = p.id_danh_muc WHERE 1=1';
        $params = [];
        if ($visibleOnly) {
            $sql .= " AND p.trang_thai = 'hien' AND d.trang_thai = 'hien'";
        }
        if (trim($keyword) !== '') {
            // Tìm chuỗi literal: % và _ người dùng nhập không trở thành wildcard.
            $sql .= " AND p.ten_san_pham LIKE :keyword ESCAPE '!'";
            $params['keyword'] = '%' . strtr(trim($keyword), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($categoryId !== null) {
            $sql .= ' AND p.id_danh_muc = :category_id';
            $params['category_id'] = $categoryId;
        }
        if ($minPrice !== null) {
            $sql .= ' AND p.gia_san_pham >= :min_price';
            $params['min_price'] = $minPrice;
        }
        if ($maxPrice !== null) {
            $sql .= ' AND p.gia_san_pham <= :max_price';
            $params['max_price'] = $maxPrice;
        }
        $statement = $this->db()->prepare($sql . ' ORDER BY ' . $sorts[$sort]);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function getByCategory(int $categoryId): array
    {
        return $this->getAll(categoryId: $categoryId);
    }

    /** Chi tiết phía cửa hàng không hiển thị sản phẩm/danh mục đã ẩn. */
    public function findVisibleById(int $id): ?array
    {
        $statement = $this->db()->prepare(
            "SELECT p.*, d.ten_danh_muc FROM san_pham p
             INNER JOIN danh_muc d ON d.id_danh_muc = p.id_danh_muc
             WHERE p.id_san_pham = :id AND p.trang_thai = 'hien' AND d.trang_thai = 'hien' LIMIT 1"
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
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
        foreach (['ten_san_pham' => 200, 'ma_san_pham' => 50] as $field => $maxLength) {
            if ($creating || array_key_exists($field, $data)) {
                if (!is_string($data[$field] ?? null) || trim($data[$field]) === ''
                    || mb_strlen(trim($data[$field]), 'UTF-8') > $maxLength) {
                    throw new DomainException("{$field} phải có từ 1 đến {$maxLength} ký tự.");
                }
                $data[$field] = trim($data[$field]);
            }
        }
        if ($creating || array_key_exists('gia_san_pham', $data)) {
            $price = $data['gia_san_pham'] ?? null;
            if (!is_numeric($price) || !is_finite((float) $price) || (float) $price < 0
                || (float) $price > 9999999999.99) {
                throw new DomainException('Giá sản phẩm không hợp lệ.');
            }
        }
        foreach (['id_danh_muc' => 1, 'so_luong_kho' => 0] as $field => $minimum) {
            if (($creating && $field === 'id_danh_muc') || array_key_exists($field, $data)) {
                if (filter_var($data[$field] ?? null, FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => $minimum, 'max_range' => 2147483647]]) === false) {
                    throw new DomainException("{$field} phải là số nguyên từ {$minimum} đến 2147483647.");
                }
            }
        }
        if (array_key_exists('trang_thai', $data) && !in_array($data['trang_thai'], ['hien', 'an'], true)) {
            throw new DomainException('Trạng thái sản phẩm phải là hien hoặc an.');
        }

        return $data;
    }
}
