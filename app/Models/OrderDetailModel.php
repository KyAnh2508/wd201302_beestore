<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use InvalidArgumentException;
use RuntimeException;

final class OrderDetailModel extends Model
{
    public function getByOrderId(int $orderId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM order_details WHERE order_id = :order_id ORDER BY id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetchAll();
    }

    /**
     * Lấy giá tin cậy từ bảng products trên server nếu có, fallback sang giá hợp lệ của item.
     */
    private function resolveTrustedUnitPrice(array $item): float
    {
        $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);

        if ($productId > 0) {
            $stmt = $this->db()->prepare('SELECT price FROM products WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $productId]);
            $row = $stmt->fetch();

            if ($row !== false && isset($row['price'])) {
                $dbPrice = (float) $row['price'];
                if ($dbPrice <= 0) {
                    throw new RuntimeException("Giá sản phẩm #{$productId} trong cơ sở dữ liệu không hợp lệ.");
                }
                return $dbPrice;
            }
        }

        $rawPrice = $item['unit_price'] ?? $item['price'] ?? null;
        if (!is_numeric($rawPrice)) {
            throw new InvalidArgumentException('Giá sản phẩm không hợp lệ.');
        }

        $price = (float) $rawPrice;
        if ($price <= 0) {
            throw new InvalidArgumentException('Giá sản phẩm phải lớn hơn 0.');
        }

        return $price;
    }

    /**
     * Validate và chuẩn hóa 1 dòng sản phẩm trước khi lưu.
     */
    public function normalizeItem(array $item): array
    {
        $rawQty = $item['quantity'] ?? $item['qty'] ?? null;
        if (filter_var($rawQty, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Số lượng sản phẩm phải là số nguyên.');
        }

        $qty = (int) $rawQty;
        if ($qty <= 0) {
            throw new InvalidArgumentException('Số lượng sản phẩm phải lớn hơn 0.');
        }

        $unitPrice = $this->resolveTrustedUnitPrice($item);
        $subtotal = round($unitPrice * $qty, 2);

        if ($subtotal <= 0) {
            throw new InvalidArgumentException('Thành tiền dòng sản phẩm không hợp lệ.');
        }

        $productName = trim((string) ($item['product_name'] ?? $item['name'] ?? ''));
        if ($productName === '') {
            throw new InvalidArgumentException('Tên sản phẩm không được để trống.');
        }

        return [
            'product_id'    => (int) ($item['product_id'] ?? $item['id'] ?? 0),
            'product_sku'   => isset($item['product_sku']) || isset($item['sku'])
                ? trim((string) ($item['product_sku'] ?? $item['sku']))
                : null,
            'product_name'  => $productName,
            'product_image' => isset($item['product_image']) || isset($item['image'])
                ? trim((string) ($item['product_image'] ?? $item['image']))
                : null,
            'color'         => trim((string) ($item['color'] ?? '')),
            'size'          => trim((string) ($item['size'] ?? '')),
            'unit_price'    => $unitPrice,
            'quantity'      => $qty,
            'subtotal'      => $subtotal,
        ];
    }

    public function create(int $orderId, array $item): int
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Mã đơn hàng không hợp lệ.');
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

    /**
     * Lưu danh sách sản phẩm và trả về tổng tiền thực tế đã tính từ server.
     */
    public function insertMany(int $orderId, array $items): float
    {
        if ($items === []) {
            throw new InvalidArgumentException('Đơn hàng phải có ít nhất một sản phẩm.');
        }

        $total = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Dữ liệu sản phẩm trong đơn không hợp lệ.');
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
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}