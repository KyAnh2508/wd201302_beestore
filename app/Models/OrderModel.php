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

    private const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING    => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED  => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED  => [],
        self::STATUS_CANCELLED  => [],
    ];

    /**
     * Chuẩn hóa chuỗi lý do: chỉ kiểm tra trim($value) === '' để chuỗi "0" vẫn hợp lệ.
     */
    private function normalizeNonEmptyReason(string $reason, string $fieldLabel = 'Lý do hủy'): string
    {
        $clean = trim($reason);
        if ($clean === '') {
            throw new InvalidArgumentException("{$fieldLabel} không được để trống.");
        }

        return $clean;
    }

    /**
     * Kiểm tra đơn có yêu cầu hủy đang chờ xử lý hay không (không dùng empty() để không nhầm với "0").
     */
    private function hasPendingCancelRequest(array $order): bool
    {
        $req = $order['cancel_request'] ?? null;
        return $req !== null && trim((string) $req) !== '';
    }

    /**
     * Kiểm tra đầy đủ thông tin nhận hàng trực tiếp từ dữ liệu đặt hàng.
     */
    private function validateShippingInfo(array $orderData): array
    {
        $name = trim((string) ($orderData['customer_name'] ?? ''));
        $email = trim((string) ($orderData['customer_email'] ?? ''));
        $phone = preg_replace('/\s+/', '', trim((string) ($orderData['customer_phone'] ?? ''))) ?? '';
        $address = trim((string) ($orderData['shipping_address'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Vui lòng nhập tên người nhận.');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Vui lòng nhập email nhận hàng hợp lệ.');
        }

        if ($phone === '' || !preg_match('/^(0|\+84)[0-9]{8,10}$/', $phone)) {
            throw new InvalidArgumentException('Vui lòng nhập số điện thoại nhận hàng hợp lệ.');
        }

        if ($address === '') {
            throw new InvalidArgumentException('Vui lòng nhập địa chỉ nhận hàng.');
        }

        return [
            'customer_name'    => $name,
            'customer_email'   => $email,
            'customer_phone'   => $phone,
            'shipping_address' => $address,
        ];
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

    public function createWithDetails(array $orderData, array $items): int
    {
        if ($items === []) {
            throw new InvalidArgumentException('Đơn hàng phải có ít nhất một sản phẩm.');
        }

        $shipping = $this->validateShippingInfo($orderData);
        $detailModel = new OrderDetailModel();

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
                'customer_email'   => $shipping['customer_email'],
                'customer_name'    => $shipping['customer_name'],
                'customer_phone'   => $shipping['customer_phone'],
                'shipping_address' => $shipping['shipping_address'],
                'note'             => isset($orderData['note']) && trim((string) $orderData['note']) !== ''
                    ? trim((string) $orderData['note'])
                    : null,
                'status'           => self::STATUS_PENDING,
                'total_amount'     => $computedTotal,
            ]);

            $orderId = (int) $pdo->lastInsertId();
            $detailModel->insertMany($orderId, $normalizedItems);

            $verifiedTotal = $detailModel->calculateOrderTotal($orderId);
            if ($verifiedTotal <= 0) {
                throw new InvalidArgumentException('Tổng tiền đơn hàng không hợp lệ.');
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
        if ($order === null || $this->hasPendingCancelRequest($order)) {
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

        if ($this->hasPendingCancelRequest($order) && $newStatus !== self::STATUS_CANCELLED) {
            return false;
        }

        if (!$this->canTransition($currentStatus, $newStatus)) {
            throw new InvalidArgumentException(
                "Không thể chuyển trạng thái đơn hàng từ '{$currentStatus}' sang '{$newStatus}'."
            );
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
        $cleanReason = $this->normalizeNonEmptyReason($reason, 'Lý do hủy');

        $order = $this->findById($id);
        if ($order === null || $order['status'] !== self::STATUS_PENDING || $this->hasPendingCancelRequest($order)) {
            return false;
        }

        $stmt = $this->db()->prepare(
            'UPDATE orders
             SET cancel_request = :reason
             WHERE id = :id AND status = :status'
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
        if ($order === null || $order['status'] !== self::STATUS_PENDING || !$this->hasPendingCancelRequest($order)) {
            return false;
        }

        $pendingReason = trim((string) $order['cancel_request']);

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
        $cleanReason = $this->normalizeNonEmptyReason($reason, 'Lý do hủy');

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