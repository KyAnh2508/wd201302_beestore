<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

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

    public function create(int $orderId, array $item): int
    {
        $qty = max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));
        $unitPrice = (float) ($item['unit_price'] ?? $item['price'] ?? 0);
        $subtotal = $unitPrice * $qty;

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
            'product_id'    => (int) ($item['product_id'] ?? $item['id'] ?? 0),
            'product_sku'   => $item['product_sku'] ?? $item['sku'] ?? null,
            'product_name'  => $item['product_name'] ?? $item['name'] ?? '',
            'product_image' => $item['product_image'] ?? $item['image'] ?? null,
            'color'         => $item['color'] ?? '',
            'size'          => $item['size'] ?? '',
            'unit_price'    => $unitPrice,
            'quantity'      => $qty,
            'subtotal'      => $subtotal,
        ]);

        return (int) $this->db()->lastInsertId();
    }

    public function insertMany(int $orderId, array $items): void
    {
        foreach ($items as $item) {
            $this->create($orderId, $item);
        }
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
            "SELECT
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
             LIMIT {$limit}"
        );

        $stmt->execute(['completed_status' => OrderModel::STATUS_COMPLETED]);
        return $stmt->fetchAll();
    }
}