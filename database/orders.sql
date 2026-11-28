-- Tạo và sử dụng cơ sở dữ liệu dự án (đổi tên DB nếu nhóm dùng tên khác)
CREATE DATABASE IF NOT EXISTS wd201302_beestore
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE wd201302_beestore;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- Bảng orders: lưu đơn hàng, thông tin nhận hàng, trạng thái và hủy đơn
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_code VARCHAR(50) NOT NULL,
    user_id BIGINT UNSIGNED NULL DEFAULT NULL,
    customer_email VARCHAR(150) NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(25) NOT NULL,
    shipping_address VARCHAR(255) NOT NULL,
    note TEXT NULL DEFAULT NULL,
    status ENUM(
        'Chờ xác nhận',
        'Đã xác nhận',
        'Đang xử lý',
        'Hoàn thành',
        'Đã hủy'
    ) NOT NULL DEFAULT 'Chờ xác nhận',
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    cancel_request VARCHAR(255) NULL DEFAULT NULL,
    cancel_reason VARCHAR(255) NULL DEFAULT NULL,
    cancel_rejection VARCHAR(255) NULL DEFAULT NULL,
    completed_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_order_code (order_code),
    KEY idx_orders_user_id (user_id),
    KEY idx_orders_customer_email (customer_email),
    KEY idx_orders_customer_phone (customer_phone),
    KEY idx_orders_status (status),
    KEY idx_orders_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Bảng order_details: lưu sản phẩm theo từng đơn, giá mua, số lượng, thành tiền
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_details (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_sku VARCHAR(60) NULL DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    product_image VARCHAR(255) NULL DEFAULT NULL,
    color VARCHAR(60) NOT NULL DEFAULT '',
    size VARCHAR(60) NOT NULL DEFAULT '',
    unit_price DECIMAL(12, 2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_details_order_id (order_id),
    KEY idx_order_details_product_id (product_id),
    CONSTRAINT fk_order_details_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_order_details_product
        FOREIGN KEY (product_id) REFERENCES san_pham (id_san_pham)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Khóa ngoại tới bảng người dùng:
-- Kiểm tra tên bảng & khóa chính của bảng người dùng trong DB của nhóm
-- (ví dụ: users(id) hoặc nguoi_dung(id_nguoi_dung)), sau đó bỏ comment và chạy:
-- --------------------------------------------------------
-- ALTER TABLE orders
--   ADD CONSTRAINT fk_orders_user
--   FOREIGN KEY (user_id) REFERENCES users (id)
--   ON DELETE SET NULL
--   ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;