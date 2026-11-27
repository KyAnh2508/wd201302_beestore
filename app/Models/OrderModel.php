<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use InvalidArgumentException;
use Throwable;

final class OrderModel extends Model
{
    public const STATUS_PENDING    = 'Chờ xác nhận';
    public const STATUS_CONFIRMED  = 'Đã xác nhận';
    public const STATUS_PROCESSING = 'Đang xử lý';
    public const STATUS_COMPLETED  = 'Hoàn thành';
    public const STATUS_CANCELLED  = 'Đã hủy';

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Bảng chuyển trạng thái hợp lệ:
     * - Chờ xác nhận -> Đã xác nhận hoặc Đã hủy
     * - Đã xác nhận -> Đang xử lý hoặc Đã hủy
     * - Đang xử lý -> Hoàn thành
     * - Hoàn thành / Đã hủy -> không thể đổi sang trạng thái khác
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING    => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED  => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED  => [],
        self::STATUS_CANCELLED  => [],
    ];

    private function normalizeNonEmptyReason(string $reason, string $fieldLabel = 'Lý do hủy'): string
    {
        $clean = trim($reason);
        if ($clean === '') {
            throw new InvalidArgumentException("{$fieldLabel} không được để trống hoặc chỉ chứa khoảng trắng.");
        }
        return $clean;
    }

    public function canTransition(string $currentStatus, string $newStatus): bool
    {
        if (!isset(self::ALLOWED_TRANSITIONS[$currentStatus])) {
            return false;
        }
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$currentStatus], true);
    }

    public function getAll(string $keyword = '', string $status = ''): array
    {
        $sql = 'SELECT * FROM orders WHERE 1=1';
        $params = [];

        if ($keyword !== '') {
            $sql .= ' AND (order_code LIKE :kw_code OR customer_phone LIKE :kw_phone OR customer_name LIKE :kw_name)';
            $like = '%' . $keyword . '%';
            $params['kw_code'] = $like;
            $params['kw_phone'] = $like;
            $params['kw_name'] = $like;
        }

        if ($status !== '' && in_array($status, self::ALL_STATUSES, true)) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getByCustomerEmail(string $email, string $status = ''): array
    {
        $sql = 'SELECT * FROM orders WHERE customer_email = :email';
        $params = ['email' => $email];

        if ($status !== '' && in_array($status, self::ALL_STATUSES, true)) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function findByCode(string $orderCode): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM orders WHERE order_code = :order_code LIMIT 1');
        $stmt->execute(['order_code' => $orderCode]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Tạo đơn hàng mới kèm chi tiết sản phẩm trong 1 Transaction.
     * Tổng tiền được tính lại từ giá tin cậy của từng item và đồng bộ lại từ bảng order_details.
     */
    public function createWithDetails(array $orderData, array $items): int
    {
        if ($items === []) {
            throw new InvalidArgumentException('Không thể tạo đơn hàng rỗng.');
        }

        $detailModel = new OrderDetailModel();

        // Validate toàn bộ item và tính tổng dự kiến trước khi mở transaction
        $computedTotal = 0.0;
        $normalizedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Dữ liệu sản phẩm không hợp lệ.');
            }
            $norm = $detailModel->normalizeItem($item);
            $normalizedItems[] = $norm;
            $computedTotal += $norm['subtotal'];
        }
        $computedTotal = round($computedTotal, 2);

        if ($computedTotal <= 0) {
            throw new InvalidArgumentException('Tổng tiền đơn hàng phải lớn hơn 0.');
        }

        $pdo = $this->db();
        $pdo->beginTransaction();

        try {
            $orderCode = $orderData['order_code'] ?? ('BEE' . substr((string) round(microtime(true) * 1000), -9) . random_int(10, 99));

            $stmt = $pdo->prepare(
                'INSERT INTO orders (
                    order_code, user_id, customer_email, customer_name, customer_phone,
                    shipping_address, note, status, total_amount
                ) VALUES (
                    :order_code, :user_id, :customer_email, :customer_name, :customer_phone,
                    :shipping_address, :note, :status, :total_amount
                )'
            );

            $stmt->execute([
                'order_code'       => $orderCode,
                'user_id'          => $orderData['user_id'] ?? null,
                'customer_email'   => trim((string) ($orderData['customer_email'] ?? '')),
                'customer_name'    => trim((string) ($orderData['customer_name'] ?? '')),
                'customer_phone'   => trim((string) ($orderData['customer_phone'] ?? '')),
                'shipping_address' => trim((string) ($orderData['shipping_address'] ?? '')),
                'note'             => isset($orderData['note']) ? trim((string) $orderData['note']) : null,
                'status'           => self::STATUS_PENDING,
                'total_amount'     => $computedTotal,
            ]);

            $orderId = (int) $pdo->lastInsertId();

            $detailModel->insertMany($orderId, $normalizedItems);

            // Đối chiếu lại tổng tiền trực tiếp từ order_details để đảm bảo khớp 100%
            $verifiedTotal = $detailModel->calculateOrderTotal($orderId);
            if ($verifiedTotal <= 0) {
                throw new InvalidArgumentException('Tổng tiền đơn hàng sau khi lưu chi tiết không hợp lệ.');
            }

            $updateTotalStmt = $pdo->prepare('UPDATE orders SET total_amount = :total WHERE id = :id');
            $updateTotalStmt->execute([
                'total' => $verifiedTotal,
                'id'    => $orderId,
            ]);

            $pdo->commit();
            return $orderId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function advanceStatus(int $id): bool
    {
        $order = $this->findById($id);
        if ($order === null || !empty($order['cancel_request'])) {
            return false;
        }

        $nextMap = [
            self::STATUS_PENDING    => self::STATUS_CONFIRMED,
            self::STATUS_CONFIRMED  => self::STATUS_PROCESSING,
            self::STATUS_PROCESSING => self::STATUS_COMPLETED,
        ];

        $current = (string) $order['status'];
        if (!isset($nextMap[$current])) {
            return false;
        }

        return $this->updateStatus($id, $nextMap[$current]);
    }

    /**
     * Chỉ cho phép chuyển trạng thái theo luồng hợp lệ đã định nghĩa trong ALLOWED_TRANSITIONS.
     */
    public function updateStatus(int $id, string $newStatus): bool
    {
        if (!in_array($newStatus, self::ALL_STATUSES, true)) {
            return false;
        }

        $order = $this->findById($id);
        if ($order === null) {
            return false;
        }

        $currentStatus = (string) $order['status'];
        if ($currentStatus === $newStatus) {
            return true;
        }

        if (!$this->canTransition($currentStatus, $newStatus)) {
            throw new InvalidArgumentException(
                "Không thể chuyển trạng thái đơn hàng từ '{$currentStatus}' sang '{$newStatus}'."
            );
        }

        if ($newStatus !== self::STATUS_CANCELLED && !empty($order['cancel_request'])) {
            return false;
        }

        $completedAt = $newStatus === self::STATUS_COMPLETED ? date('Y-m-d H:i:s') : null;

        $stmt = $this->db()->prepare(
            'UPDATE orders
             SET status = :status,
                 completed_at = COALESCE(:completed_at, completed_at)
             WHERE id = :id'
        );

        return $stmt->execute([
            'status'       => $newStatus,
            'completed_at' => $completedAt,
            'id'           => $id,
        ]);
    }

    public function requestCancel(int $id, string $reason): bool
    {
        $cleanReason = $this->normalizeNonEmptyReason($reason, 'Lý do yêu cầu hủy');

        $stmt = $this->db()->prepare(
            'UPDATE orders
             SET cancel_request = :reason
             WHERE id = :id AND status = :status AND (cancel_request IS NULL OR TRIM(cancel_request) = \'\')'
        );

        $stmt->execute([
            'reason' => $cleanReason,
            'id'     => $id,
            'status' => self::STATUS_PENDING,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function resolveCancelRequest(int $id, bool $approve): bool
    {
        $order = $this->findById($id);
        if ($order === null || $order['status'] !== self::STATUS_PENDING) {
            return false;
        }

        $pendingReason = trim((string) ($order['cancel_request'] ?? ''));
        if ($pendingReason === '') {
            // Dọn sạch yêu cầu hủy rỗng/khoảng trắng bị kẹt nếu có
            $cleanStmt = $this->db()->prepare('UPDATE orders SET cancel_request = NULL WHERE id = :id');
            $cleanStmt->execute(['id' => $id]);
            return false;
        }

        if ($approve) {
            $stmt = $this->db()->prepare(
                'UPDATE orders
                 SET status = :status,
                     cancel_reason = :reason,
                     cancel_request = NULL
                 WHERE id = :id'
            );
            return $stmt->execute([
                'status' => self::STATUS_CANCELLED,
                'reason' => $pendingReason,
                'id'     => $id,
            ]);
        }

        $stmt = $this->db()->prepare(
            'UPDATE orders
             SET cancel_rejection = :rejection,
                 cancel_request = NULL
             WHERE id = :id'
        );
        return $stmt->execute([
            'rejection' => 'Cửa hàng không chấp nhận yêu cầu hủy.',
            'id'        => $id,
        ]);
    }

    public function adminCancel(int $id, string $reason): bool
    {
        $cleanReason = $this->normalizeNonEmptyReason($reason, 'Lý do hủy đơn');

        $stmt = $this->db()->prepare(
            'UPDATE orders
             SET status = :cancelled,
                 cancel_reason = :reason,
                 cancel_request = NULL
             WHERE id = :id AND status IN (:s1, :s2)'
        );

        $stmt->execute([
            'cancelled' => self::STATUS_CANCELLED,
            'reason'    => $cleanReason,
            'id'        => $id,
            's1'        => self::STATUS_PENDING,
            's2'        => self::STATUS_CONFIRMED,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function getSummaryStats(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status = :pending THEN 1 ELSE 0 END) AS pending_orders,
                SUM(CASE WHEN status = :completed THEN 1 ELSE 0 END) AS completed_orders,
                COALESCE(SUM(CASE WHEN status = :completed_rev THEN total_amount ELSE 0 END), 0) AS total_revenue
             FROM orders'
        );

        $stmt->execute([
            'pending'       => self::STATUS_PENDING,
            'completed'     => self::STATUS_COMPLETED,
            'completed_rev' => self::STATUS_COMPLETED,
        ]);

        return $stmt->fetch() ?: [
            'total_orders'     => 0,
            'pending_orders'   => 0,
            'completed_orders' => 0,
            'total_revenue'    => 0,
        ];
    }
}