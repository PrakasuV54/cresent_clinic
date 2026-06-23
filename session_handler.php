<?php
require_once __DIR__ . '/db.php';

class DatabaseSessionHandler implements SessionHandlerInterface {
    private $conn;

    public function __construct() {
        $this->conn = get_db();
    }

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        $stmt = $this->conn->prepare("SELECT data FROM sessions WHERE id = ? AND expires_at > ?");
        $stmt->execute([$id, time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $row['data'];
        }

        return '';
    }

    public function write(string $id, string $data): bool {
        $expires_at = time() + (int) ini_get('session.gc_maxlifetime');
        
        $stmt = $this->conn->prepare("INSERT OR REPLACE INTO sessions (id, data, expires_at) VALUES (?, ?, ?)");
        return $stmt->execute([$id, $data, $expires_at]);
    }

    public function destroy(string $id): bool {
        $stmt = $this->conn->prepare("DELETE FROM sessions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false {
        $stmt = $this->conn->prepare("DELETE FROM sessions WHERE expires_at <= ?");
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }
}
