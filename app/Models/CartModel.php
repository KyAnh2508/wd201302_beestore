<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DomainException;

final class CartModel
{
    public static function items(int $uid): array
    {
        $query = Database::connection()->prepare(
            'SELECT c.id_san_pham, c.so_luong, p.ten_san_pham, p.gia_san_pham, p.so_luong_kho
             FROM chi_tiet_gio_hang c JOIN san_pham p ON p.id_san_pham = c.id_san_pham
             WHERE c.id_nguoi_dung = ? ORDER BY c.id_chi_tiet_gio_hang DESC'
        );
        $query->execute([$uid]);
        return $query->fetchAll();
    }

    public static function update(int $uid, int $pid, int $qty): void
    {
        if ($qty < 0 || $qty > 99) {
            throw new DomainException('Số lượng phải từ 0 đến 99.');
        }

        $db = Database::connection();
        if ($qty === 0) {
            $query = $db->prepare('DELETE FROM chi_tiet_gio_hang WHERE id_nguoi_dung = ? AND id_san_pham = ?');
            $query->execute([$uid, $pid]);
            return;
        }

        $query = $db->prepare("SELECT so_luong_kho FROM san_pham WHERE id_san_pham = ? AND trang_thai = 'hien'");
        $query->execute([$pid]);
        $stock = $query->fetchColumn();
        if ($stock === false) {
            throw new DomainException('Sản phẩm không tồn tại.');
        }
        if ($qty > (int) $stock) {
            throw new DomainException('Số lượng vượt quá hàng tồn kho.');
        }

        $query = $db->prepare(
            'INSERT INTO chi_tiet_gio_hang (id_nguoi_dung, id_san_pham, so_luong)
             VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE so_luong = ?'
        );
        $query->execute([$uid, $pid, $qty, $qty]);
    }
}
