<?php
/**
 * BunnySecureDB
 * SecureDB-style PHP wrapper for Bunny.net Database via HTTP SQL API (/v2/pipeline).
 *
 * Endpoint format:
 *   libsql://<DB-ID>.lite.bunnydb.net/
 *
 * Usage:
 *   $db = BunnySecureDB::getInstance([
 *     'endpoint' => 'libsql://xxx.lite.bunnydb.net/' 
 *     'token' => 'YOUR_RW_OR_RO_TOKEN',
 *     'timeout' => 20
 *   ]);
 */

declare(strict_types=1);

class BunnySecureDBException extends Exception {}

class BunnySecureDB
{
    private static ?self $instance = null;

    private string $endpoint;
    private string $token;
    private int $timeout = 15;

    // Fluent state
    private string $fluentTable = '';
    private array $fluentWhere = [];
    private int $fluentBatchSize = 1000;
    private string $fluentOperation = '';
    private array $fluentColumns = ['*'];
    private string $fluentOrderBy = '';
    private int $fluentLimit = 0;

    private function __construct(array $config)
    {
        $endpoint = (string)($config['endpoint'] ?? $config['url'] ?? $config['connection_url'] ?? '');
        $token    = (string)($config['token'] ?? $config['access_token'] ?? '');

        if ($endpoint === '') {
            throw new BunnySecureDBException("Missing config. Provide 'endpoint' (or 'url' / 'connection_url').");
        }

        $this->endpoint = $this->normalizeEndpoint($endpoint);

        // Optional: support token provided in URL query for seamless libsql-style configs.
        if ($token === '') {
            $token = $this->extractTokenFromUrl($endpoint);
        }

        if ($token === '') {
            throw new BunnySecureDBException("Missing token. Provide 'token' (or 'access_token'), or include token/authToken in URL query.");
        }

        $this->token = $token;

        if (isset($config['timeout'])) {
            $this->timeout = max(1, (int)$config['timeout']);
        }
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new BunnySecureDBException("Empty endpoint/url provided.");
        }

        // Accept libsql://<host>/ and convert to Bunny pipeline endpoint.
        if (preg_match('#^libsql://#i', $endpoint) === 1) {
            $parts = parse_url($endpoint);
            $host = (string)($parts['host'] ?? '');
            if ($host === '') {
                throw new BunnySecureDBException("Invalid libsql URL. Expected: libsql://<DB-ID>.lite.bunnydb.net/");
            }
            return "https://{$host}/v2/pipeline";
        }

