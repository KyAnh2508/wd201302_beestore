<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DomainException;
use Throwable;

final class CartModel
{
    public static function items(int $uid): array
    {
        $query = Database::connection()->prepare(
            'SELECT c.id_san_pham, c.so_luong, p.ten_san_pham, p.gia_san_pham
             FROM chi_tiet_gio_hang c JOIN san_pham p ON p.id_san_pham = c.id_san_pham
             WHERE c.id_nguoi_dung = ? ORDER BY c.id_chi_tiet_gio_hang DESC'
        );
        $query->execute([$uid]);
        return $query->fetchAll();
    }

    public static function checkoutItems(int $uid): array
    {
        $query = Database::connection()->prepare(
            "SELECT p.id_san_pham AS product_id, p.ma_san_pham AS product_sku,
                    p.ten_san_pham AS product_name, p.hinh_anh AS product_image,
                    p.gia_san_pham AS unit_price, c.so_luong AS quantity,
                    (p.trang_thai = 'hien' AND d.trang_thai = 'hien') AS available
             FROM chi_tiet_gio_hang c
             LEFT JOIN san_pham p ON p.id_san_pham = c.id_san_pham
             LEFT JOIN danh_muc d ON d.id_danh_muc = p.id_danh_muc
             WHERE c.id_nguoi_dung = ? ORDER BY c.id_chi_tiet_gio_hang DESC"
        );
        $query->execute([$uid]);
        $items = [];
        foreach ($query->fetchAll() as $item) {
            if (!(bool) $item['available']) {
                throw new DomainException('Hãy xóa sản phẩm không còn hiển thị trước khi đặt hàng.');
            }
            unset($item['available']);
            $items[] = $item;
        }
        return $items;
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

        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $query = $db->prepare(
                "SELECT p.id_san_pham FROM san_pham p
                 JOIN danh_muc d ON d.id_danh_muc = p.id_danh_muc
                 WHERE p.id_san_pham = ? AND p.trang_thai = 'hien' AND d.trang_thai = 'hien'
                 FOR SHARE"
            );
            $query->execute([$pid]);
            if ($query->fetchColumn() === false) {
                throw new DomainException('Sản phẩm không tồn tại hoặc đang bị ẩn.');
            }
            $query->closeCursor();

            $query = $db->prepare(
                'INSERT INTO chi_tiet_gio_hang (id_nguoi_dung, id_san_pham, so_luong)
                 VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE so_luong = ?'
            );
            $query->execute([$uid, $pid, $qty, $qty]);
            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}
