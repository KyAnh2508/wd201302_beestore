# Website bán quần áo — PHP MVC

Khung ban đầu cho đồ án: PHP 8.1+, MySQL 8+, PDO MySQL; Apache cần mod_rewrite và AllowOverride All nếu dùng .htaccess.

## Chạy trên máy

```sh
cp .env.example .env
# Sửa DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS trong .env.
mysql -u root -p < database/schema.sql
php -S localhost:8000 -t public public/index.php
```

Mở http://localhost:8000. Trang chủ không cần DB để hiển thị. Kiểm tra DB riêng:

```sh
php scripts/check-db.php
```

Apache: đặt DocumentRoot trỏ đến thư mục `public/` (khuyến nghị). File `public/.htaccess` chuyển URL về index.php. File `.htaccess` ở gốc hỗ trợ khi đặt toàn bộ dự án trong htdocs; chỉ các file trong public được phục vụ qua rewrite. Không chạy PHP server với gốc dự án làm DocumentRoot.

## Cấu trúc

```text
app/
  Controllers/HomeController.php
  Core/Controller.php, Model.php, Database.php
  Models/
  Views/home/index.php
config/database.php
public/index.php, .htaccess, assets/
database/schema.sql
scripts/check-db.php
storage/logs/
bootstrap.php
.env.example
```

Luồng xử lý: public/index.php → Controller → Model (PDO khi cần) → View. Route hiện có: `/`, `/home`; route khác trả 404. Thêm route bằng danh sách tường minh trong public/index.php.

PDO tái sử dụng kết nối trong mỗi request, dùng utf8mb4, exception và prepared statements thật. Truy vấn có dữ liệu người dùng phải dùng `prepare()`/`execute()`; không nối chuỗi SQL.

```php
$statement = \App\Core\Database::connection()->prepare('SELECT * FROM products WHERE id = :id');
$statement->execute(['id' => $id]);
$product = $statement->fetch();
```

Bảng `products` chỉ là ví dụ, chưa có trong schema. Không commit `.env`, mật khẩu hoặc API key.

## GitHub workflow của nhóm

- Tên repository: `wd201302_<ten-nhom>`.
- Nhóm trưởng mời thành viên và giảng viên; mọi người kiểm tra email và chấp nhận lời mời.
- Nhóm trưởng đưa khung ban đầu lên `main`, sau đó tạo nhánh theo tên từng thành viên (ví dụ `thanh-vien/ho-ten`).
- Thành viên chỉ code trên nhánh được giao. Không code hoặc push trực tiếp lên `main`.
- Hoàn tất công việc: push nhánh cá nhân và mở Pull Request vào `main`. Nhóm trưởng kiểm tra và merge.

Lần đầu lấy dự án (thay các giá trị trong dấu `<...>`):

```sh
git clone <URL_REPOSITORY>
cd <TEN_REPOSITORY>
git fetch origin
git switch --track origin/thanh-vien/<ten-cua-ban>
```

Nếu nhánh cá nhân đã có ở máy, dùng `git switch thanh-vien/<ten-cua-ban>`.
Trước khi bắt đầu việc mới, commit hoặc stash thay đổi đang làm, rồi cập nhật:

```sh
git switch main
git pull --ff-only origin main
git switch thanh-vien/<ten-cua-ban>
git merge main
```

Nếu có xung đột, giải quyết với nhóm trưởng trước khi push. Sau khi code xong:

```sh
git add <cac-file-da-sua>
git commit -m "feat: mo ta cong viec"
git push -u origin thanh-vien/<ten-cua-ban>
```

Mở Pull Request vào `main`, mô tả phần đã làm và cách kiểm tra. Nhóm trưởng review rồi merge. Không force push lên `main` hoặc nhánh của người khác.

Nên bật bảo vệ `main` trên GitHub: yêu cầu Pull Request và ít nhất một lượt duyệt trước khi merge (tùy khả năng của tài khoản/repository).

Dòng “GitHub Workflow” trong phân công là quy trình làm việc với nhánh; không yêu cầu GitHub Actions.