        $endpoint = rtrim($endpoint, '/');
        if (!preg_match('#/v2/pipeline$#', $endpoint)) {
            $endpoint .= '/v2/pipeline';
        }
        return $endpoint;
    }

    private function extractTokenFromUrl(string $url): string
    {
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        if (!is_array($params)) {
            return '';
        }

        $token = (string)($params['token'] ?? $params['authToken'] ?? '');
        return trim($token);
    }

    /** Singleton */
    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            if (empty($config)) {
                throw new BunnySecureDBException("Configuration must be provided on first getInstance() call.");
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /* =========================================================
     * Basic Query API
     * ========================================================= */

    /** SELECT-only convenience */
    public function select(string $sql, array $params = []): array
    {
        $res = $this->executePipeline([
            $this->buildExecuteRequest($sql, $params),
            ["type" => "close"],
        ]);

        return $this->extractRowsFromPipeline($res, 0);
    }

    /** Alias */
    public function q(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params);
    }

    /**
     * SecureDB-like query():
     * - SELECT/PRAGMA => array rows
     * - INSERT        => last_insert_rowid (int|null)
     * - UPDATE/DELETE => affected_row_count (int)
     * - Other         => true
     */
    public function query(string $sql, array $params = []): mixed
    {
        $type = $this->detectQueryType($sql);

        $res = $this->executePipeline([
            $this->buildExecuteRequest($sql, $params),
            ["type" => "close"],
        ]);

        $result = $this->extractResultFromPipeline($res, 0);

        return match ($type) {
            'SELECT', 'PRAGMA' => $this->decodeRows($result),
            'INSERT' => isset($result['last_insert_rowid']) ? (int)$result['last_insert_rowid'] : null,
            'UPDATE', 'DELETE' => (int)($result['affected_row_count'] ?? 0),
            default => true,
        };
    }

    /* =========================================================
     * CRUD Helpers (SecureDB vibe)
     * ========================================================= */

    public function from(string $table, array $columns = ['*']): self
    {
        $this->reset();
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'select';
        $this->fluentColumns = $columns;
        return $this;
    }

    public function where(array $conditions): self|int
    {
        $this->fluentWhere = $conditions;

        // Convenience: delete('table')->where([...]) runs immediately
        if ($this->fluentOperation === 'delete' && $this->fluentTable) {
            return $this->executeDelete();
        }

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        if ($this->fluentOperation !== 'select') {
            throw new BunnySecureDBException("orderBy() can only be used with SELECT operations.");
        }

        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new BunnySecureDBException("Invalid order direction. Use ASC or DESC.");
        }

        $column = $this->validateColumnName($column);
        $this->fluentOrderBy = $this->quoteIdent($column) . " $direction";
        return $this;
    }

    public function limit(int $count): self
    {
        if ($this->fluentOperation !== 'select') {
            throw new BunnySecureDBException("limit() can only be used with SELECT operations.");
        }
        $this->fluentLimit = max(0, $count);
        return $this;
    }

    public function get(): array
    {
        if ($this->fluentOperation !== 'select' || $this->fluentTable === '') {
            throw new BunnySecureDBException("Use from('table')->get()");
        }

        $cols = $this->fluentColumns;
        $colSql = '*';
        if (!empty($cols) && !($cols === ['*'])) {
            $colSql = implode(', ', array_map(function ($c) {
                if ($c === '*') return '*';
                return $this->quoteIdent($this->validateColumnName((string)$c));
            }, $cols));
        }

        $sql = "SELECT $colSql FROM {$this->quoteIdent($this->fluentTable)}";
        $args = [];

        if (!empty($this->fluentWhere)) {
            $whereCols = array_keys($this->fluentWhere);
            $whereSql = implode(' AND ', array_map(function ($c) {
                $c = (string)$c;
                return $this->quoteIdent($this->validateColumnName($c)) . " = ?";
            }, $whereCols));

            $sql .= " WHERE $whereSql";
            $args = array_values($this->fluentWhere);
        }

        if ($this->fluentOrderBy !== '') {
            $sql .= " ORDER BY {$this->fluentOrderBy}";
        }
        if ($this->fluentLimit > 0) {
            $sql .= " LIMIT {$this->fluentLimit}";
        }

        $res = $this->executePipeline([
            $this->buildExecuteRequest($sql, $args),
            ["type" => "close"],
        ]);

        $rows = $this->extractRowsFromPipeline($res, 0);
        $this->reset();
        return $rows;
    }

    /** Quick insert: insert('table', [data]) => last_insert_id */
    public function insert(string $table, array $data = []): int|self
    {
        if (!empty($data)) {
            $table = $this->validateTableName($table);
            $cols = array_keys($data);

            $colList = implode(', ', array_map([$this, 'quoteIdent'], $cols));
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));

            $sql = "INSERT INTO {$this->quoteIdent($table)} ($colList) VALUES ($placeholders)";

            $res = $this->executePipeline([
                $this->buildExecuteRequest($sql, array_values($data)),
                ["type" => "close"],
            ]);

            $result = $this->extractResultFromPipeline($res, 0);
            return isset($result['last_insert_rowid']) ? (int)$result['last_insert_rowid'] : 0;
        }

        $this->reset();
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'insert';
        return $this;
    }

    /** Fluent insert('table')->row([data]) */
    public function row(array $data): int
    {
        if ($this->fluentOperation !== 'insert' || $this->fluentTable === '') {
            throw new BunnySecureDBException("Use insert('table')->row([...])");
        }
        if (empty($data)) {
            throw new BunnySecureDBException("No data provided for insert row().");
        }

        $table = $this->fluentTable;
        $cols = array_keys($data);

        $colList = implode(', ', array_map([$this, 'quoteIdent'], $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));

        $sql = "INSERT INTO {$this->quoteIdent($table)} ($colList) VALUES ($placeholders)";

        $res = $this->executePipeline([
            $this->buildExecuteRequest($sql, array_values($data)),
            ["type" => "close"],
        ]);

        $result = $this->extractResultFromPipeline($res, 0);
        $this->reset();
        return isset($result['last_insert_rowid']) ? (int)$result['last_insert_rowid'] : 0;
    }

    /** Quick bulk insert: insertMultiple('table', $rows, $batchSize) => total inserted */
    public function insertMultiple(string $table, array $rows = [], int $batchSize = 1000): int|self
    {
        if (!empty($rows)) {
            return $this->executeInsertMultiple($this->validateTableName($table), $rows, $batchSize);
        }

        $this->reset();
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'insertMultiple';
        $this->fluentBatchSize = max(1, $batchSize);
        return $this;
    }

    public function batch(int $size): self
    {
        $this->fluentBatchSize = max(1, $size);
        return $this;
    }

    /** Fluent insertMultiple('table')->batch(500)->rows([...]) */
    public function rows(array $rows): int
    {
        if ($this->fluentOperation !== 'insertMultiple' || $this->fluentTable === '') {
            throw new BunnySecureDBException("Use insertMultiple('table')->rows([...])");
        }
        $total = $this->executeInsertMultiple($this->fluentTable, $rows, $this->fluentBatchSize);
        $this->reset();
        return $total;
    }

    /** Quick update: update('table', [set], [where]) => affected */
    public function update(string $table, array $data = [], array $where = []): int|self
    {
        if (!empty($data) && !empty($where)) {
            $table = $this->validateTableName($table);

            $setCols = array_keys($data);
            $setSql = implode(', ', array_map(fn($c) => $this->quoteIdent((string)$c) . " = ?", $setCols));

            $whereCols = array_keys($where);
            $whereSql = implode(' AND ', array_map(fn($c) => $this->quoteIdent((string)$c) . " = ?", $whereCols));

            $sql = "UPDATE {$this->quoteIdent($table)} SET $setSql WHERE $whereSql";
            $args = array_merge(array_values($data), array_values($where));

            $res = $this->executePipeline([
                $this->buildExecuteRequest($sql, $args),
                ["type" => "close"],
            ]);

            $result = $this->extractResultFromPipeline($res, 0);
            return (int)($result['affected_row_count'] ?? 0);
        }

        $this->reset();
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'update';
        return $this;
    }

    /** Fluent update('table')->where([...])->change([set]) */
    public function change(array $data): int
    {
        if ($this->fluentOperation !== 'update' || $this->fluentTable === '') {
            throw new BunnySecureDBException("Use update('table')->where([...])->change([...])");
        }
        if (empty($this->fluentWhere)) {
            throw new BunnySecureDBException("No WHERE specified. Use where([...]) first.");
        }
        if (empty($data)) {
            throw new BunnySecureDBException("No data provided for update.");
        }

        $setCols = array_keys($data);
        $setSql = implode(', ', array_map(function ($c) {
            $c = $this->validateColumnName((string)$c);
            return $this->quoteIdent($c) . " = ?";
        }, $setCols));

        $whereCols = array_keys($this->fluentWhere);
        $whereSql = implode(' AND ', array_map(function ($c) {
            $c = $this->validateColumnName((string)$c);
            return $this->quoteIdent($c) . " = ?";
        }, $whereCols));

        $sql = "UPDATE {$this->quoteIdent($this->fluentTable)} SET $setSql WHERE $whereSql";
        $args = array_merge(array_values($data), array_values($this->fluentWhere));

        $res = $this->executePipeline([
            $this->buildExecuteRequest($sql, $args),
            ["type" => "close"],
        ]);

        $result = $this->extractResultFromPipeline($res, 0);
        $this->reset();
        return (int)($result['affected_row_count'] ?? 0);
    }

    /** Quick delete: delete('table', [where]) => affected */
    public function delete(string $table, array $where = []): int|self
    {
        if (!empty($where)) {
            $table = $this->validateTableName($table);

            $whereCols = array_keys($where);
            $whereSql = implode(' AND ', array_map(fn($c) => $this->quoteIdent((string)$c) . " = ?", $whereCols));

            $sql = "DELETE FROM {$this->quoteIdent($table)} WHERE $whereSql";

            $res = $this->executePipeline([
                $this->buildExecuteRequest($sql, array_values($where)),
                ["type" => "close"],
            ]);

            $result = $this->extractResultFromPipeline($res, 0);
            return (int)($result['affected_row_count'] ?? 0);
        }

        $this->reset();
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'delete';
        return $this;
    }

    /** Create table helper: createTable('table', [columns], true) => success */
    public function createTable(string $table, array $columns, bool $ifNotExists = true): bool
    {
        if (empty($columns)) {
            throw new BunnySecureDBException("No columns provided for table creation.");
        }

        $table = $this->validateTableName($table);
        $ifNotExistsSql = $ifNotExists ? ' IF NOT EXISTS' : '';

        $columnDefinitions = [];
        foreach ($columns as $name => $definition) {
            $name = (string)$name;
            $definition = trim((string)$definition);

            // Handle table constraints (FOREIGN KEY, CHECK, etc.) - they don't have a column name
            if (empty($definition)) {
                $columnDefinitions[] = $name;
            } else {
                // Regular column: validate name, use definition as-is
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                    $columnDefinitions[] = $this->quoteIdent($name) . ' ' . $definition;
                } else {
                    // If name doesn't match column pattern, treat it as a table constraint
                    $columnDefinitions[] = $name;
                }
            }
        }

        $columnsSql = implode(', ', $columnDefinitions);
        $sql = "CREATE TABLE{$ifNotExistsSql} {$this->quoteIdent($table)} ($columnsSql)";

        $this->executePipeline([
            $this->buildExecuteRequest($sql, []),
            ["type" => "close"],
        ]);

        $this->reset();
        return true;
    }

    /** Drop table helper: deleteTable('table', true) => success */
    public function deleteTable(string $table, bool $ifExists = true): bool
    {
        $table = $this->validateTableName($table);
        $ifExistsSql = $ifExists ? ' IF EXISTS' : '';
        $sql = "DROP TABLE{$ifExistsSql} {$this->quoteIdent($table)}";

        $this->executePipeline([
            $this->buildExecuteRequest($sql, []),
            ["type" => "close"],
        ]);

        $this->reset();
        return true;
    }

    /** Alias: dropTable('table', true) => success */
    public function dropTable(string $table, bool $ifExists = true): bool
    {
        return $this->deleteTable($table, $ifExists);
    }

    /* =========================================================
     * Internals: Bulk insert
     * ========================================================= */

    private function executeInsertMultiple(string $table, array $rows, int $batchSize): int
    {
        if (empty($rows)) {
            throw new BunnySecureDBException("No rows provided for bulk insert.");
        }

        // Ensure consistent keys across rows
        $columns = array_keys($rows[0]);
        foreach ($rows as $r) {
            if (array_keys($r) !== $columns) {
                throw new BunnySecureDBException("All rows must have the same keys in the same order.");
            }
        }

        $tableQ = $this->quoteIdent($table);
        $colList = implode(', ', array_map([$this, 'quoteIdent'], $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insertSql = "INSERT INTO $tableQ ($colList) VALUES ($placeholders)";

        $total = 0;
        $batchSize = max(1, $batchSize);

        foreach (array_chunk($rows, $batchSize) as $batch) {
            $requests = [];

            // Transaction per batch
            $requests[] = ["type" => "execute", "stmt" => ["sql" => "BEGIN"]];
            foreach ($batch as $row) {
                $requests[] = $this->buildExecuteRequest($insertSql, array_values($row));
            }
            $requests[] = ["type" => "execute", "stmt" => ["sql" => "COMMIT"]];
            $requests[] = ["type" => "close"];

            $res = $this->executePipeline($requests);

            // BEGIN is result[0], inserts are result[1..n], COMMIT is result[n+1]
            for ($i = 1; $i <= count($batch); $i++) {
                $result = $this->extractResultFromPipeline($res, $i);
                $total += (int)($result['affected_row_count'] ?? 0);
            }
        }

        return $total;
    }

    private function executeDelete(): int
    {
        if ($this->fluentTable === '') {
            throw new BunnySecureDBException("No table specified. Use delete('table') first.");
        }
        if (empty($this->fluentWhere)) {
            throw new BunnySecureDBException("No WHERE specified. Use where([...]) first.");
        }

        $whereCols = array_keys($this->fluentWhere);
        $whereSql = implode(' AND ', array_map(function ($c) {
            $c = $this->validateColumnName((string)$c);
            return $this->quoteIdent($c) . " = ?";
        }, $whereCols));

        $sql = "DELETE FROM {$this->quoteIdent($this->fluentTable)} WHERE $whereSql";
        $args = array_values($this->fluentWhere);

        $res = $this->executePipeline([
            $this->buildExecuteRequest($sql, $args),
            ["type" => "close"],
        ]);

        $result = $this->extractResultFromPipeline($res, 0);
        $count = (int)($result['affected_row_count'] ?? 0);
        $this->reset();
        return $count;
    }

    /* =========================================================
     * Internals: Pipeline + Parsing
     * ========================================================= */

    private function executePipeline(array $requests): array
    {
        $payload = json_encode(["requests" => $requests], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->token}",
                "Content-Type: application/json",
            ],
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new BunnySecureDBException("cURL error: $err");
        }

        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new BunnySecureDBException("Invalid JSON response (HTTP $code): $raw");
        }

        // Pipeline errors can come back in results
        if (isset($json['results']) && is_array($json['results'])) {
            foreach ($json['results'] as $idx => $r) {
                if (($r['type'] ?? '') === 'error') {
                    $msg = $r['error']['message'] ?? 'Unknown pipeline error';
                    throw new BunnySecureDBException("Pipeline error at results[$idx]: $msg");
                }
            }
        }

        if ($code >= 400) {
            throw new BunnySecureDBException("HTTP $code error: $raw");
        }

        return $json;
    }

    private function buildExecuteRequest(string $sql, array $params): array
    {
        // Supports positional args OR named_args (assoc array)
        if ($this->isAssoc($params)) {
            $named = [];
            foreach ($params as $name => $value) {
                $named[] = [
                    "name" => (string)$name,
                    "value" => $this->encodeValue($value),
                ];
            }

            return [
                "type" => "execute",
                "stmt" => [
                    "sql" => $sql,
                    "named_args" => $named,
                ],
            ];
        }

        $args = [];
        foreach ($params as $v) {
            $args[] = $this->encodeValue($v);
        }

        return [
            "type" => "execute",
            "stmt" => [
                "sql" => $sql,
                "args" => $args,
            ],
        ];
    }

    private function extractResultFromPipeline(array $pipelineResponse, int $index): array
    {
        $results = $pipelineResponse['results'] ?? null;
        if (!is_array($results) || !isset($results[$index])) {
            throw new BunnySecureDBException("Missing pipeline result at index $index.");
        }

        $r = $results[$index];
        if (($r['type'] ?? '') !== 'ok') {
            $msg = $r['error']['message'] ?? 'Unknown result error';
            throw new BunnySecureDBException("Non-ok result at index $index: $msg");
        }

        $resp = $r['response'] ?? [];
        if (($resp['type'] ?? '') !== 'execute') {
            return $resp;
        }

        return $resp['result'] ?? [];
    }

    private function extractRowsFromPipeline(array $pipelineResponse, int $index): array
    {
        $result = $this->extractResultFromPipeline($pipelineResponse, $index);
        return $this->decodeRows($result);
    }

    private function decodeRows(array $result): array
    {
        $cols = $result['cols'] ?? [];
        $rows = $result['rows'] ?? [];

        if (!is_array($cols) || !is_array($rows)) {
            return [];
        }

        $colNames = array_map(fn($c) => $c['name'] ?? null, $cols);

        $out = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach ($row as $i => $cell) {
                $name = $colNames[$i] ?? "col_$i";
                $assoc[$name] = $this->decodeValue(is_array($cell) ? $cell : ["type" => "null"]);
            }
            $out[] = $assoc;
        }

        return $out;
    }

    private function encodeValue(mixed $v): array
    {
        // Types: null/integer/float/text/blob (blob expects base64 string)
        if ($v === null) return ["type" => "null"];

        if (is_int($v)) return ["type" => "integer", "value" => (string)$v];
        if (is_float($v)) return ["type" => "float", "value" => (string)$v];
        if (is_bool($v)) return ["type" => "integer", "value" => ($v ? "1" : "0")];

        if (is_string($v)) return ["type" => "text", "value" => $v];

        if (is_array($v) || is_object($v)) {
            return ["type" => "text", "value" => json_encode($v, JSON_UNESCAPED_SLASHES)];
        }

        return ["type" => "text", "value" => (string)$v];
    }

    private function decodeValue(array $cell): mixed
    {
        $type = $cell['type'] ?? 'null';
        $value = $cell['value'] ?? null;

        return match ($type) {
            'null' => null,
            'integer' => is_string($value) ? (int)$value : 0,
            'float' => is_string($value) ? (float)$value : 0.0,
            'text' => $value,
            'blob' => is_string($value) ? base64_decode($value) : null,
            default => $value,
        };
    }

    private function detectQueryType(string $sql): string
    {
        $sql = trim($sql);
        $sql = preg_replace('/^\/\*.*?\*\/\s*/s', '', $sql);
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $sql = trim($sql);

        $first = strtoupper(strtok($sql, " \t\r\n") ?: '');
        return $first ?: 'OTHER';
    }

    private function reset(): void
    {
        $this->fluentTable = '';
        $this->fluentWhere = [];
        $this->fluentBatchSize = 1000;
        $this->fluentOperation = '';
        $this->fluentColumns = ['*'];
        $this->fluentOrderBy = '';
        $this->fluentLimit = 0;
    }

    private function isAssoc(array $arr): bool
    {
        $i = 0;
        foreach (array_keys($arr) as $k) {
            if ($k !== $i++) return true;
        }
        return false;
    }

    private function validateTableName(string $table): string
    {
        $table = trim($table, "\"` ");
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new BunnySecureDBException("Invalid table name: '$table'");
        }
        return $table;
    }

    private function validateColumnName(string $col): string
    {
        $col = trim($col, "\"` ");
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
            throw new BunnySecureDBException("Invalid column name: '$col'");
        }
        return $col;
    }

    private function quoteIdent(string $ident): string
    {
        $ident = str_replace('"', '""', $ident);
        return "\"$ident\"";
    }
}

?>
