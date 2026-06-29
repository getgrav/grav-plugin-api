<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Audit;

use Grav\Common\Grav;
use PDO;

/**
 * SQLite-backed store for the admin audit trail.
 *
 * A single indexed table at user://data/api/audit/audit.sqlite holds one row
 * per audited action. The store is intentionally self-contained; it owns its
 * schema (lazily created on first write) so enabling the feature needs no
 * migration step. Filtering, pagination and retention pruning all run as SQL,
 * which keeps the log viewer responsive at volume where a flat-file scan would
 * not.
 *
 * Writes are best-effort: the AuditSubscriber wraps every call in a try/catch
 * so a storage hiccup can never break the request being audited.
 */
class AuditStore
{
    /** Columns that are real table columns (everything else in a row is dropped). */
    private const COLUMNS = [
        'ts', 'event', 'severity', 'actor_id', 'actor_name', 'actor_roles',
        'auth_method', 'ip', 'user_agent', 'target_type', 'target_id',
        'status', 'context',
    ];

    private ?PDO $pdo = null;
    private string $dbPath;

    public function __construct(?string $dbPath = null)
    {
        if ($dbPath !== null) {
            $this->dbPath = $dbPath;
            return;
        }

        $grav = Grav::instance();
        // (true, true) → return absolute path and create the folder if missing,
        // matching WebhookManager's user://data/api convention.
        $dir = $grav['locator']->findResource('user://data/api', true, true) . '/audit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $this->dbPath = $dir . '/audit.sqlite';
    }

    /**
     * Whether SQLite is usable in this environment. The viewer degrades
     * gracefully (reports "unavailable") rather than throwing when the PDO
     * SQLite driver is missing from a hardened PHP build.
     */
    public static function available(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    /**
     * Append one audited action. Unknown keys are ignored; missing columns are
     * stored as null. `context` is JSON-encoded if handed an array.
     *
     * @param array<string,mixed> $row
     * @return int The new row id (used to schedule opportunistic pruning).
     */
    public function append(array $row): int
    {
        if (isset($row['context']) && is_array($row['context'])) {
            $row['context'] = $row['context'] === []
                ? null
                : json_encode($row['context'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        if (isset($row['actor_roles']) && is_array($row['actor_roles'])) {
            $row['actor_roles'] = implode(',', $row['actor_roles']);
        }

        $values = [];
        foreach (self::COLUMNS as $col) {
            $values[$col] = $row[$col] ?? null;
        }

        $placeholders = implode(', ', array_map(static fn($c) => ':' . $c, self::COLUMNS));
        $columns = implode(', ', self::COLUMNS);
        $db = $this->db();
        $stmt = $db->prepare("INSERT INTO events ($columns) VALUES ($placeholders)");
        $stmt->execute($values);

        return (int) $db->lastInsertId();
    }

    /**
     * Query events with filters + pagination. Returns the decoded rows for the
     * requested page plus the total matching count (for pagination meta).
     *
     * @param array<string,mixed> $filters event, actor, target_type, severity, from, to, q
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function query(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $countStmt = $this->db()->prepare("SELECT COUNT(*) FROM events $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db()->prepare(
            "SELECT * FROM events $where ORDER BY ts DESC, id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * All matching rows, unpaginated but hard-capped, for CSV/JSON export.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function queryAll(array $filters, int $cap = 100000): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db()->prepare(
            "SELECT * FROM events $where ORDER BY ts DESC, id DESC LIMIT :cap"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':cap', $cap, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Distinct values for the filter drop-downs (event types + actors).
     *
     * @return array{events: array<int,string>, actors: array<int,array{id:?string,name:?string}>}
     */
    public function facets(): array
    {
        $events = $this->db()->query('SELECT DISTINCT event FROM events ORDER BY event')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $actors = $this->db()->query(
            'SELECT actor_id, actor_name FROM events GROUP BY actor_id, actor_name ORDER BY actor_name'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'events' => array_values(array_filter($events)),
            'actors' => array_map(static fn($a) => [
                'id' => $a['actor_id'],
                'name' => $a['actor_name'],
            ], $actors),
        ];
    }

    /**
     * Trim the log by age and by total row count. Returns the number of rows
     * deleted. Both bounds are applied; 0 disables a bound.
     */
    public function prune(int $maxAgeDays, int $maxRows): int
    {
        $deleted = 0;

        if ($maxAgeDays > 0) {
            $cutoff = (self::now() - ($maxAgeDays * 86400)) * 1000; // ms
            $stmt = $this->db()->prepare('DELETE FROM events WHERE ts < :cutoff');
            $stmt->execute([':cutoff' => $cutoff]);
            $deleted += $stmt->rowCount();
        }

        if ($maxRows > 0) {
            // Keep the newest $maxRows rows; delete everything older.
            $stmt = $this->db()->prepare(
                'DELETE FROM events WHERE id NOT IN (
                    SELECT id FROM events ORDER BY ts DESC, id DESC LIMIT :keep
                )'
            );
            $stmt->bindValue(':keep', $maxRows, PDO::PARAM_INT);
            $stmt->execute();
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    /** Total row count (used by the status endpoint). */
    public function count(): int
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM events')->fetchColumn();
    }

    // ---------------------------------------------------------------------

    /**
     * Build the WHERE clause + bound params from the filter set. All filters
     * are parameterized; no value is ever interpolated into SQL.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['event'])) {
            $clauses[] = 'event = :event';
            $params[':event'] = (string) $filters['event'];
        }
        if (!empty($filters['severity'])) {
            $clauses[] = 'severity = :severity';
            $params[':severity'] = (string) $filters['severity'];
        }
        if (!empty($filters['target_type'])) {
            $clauses[] = 'target_type = :target_type';
            $params[':target_type'] = (string) $filters['target_type'];
        }
        if (!empty($filters['actor'])) {
            $clauses[] = '(actor_id = :actor OR actor_name = :actor)';
            $params[':actor'] = (string) $filters['actor'];
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'ts >= :from';
            $params[':from'] = (int) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'ts <= :to';
            $params[':to'] = (int) $filters['to'];
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $clauses[] = '(actor_name LIKE :q OR target_id LIKE :q OR event LIKE :q OR ip LIKE :q)';
            $params[':q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']) . '%';
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }

    /**
     * Decode a raw DB row into the API shape: JSON context expanded, roles
     * split back to a list, numeric columns cast.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['ts'] = (int) $row['ts'];
        $row['status'] = $row['status'] !== null ? (int) $row['status'] : null;
        $row['context'] = $row['context'] ? json_decode((string) $row['context'], true) : null;
        $row['actor_roles'] = $row['actor_roles']
            ? array_values(array_filter(explode(',', (string) $row['actor_roles'])))
            : [];
        return $row;
    }

    private function db(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // WAL + a busy timeout so the SPA's concurrent requests don't trip over
        // SQLite's writer lock under load.
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->createSchema($this->pdo);

        return $this->pdo;
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS events (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                ts          INTEGER NOT NULL,
                event       TEXT    NOT NULL,
                severity    TEXT    NOT NULL DEFAULT "info",
                actor_id    TEXT,
                actor_name  TEXT,
                actor_roles TEXT,
                auth_method TEXT,
                ip          TEXT,
                user_agent  TEXT,
                target_type TEXT,
                target_id   TEXT,
                status      INTEGER,
                context     TEXT
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_ts ON events (ts)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_event ON events (event)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_actor ON events (actor_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_target ON events (target_type)');
    }

    private static function now(): int
    {
        return time();
    }
}
