CREATE DATABASE IF NOT EXISTS website_ban_quan_ao
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE website_ban_quan_ao;

CREATE TABLE IF NOT EXISTS danh_muc (
    id_danh_muc INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ten_danh_muc VARCHAR(100) NOT NULL,
    mo_ta TEXT NULL,
    trang_thai ENUM('hien', 'an') NOT NULL DEFAULT 'hien',
    ngay_tao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_danh_muc_ten (ten_danh_muc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS san_pham (
    id_san_pham INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_danh_muc INT NOT NULL,
    ma_san_pham VARCHAR(50) NOT NULL,
    ten_san_pham VARCHAR(200) NOT NULL,
    mo_ta TEXT NULL,
    gia_san_pham DECIMAL(12, 2) NOT NULL,
    hinh_anh VARCHAR(255) NULL,
    so_luong_kho INT NOT NULL DEFAULT 0,
    trang_thai ENUM('hien', 'an') NOT NULL DEFAULT 'hien',
    ngay_tao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_san_pham_ma (ma_san_pham),
    KEY idx_san_pham_danh_muc (id_danh_muc),
    CONSTRAINT fk_san_pham_danh_muc FOREIGN KEY (id_danh_muc)
        REFERENCES danh_muc (id_danh_muc) ON DELETE RESTRICT,
    CONSTRAINT chk_san_pham_gia CHECK (gia_san_pham >= 0),
    CONSTRAINT chk_san_pham_kho CHECK (so_luong_kho >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS nguoi_dung (
    id_nguoi_dung INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ho_ten VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    mat_khau_hash VARCHAR(255) NOT NULL,
    sdt VARCHAR(13) NULL,
    dia_chi_mac_dinh VARCHAR(255) NULL,
    vai_tro VARCHAR(32) NOT NULL DEFAULT 'khach_hang',
    trang_thai VARCHAR(32) NOT NULL DEFAULT 'hoat_dong',
    ngay_tao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nguoi_dung_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chi_tiet_gio_hang (
    id_chi_tiet_gio_hang INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_nguoi_dung INT NOT NULL,
    id_san_pham INT NOT NULL,
    so_luong INT NOT NULL,
    UNIQUE KEY uq_gio_hang_nguoi_dung_san_pham (id_nguoi_dung, id_san_pham),
    KEY idx_gio_hang_san_pham (id_san_pham),
    CONSTRAINT fk_gio_hang_nguoi_dung FOREIGN KEY (id_nguoi_dung)
        REFERENCES nguoi_dung (id_nguoi_dung) ON DELETE CASCADE,
    CONSTRAINT fk_gio_hang_san_pham FOREIGN KEY (id_san_pham)
        REFERENCES san_pham (id_san_pham) ON DELETE CASCADE,
    CONSTRAINT chk_gio_hang_so_luong CHECK (so_luong BETWEEN 1 AND 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
