<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DomainException;

final class UserModel
{
    private const MAX_NAME_LENGTH = 100;
    private const MAX_ADDRESS_LENGTH = 255;

    public static function byEmail(string $email): ?array
    {
        $query = Database::connection()->prepare(
            'SELECT * FROM nguoi_dung WHERE email = ? LIMIT 1'
        );
        $query->execute([strtolower(trim($email))]);
        return $query->fetch() ?: null;
    }

    public static function profile(int $uid): ?array
    {
        $query = Database::connection()->prepare(
            'SELECT id_nguoi_dung, ho_ten, email, sdt, dia_chi_mac_dinh, vai_tro, trang_thai, ngay_tao
             FROM nguoi_dung WHERE id_nguoi_dung = ?'
        );
        $query->execute([$uid]);
        return $query->fetch() ?: null;
    }

    public static function register(string $name, string $email, string $password): int
    {
        self::validatePasswordLength($password);
        $name = self::normalizeName($name);
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Thông tin đăng ký không hợp lệ.');
        }

        $db = Database::connection();
        $query = $db->prepare(
            "INSERT INTO nguoi_dung (ho_ten, email, mat_khau_hash, vai_tro, trang_thai)
             VALUES (?, ?, ?, 'khach_hang', 'hoat_dong')"
        );
        $query->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        return (int) $db->lastInsertId();
    }

    public static function verify(string $email, string $password): ?array
    {
        $user = self::byEmail($email);
        if ($user === null || $user['trang_thai'] !== 'hoat_dong' || !password_verify($password, $user['mat_khau_hash'])) {
            return null;
        }

        unset($user['mat_khau_hash']);
        return $user;
    }

    public static function updateProfile(int $uid, array $data): bool
    {
        $fields = array_intersect_key($data, array_flip(['ho_ten', 'sdt', 'dia_chi_mac_dinh']));
        if ($fields === []) {
            return false;
        }

        if (array_key_exists('ho_ten', $fields)) {
            $fields['ho_ten'] = self::normalizeName($fields['ho_ten']);
        }
        if (array_key_exists('sdt', $fields)) {
            $phone = self::normalizeProfileText($fields['sdt']);
            if ($phone !== null && !preg_match('/\A(?:0|\+84)[0-9]{8,10}\z/', $phone)) {
                throw new DomainException('Số điện thoại không đúng định dạng.');
            }
            $fields['sdt'] = $phone;
        }
        if (array_key_exists('dia_chi_mac_dinh', $fields)) {
            $address = self::normalizeProfileText($fields['dia_chi_mac_dinh']);
            if ($address !== null && mb_strlen($address, 'UTF-8') > self::MAX_ADDRESS_LENGTH) {
                throw new DomainException('Địa chỉ tối đa 255 ký tự.');
            }
            $fields['dia_chi_mac_dinh'] = $address;
        }

        $assignments = implode(', ', array_map(static fn (string $field): string => "$field = ?", array_keys($fields)));
        $query = Database::connection()->prepare("UPDATE nguoi_dung SET $assignments WHERE id_nguoi_dung = ?");
        return $query->execute([...array_values($fields), $uid]);
    }

    private static function normalizeName(mixed $value): string
    {
        $name = self::normalizeProfileText($value);
        $length = $name === null ? 0 : mb_strlen($name, 'UTF-8');
        if ($length < 2 || $length > self::MAX_NAME_LENGTH) {
            throw new DomainException('Họ tên phải có từ 2 đến 100 ký tự.');
        }
        return $name;
    }

    private static function normalizeProfileText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = is_string($value) ? preg_replace('/\A\s+|\s+\z/u', '', $value) : null;
        if ($trimmed === null) {
            throw new DomainException('Thông tin hồ sơ không hợp lệ.');
        }
        return $trimmed === '' ? null : $trimmed;
    }

    public static function changePassword(int $uid, string $old, string $new): void
    {
        self::validatePasswordLength($new);
        $db = Database::connection();
        $query = $db->prepare('SELECT mat_khau_hash FROM nguoi_dung WHERE id_nguoi_dung = ?');
        $query->execute([$uid]);
        $hash = $query->fetchColumn();
        if ($hash === false || !password_verify($old, $hash)) {
            throw new DomainException('Mật khẩu cũ không hợp lệ.');
        }

        $query = $db->prepare(
            'UPDATE nguoi_dung SET mat_khau_hash = ? WHERE id_nguoi_dung = ? AND mat_khau_hash = ?'
        );
        $query->execute([password_hash($new, PASSWORD_DEFAULT), $uid, $hash]);
        if ($query->rowCount() === 0) {
            throw new DomainException('Mật khẩu đã thay đổi. Vui lòng thử lại.');
        }
    }

    private static function validatePasswordLength(string $password): void
    {
        $length = strlen($password);
        if ($length < 8 || $length > 72) {
            throw new DomainException('Mật khẩu phải từ 8 đến 72 byte.');
        }
    }
}
