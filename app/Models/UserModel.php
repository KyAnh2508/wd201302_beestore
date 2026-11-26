<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DomainException;

final class UserModel
{
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
        $name = trim($name);
        $email = strtolower(trim($email));
        if (mb_strlen($name, 'UTF-8') < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
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

        $assignments = implode(', ', array_map(static fn (string $field): string => "$field = ?", array_keys($fields)));
        $query = Database::connection()->prepare("UPDATE nguoi_dung SET $assignments WHERE id_nguoi_dung = ?");
        return $query->execute([...array_values($fields), $uid]);
    }

    public static function changePassword(int $uid, string $old, string $new): void
    {
        $db = Database::connection();
        $query = $db->prepare('SELECT mat_khau_hash FROM nguoi_dung WHERE id_nguoi_dung = ?');
        $query->execute([$uid]);
        $hash = $query->fetchColumn();
        if ($hash === false || !password_verify($old, $hash) || strlen($new) < 8) {
            throw new DomainException('Mật khẩu cũ hoặc mới không hợp lệ.');
        }

        $query = $db->prepare(
            'UPDATE nguoi_dung SET mat_khau_hash = ? WHERE id_nguoi_dung = ? AND mat_khau_hash = ?'
        );
        $query->execute([password_hash($new, PASSWORD_DEFAULT), $uid, $hash]);
        if ($query->rowCount() === 0) {
            throw new DomainException('Mật khẩu đã thay đổi. Vui lòng thử lại.');
        }
    }
}
