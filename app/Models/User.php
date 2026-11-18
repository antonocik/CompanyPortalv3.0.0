<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = [
        'fullname', 'username', 'email', 'photo_id', 'password', 'role',
        'department_id', 'status', 'last_login', 'face_descriptor',
    ];

    public function findByLogin(string $login): ?array
    {
        $stmt = Database::query(
            'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$login, $login]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function withDepartment(): array
    {
        return Database::query(
            'SELECT u.*, d.department_name
               FROM users u
               LEFT JOIN departments d ON d.id = u.department_id
              ORDER BY u.fullname ASC'
        )->fetchAll();
    }

    public function findWithDepartment(int $id): ?array
    {
        $row = Database::query(
            'SELECT u.*, d.department_name
               FROM users u
               LEFT JOIN departments d ON d.id = u.department_id
              WHERE u.id = ? LIMIT 1',
            [$id]
        )->fetch();
        return $row ?: null;
    }

    public function updateLastLogin(int $id): void
    {
        $this->db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$id]);
    }

    public function countByRole(string $role): int
    {
        return $this->count('role = ?', [$role]);
    }

    public function countActive(): int
    {
        return $this->count('status = ?', ['active']);
    }

    /** Find the enrolled user whose face descriptor is closest to the given one. */
    public function findFaceMatch(array $descriptor, float $threshold = 0.6): ?array
    {
        $rows = Database::query(
            'SELECT id, fullname, username, face_descriptor FROM users WHERE face_descriptor IS NOT NULL'
        )->fetchAll();

        $best = null;
        $bestDist = $threshold;
        foreach ($rows as $row) {
            $stored = json_decode((string) $row['face_descriptor'], true);
            if (!is_array($stored) || count($stored) !== count($descriptor)) {
                continue;
            }
            $sum = 0.0;
            for ($i = 0; $i < count($descriptor); $i++) {
                $d = (float) $descriptor[$i] - (float) $stored[$i];
                $sum += $d * $d;
            }
            $dist = sqrt($sum);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $row;
                $best['_distance'] = $dist;
            }
        }
        return $best;
    }
}
