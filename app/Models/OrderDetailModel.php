<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use InvalidArgumentException;
use PDO;

final class OrderDetailModel extends Model
{
    public const MAX_QUANTITY = 99;

    public function getByOrderId(int $orderId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM order_details WHERE order_id = :order_id ORDER BY id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetchAll();
    }

    /**
     * Truy vấn sản phẩm từ bảng san_pham và trả về thông tin tin cậy từ server.
     * Bắt buộc sản phẩm tồn tại trong san_pham; không fallback sang giá do client gửi.
     */
    private function resolveProductFromDb(array $item): array
    {
        $rawId = $item['product_id'] ?? $item['id'] ?? null;
        if (filter_var($rawId, FILTER_VALIDATE_INT) === false || (int) $rawId <= 0) {
            throw new InvalidArgumentException('Sản phẩm không tồn tại.');
        }

        $productId = (int) $rawId;
        $stmt = $this->db()->prepare('SELECT * FROM san_pham WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();

        if (!is_array($product) || $product === []) {
            throw new InvalidArgumentException('Sản phẩm không tồn tại.');
        }

        $rawPrice = $product['gia'] ?? $product['price'] ?? $product['unit_price'] ?? null;
        if (!is_numeric($rawPrice) || (float) $rawPrice <= 0) {
            throw new InvalidArgumentException('Giá sản phẩm không hợp lệ.');
        }

        $name = trim((string) (
            $product['ten_san_pham']
            ?? $product['name']
            ?? $product['title']
            ?? $item['product_name']
            ?? $item['name']
            ?? ''
        ));

        if ($name === '') {
            throw new InvalidArgumentException('Sản phẩm không tồn tại.');
        }

        $sku = $product['ma_san_pham'] ?? $product['sku'] ?? $item['product_sku'] ?? $item['sku'] ?? null;
        $image = $product['hinh_anh'] ?? $product['image'] ?? $item['product_image'] ?? $item['image'] ?? null;

        return [
            'product_id'    => $productId,
            'product_sku'   => $sku !== null && trim((string) $sku) !== '' ? trim((string) $sku) : null,
            'product_name'  => $name,
            'product_image' => $image !== null && trim((string) $image) !== '' ? trim((string) $image) : null,
            'unit_price'    => round((float) $rawPrice, 2),
        ];
    }

    /**
     * Kiểm tra và chuẩn hóa 1 dòng sản phẩm trước khi lưu.
     */
    public function normalizeItem(array $item): array
    {
        $rawQty = $item['quantity'] ?? $item['qty'] ?? null;
        if (filter_var($rawQty, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Số lượng sản phẩm không hợp lệ.');
        }

        $qty = (int) $rawQty;
        if ($qty <= 0 || $qty > self::MAX_QUANTITY) {
            throw new InvalidArgumentException('Số lượng sản phẩm không hợp lệ (chỉ từ 1 đến ' . self::MAX_QUANTITY . ').');
        }

        $product = $this->resolveProductFromDb($item);
        $unitPrice = $product['unit_price'];
        $subtotal = round($unitPrice * $qty, 2);

        if ($subtotal <= 0) {
            throw new InvalidArgumentException('Thành tiền không hợp lệ.');
        }

        return [
            'product_id'    => $product['product_id'],
            'product_sku'   => $product['product_sku'],
            'product_name'  => $product['product_name'],
            'product_image' => $product['product_image'],
            'color'         => trim((string) ($item['color'] ?? $item['mau_sac'] ?? '')),
            'size'          => trim((string) ($item['size'] ?? $item['kich_co'] ?? '')),
            'unit_price'    => $unitPrice,
            'quantity'      => $qty,
            'subtotal'      => $subtotal,
        ];
    }

    public function create(int $orderId, array $item): int
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Đơn hàng không hợp lệ.');
        }

        $normalized = $this->normalizeItem($item);

        $stmt = $this->db()->prepare(
            'INSERT INTO order_details (
                order_id, product_id, product_sku, product_name, product_image,
                color, size, unit_price, quantity, subtotal
            ) VALUES (
                :order_id, :product_id, :product_sku, :product_name, :product_image,
                :color, :size, :unit_price, :quantity, :subtotal
            )'
        );

        $stmt->execute([
            'order_id'      => $orderId,
            'product_id'    => $normalized['product_id'],
            'product_sku'   => $normalized['product_sku'],
            'product_name'  => $normalized['product_name'],
            'product_image' => $normalized['product_image'],
            'color'         => $normalized['color'],
            'size'          => $normalized['size'],
            'unit_price'    => $normalized['unit_price'],
            'quantity'      => $normalized['quantity'],
            'subtotal'      => $normalized['subtotal'],
        ]);

        return (int) $this->db()->lastInsertId();
    }

    public function insertMany(int $orderId, array $items): float
    {
        if ($items === []) {
            throw new InvalidArgumentException('Đơn hàng phải có ít nhất một sản phẩm.');
        }

        $total = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Dữ liệu sản phẩm không hợp lệ.');
            }
            $normalized = $this->normalizeItem($item);
            $this->create($orderId, $normalized);
            $total += $normalized['subtotal'];
        }

        return round($total, 2);
    }

    public function calculateOrderTotal(int $orderId): float
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(subtotal), 0) AS total FROM order_details WHERE order_id = :order_id'
        );
        $stmt->execute(['order_id' => $orderId]);
        $row = $stmt->fetch();

        return (float) ($row['total'] ?? 0);
    }

    public function getTopSellingProducts(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        $stmt = $this->db()->prepare(
            'SELECT
                od.product_id,
                od.product_sku,
                od.product_name,
                od.product_image,
                SUM(od.quantity) AS total_sold,
                SUM(od.subtotal) AS total_revenue
             FROM order_details od
             INNER JOIN orders o ON o.id = od.order_id
             WHERE o.status = :completed_status
             GROUP BY od.product_id, od.product_sku, od.product_name, od.product_image
             ORDER BY total_sold DESC
             LIMIT :limit'
        );

        $stmt->bindValue(':completed_status', OrderModel::STATUS_COMPLETED);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}