# ProductModel và CategoryModel — phần Nguyễn Dũng

Hai model kế thừa `App\Models\BaseModel` ở PR #1 (nhánh `ky-anh`).
Merge BaseModel trước hoặc cùng PR này. Không cần Composer.
PHP 8.1+, extension `pdo_mysql` và `mbstring`.

## Database

Repo hiện chỉ tạo database, chưa có bảng. Import lần lượt:

```sh
mysql -u root -p < database/schema.sql
mysql -u root -p < database/product-category.sql
```

Nếu database có tên khác, sửa dòng `USE` trong file SQL và `DB_NAME` trong `.env`.
Tên `san_pham`, `id_san_pham`, `ten_san_pham`, `gia_san_pham`, `so_luong_kho`,
`trang_thai` khớp CartModel của Võ Dũng. `danh_muc` là cấu trúc mới đề xuất vì
repo chưa có ERD/schema bảng. File SQL không thay đổi cấu trúc bảng đã tồn tại;
nếu nhóm có database riêng, đối chiếu tên cột trước khi import.
File này chỉ tạo hai bảng, chưa tạo bảng User, Cart, Order hay OrderDetail.

## Sử dụng trong controller

```php
$products = new \App\Models\ProductModel();
$categories = new \App\Models\CategoryModel();

$list = $products->getAll(
    keyword: 'polo', categoryId: 1,
    minPrice: 100000, maxPrice: 300000, sort: 'price_asc'
);
$detail = $products->findVisibleById(1); // null nếu không có hoặc bị ẩn
$byCategory = $products->getByCategory(1);
$categoryList = $categories->getAll();

$id = $products->create([
    'id_danh_muc' => 1,
    'ma_san_pham' => 'BEE001',
    'ten_san_pham' => 'Áo polo',
    'gia_san_pham' => 249000,
    'so_luong_kho' => 20,
    'trang_thai' => 'hien',
]);
$products->update($id, ['gia_san_pham' => 229000]);
$products->update($id, ['trang_thai' => 'an']);
```

CRUD dùng chung: `all`, `findById`, `findBy`, `firstBy`, `create`, `update`, `delete`.
`all` và `findById` đọc cả bản ghi ẩn, dành cho admin. Controller phải kiểm tra quyền
trước khi cho sử dụng chức năng admin. Phía cửa hàng dùng `getAll`, `getByCategory`,
`findVisibleById`. Giá chấp nhận từ 0 đến 9999999999.99; số lượng kho là số nguyên
không âm. Mã sản phẩm và tên danh mục là duy nhất. Danh mục đang có sản phẩm
không được xóa; có thể ẩn bằng `update`.

## Kiểm tra

```sh
php -l app/Models/ProductModel.php
php -l app/Models/CategoryModel.php
php -d extension=pdo_sqlite scripts/test-product-category.php
```

Test dùng SQLite in-memory để kiểm tra CRUD, tìm kiếm, lọc, bản ghi ẩn, dữ liệu sai,
SQL injection và khóa ngoại, không sửa dữ liệu ứng dụng. Test này không thay thế
việc import DDL và kiểm tra tích hợp trên MySQL với database chính thức của nhóm.

Giao diện hiện tại vẫn dùng localStorage; việc nối controller/API và View tới
hai model là bước tích hợp tiếp theo, không nằm trong hai file model này.
