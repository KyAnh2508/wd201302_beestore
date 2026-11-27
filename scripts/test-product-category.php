<?php
declare(strict_types=1);

// Kiểm tra độc lập bằng SQLite in-memory; không đụng database của ứng dụng.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Models\CategoryModel;
use App\Models\ProductModel;

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("CREATE TABLE danh_muc (
    id_danh_muc INTEGER PRIMARY KEY AUTOINCREMENT,
    ten_danh_muc TEXT NOT NULL UNIQUE, mo_ta TEXT,
    trang_thai TEXT NOT NULL DEFAULT 'hien'
)");
$pdo->exec("CREATE TABLE san_pham (
    id_san_pham INTEGER PRIMARY KEY AUTOINCREMENT,
    id_danh_muc INTEGER NOT NULL REFERENCES danh_muc(id_danh_muc) ON DELETE RESTRICT,
    ma_san_pham TEXT NOT NULL UNIQUE, ten_san_pham TEXT NOT NULL, mo_ta TEXT,
    gia_san_pham NUMERIC NOT NULL CHECK(gia_san_pham >= 0), hinh_anh TEXT,
    so_luong_kho INTEGER NOT NULL DEFAULT 0 CHECK(so_luong_kho >= 0),
    trang_thai TEXT NOT NULL DEFAULT 'hien'
)");
(new ReflectionProperty(Database::class, 'connection'))->setValue(null, $pdo);

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    ++$checks;
    echo "PASS: {$label}\n";
}
function rejects(callable $operation, string $exception, string $label): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $exception, $label);
        return;
    }
    check(false, $label);
}

$categories = new CategoryModel();
$products = new ProductModel();
$shirts = $categories->create(['ten_danh_muc' => '  Áo  ']);
$hats = $categories->create(['ten_danh_muc' => 'Nón']);
$hidden = $categories->create(['ten_danh_muc' => 'Ẩn', 'trang_thai' => 'an']);
check($categories->findById($shirts)['ten_danh_muc'] === 'Áo', 'Tạo danh mục và chuẩn hóa tên Unicode');
check(count($categories->getAll()) === 2 && count($categories->getAll(false)) === 3, 'Ẩn danh mục phía cửa hàng, admin xem được');
rejects(fn () => $categories->create(['ten_danh_muc' => '  ']), DomainException::class, 'Từ chối danh mục rỗng');
rejects(fn () => $categories->create(['ten_danh_muc' => 'Áo']), PDOException::class, 'Ràng buộc tên danh mục duy nhất');

$base = ['id_danh_muc' => $shirts, 'ma_san_pham' => 'BEE001', 'ten_san_pham' => 'Áo polo', 'gia_san_pham' => 249000, 'so_luong_kho' => 20];
$polo = $products->create($base + ['id_san_pham' => 999, 'unknown' => 'ignored']);
$cap = $products->create(array_replace($base, ['id_danh_muc' => $hats, 'ma_san_pham' => 'BEE002', 'ten_san_pham' => 'Nón 100%_Bee', 'gia_san_pham' => 99000]));
$hiddenProduct = $products->create(array_replace($base, ['ma_san_pham' => 'BEE003', 'trang_thai' => 'an']));
$products->create(array_replace($base, ['id_danh_muc' => $hidden, 'ma_san_pham' => 'BEE004']));
check($polo !== '999' && count($products->all()) === 4, 'CRUD BaseModel và bỏ cột ngoài fillable');
check(count($products->getAll()) === 2 && count($products->getAll(visibleOnly: false)) === 4, 'Cửa hàng loại sản phẩm ẩn và danh mục ẩn');
check(count($products->getByCategory((int) $shirts)) === 1, 'Lấy sản phẩm theo danh mục');
check(count($products->getAll(keyword: 'polo')) === 1, 'Tìm sản phẩm theo tên');
check(count($products->getAll(keyword: '%_')) === 1, 'Escape wildcard trong từ khóa');
check($products->getAll(keyword: "' OR 1=1 --") === [], 'Từ khóa được bind, không chèn SQL');
check(count($products->getAll(minPrice: 100000, maxPrice: 250000)) === 1, 'Lọc khoảng giá kết hợp');
check((int) $products->getAll(sort: 'price_asc')[0]['id_san_pham'] === (int) $cap, 'Sắp xếp giá tăng');
check((int) $products->getAll(sort: 'price_desc')[0]['id_san_pham'] === (int) $polo, 'Sắp xếp giá giảm');
check($products->findVisibleById((int) $hiddenProduct) === null, 'Không mở chi tiết sản phẩm ẩn');
check($products->findVisibleById(999999) === null, 'Chi tiết ID không tồn tại trả null');
check($products->findVisibleById((int) $polo)['ten_danh_muc'] === 'Áo', 'Chi tiết có tên danh mục');
rejects(fn () => $products->getAll(sort: 'id; DROP TABLE san_pham'), InvalidArgumentException::class, 'Chặn sắp xếp ngoài whitelist');
rejects(fn () => $products->getAll(minPrice: 2, maxPrice: 1), DomainException::class, 'Từ chối khoảng giá đảo ngược');
rejects(fn () => $products->getAll(minPrice: NAN), DomainException::class, 'Từ chối giá lọc NaN');
foreach (['gia_san_pham' => -1, 'so_luong_kho' => -1, 'id_danh_muc' => 0, 'ten_san_pham' => '', 'trang_thai' => 'invalid'] as $field => $value) {
    rejects(fn () => $products->create(array_replace($base, [$field => $value])), DomainException::class, 'Từ chối dữ liệu sai: ' . $field);
}
rejects(fn () => $products->create(array_replace($base, ['id_danh_muc' => 9999])), PDOException::class, 'Từ chối danh mục không tồn tại');
rejects(fn () => $products->create($base), PDOException::class, 'Từ chối mã sản phẩm trùng');
$products->update($polo, ['gia_san_pham' => 199000, 'id_san_pham' => 999]);
check((float) $products->findById($polo)['gia_san_pham'] === 199000.0 && $products->findById(999) === null, 'Cập nhật giữ nguyên khóa chính');
rejects(fn () => $products->update($polo, ['gia_san_pham' => -10]), DomainException::class, 'Kiểm tra dữ liệu khi cập nhật');
rejects(fn () => $products->update($polo, ['unknown' => 'ignored']), InvalidArgumentException::class, 'Từ chối cập nhật không có cột hợp lệ');
rejects(fn () => $categories->delete($shirts), PDOException::class, 'Chặn xóa danh mục đang có sản phẩm');
$categories->update($shirts, ['trang_thai' => 'an']);
check($products->findVisibleById((int) $polo) === null, 'Ẩn danh mục đồng thời ẩn chi tiết sản phẩm');
$products->delete($cap);
check($products->findById($cap) === null, 'Xóa sản phẩm');
$categories->delete($hats);
check($categories->findById($hats) === null, 'Xóa danh mục trống');

echo "\n{$checks} checks passed (SQLite in-memory; chưa xác nhận DDL trên MySQL).\n";
