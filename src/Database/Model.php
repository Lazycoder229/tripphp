<?php

declare(strict_types=1);

namespace Framework\Database;

use Framework\Exception\FrameworkException;

/**
 * Wraps a raw SQL fragment so it can be dropped into SELECT / WHERE / ORDER BY
 * without going through column-name whitelist validation.
 *
 * Use sparingly — anything passed through Raw bypasses the injection guards
 * that protect the rest of the builder, so never build a Raw string out of
 * unsanitized user input.
 *
 * @example
 * $this->trip->selectRaw('COUNT(*) as total')->get();
 */
final class Raw
{
    public function __construct(private readonly string $expression) {}

    public function __toString(): string
    {
        return $this->expression;
    }
}

/**
 * Base Model class for interacting with database tables.
 *
 * Provides a fluent query builder interface and basic CRUD operations
 * on top of the ConnectionInterface. Extend this class to create
 * application-specific models.
 *
 * Usage:
 * ```php
 * class Trip extends Model
 * {
 *     protected string $table    = 'trips';
 *     protected array  $fillable = ['title', 'destination', 'status'];
 *     protected array  $guarded  = ['id', 'created_at'];
 *
 *     // Optional — enforce a real whitelist for pivot columns used in
 *     // belongsToMany(), instead of just format-checking them:
 *     protected array $pivotColumns = [
 *         'trip_tag' => ['trip_id', 'tag_id'],
 *     ];
 * }
 * ```
 *
 * @package Framework\Database
 */
abstract class Model
{
    // -------------------------------------------------------------------------
    // Child-class configuration
    // -------------------------------------------------------------------------

    /** @var string Database table this model maps to. */
    protected string $table = '';

    /**
     * @var array Columns allowed for mass-assignment (INSERT / UPDATE).
     *            When non-empty, acts as a whitelist — only listed columns
     *            are accepted. When empty, $guarded is used as a blacklist.
     */
    protected array $fillable = [];

    /**
     * @var array Columns that are never mass-assigned, regardless of $fillable.
     *            Defaults to ['id'] to prevent primary-key tampering.
     */
    protected array $guarded = ['id'];

    /**
     * @var bool Whether create()/update() should automatically manage
     *           created_at / updated_at columns.
     */
    protected bool $timestamps = true;

    /** @var string Column written on create() when $timestamps is true. */
    protected string $createdAtColumn = 'created_at';

    /** @var string Column written on create()/update() when $timestamps is true. */
    protected string $updatedAtColumn = 'updated_at';

    /**
     * @var bool Whether delete() should set $deletedAtColumn instead of
     *           removing the row, and whether SELECT queries should
     *           exclude soft-deleted rows by default.
     */
    protected bool $softDeletes = false;

    /** @var string Column used to mark a row as soft-deleted. */
    protected string $deletedAtColumn = 'deleted_at';

    /**
     * @var array Optional whitelist of pivot-table columns, keyed by pivot
     *            table name — e.g. ['trip_tag' => ['trip_id', 'tag_id']].
     *            When a pivot table has an entry here, belongsToMany() only
     *            accepts $foreignPivotKey/$relatedPivotKey values that are
     *            actually listed for that table — unlisted pivot tables
     *            fall back to format-only validation (see validateIdentifier()).
     *            Optional because pivot columns live on neither model, so
     *            there's no natural place to declare them; define this when
     *            you want schema-level enforcement instead of just format
     *            checking.
     */
    protected array $pivotColumns = [];

    /**
     * @var bool Whether create() should automatically generate and persist a
     *           UUID for this model. When true, a UUID v7 is injected into
     *           $uuidColumn on every INSERT — you never pass it manually.
     *
     *           Requirements:
     *           - Your DB table must have a column matching $uuidColumn
     *             (default: 'uuid') declared as CHAR(36) NOT NULL UNIQUE.
     *           - 'uuid' (or whatever $uuidColumn is) must be in $guarded so
     *             mass-assignment can never overwrite it from user input.
     *
     * @example
     * class Product extends Model
     * {
     *     protected bool   $uuid       = true;
     *     protected array  $fillable   = ['name', 'price'];
     *     protected array  $guarded    = ['id', 'uuid', 'created_at', 'updated_at'];
     * }
     *
     * $id      = $product->create(['name' => 'Bag', 'price' => 199]);
     * $product = $product->findByUuid('xxxxxxxx-xxxx-7xxx-yxxx-xxxxxxxxxxxx');
     */
    protected bool $uuid = false;

    /**
     * @var string The column name used to store the UUID.
     *             Change this if your schema uses a different column name
     *             (e.g. 'public_id', 'external_id').
     */
    protected string $uuidColumn = 'uuid';

    // -------------------------------------------------------------------------
    // Query-builder state (reset after every terminal call)
    // -------------------------------------------------------------------------

    /** @var array WHERE clause fragments, e.g. ["status = ?", "price > ?"] */
    private array $wheres = [];

    /** @var array Flat list of binding values, aligned with $wheres placeholders. */
    private array $bindings = [];

    /** @var array JOIN clause fragments */
    private array $joins = [];

    /** @var int|null LIMIT value; null means no LIMIT clause. */
    private ?int $limit = null;

    /** @var int|null OFFSET value; null means no OFFSET clause. */
    private ?int $offset = null;

    /** @var string|null Column used in ORDER BY; null means no ordering. */
    private ?string $orderByColumn = null;

    /** @var string Sort direction — always 'ASC' or 'DESC'. */
    private string $orderDir = 'ASC';

    /** @var array Columns for SELECT; defaults to ['*']. May contain Raw fragments. */
    private array $selects = ['*'];

    /**
     * @var string|null Trashed-row behavior for this query: null (default —
     *                  exclude trashed), 'with' (include trashed), or
     *                  'only' (trashed rows only). Ignored unless $softDeletes.
     */
    private ?string $trashedMode = null;

    // -------------------------------------------------------------------------
    // Allowed-value whitelists (guards against injection in non-bound positions)
    // -------------------------------------------------------------------------

    /** @var string[] Operators permitted in WHERE clauses. */
    private const ALLOWED_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'LIKE'];

    /** @var string[] Sort directions permitted in ORDER BY. */
    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    /** @var string[] JOIN types permitted. */
    private const ALLOWED_JOINS = ['INNER', 'LEFT', 'RIGHT', 'CROSS'];

    /**
     * @var string Pattern a single identifier segment (table or column name)
     *             must match — letters, digits, underscore, not starting
     *             with a digit. Used to format-validate identifiers that
     *             fall outside the fillable/guarded whitelist (table names,
     *             join columns, pivot columns) so they can never carry
     *             injection payloads even though they aren't checked
     *             against an actual schema.
     */
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param ConnectionInterface $db Injected by the Container automatically.
     */
    public function __construct(
        protected ConnectionInterface $db
    ) {}

    // =========================================================================
    // Fluent builder — SELECT columns
    // =========================================================================

    /**
     * Specify which columns to include in the SELECT clause.
     *
     * Column names are validated against the model's allowed-column list
     * (fillable ∪ guarded) to prevent column-name injection.
     *
     * @param  string ...$columns One or more column names.
     * @return static
     * @throws FrameworkException If a column name is not in the allowed list.
     *
     * @example
     * $this->trip->select('id', 'title')->get();
     * // → SELECT id, title FROM trips
     */
    public function select(string ...$columns): static
    {
        $allowed = $this->getAllowedColumns();

        foreach ($columns as $column) {
            if (!empty($allowed) && !in_array($column, $allowed, strict: true)) {
                throw new FrameworkException(
                    "Column '{$column}' is not allowed in SELECT."
                );
            }
        }

        $this->selects = $columns;
        return $this;
    }

    /**
     * Add a raw, unvalidated SELECT expression — e.g. aggregates or
     * computed columns that don't map to a single whitelisted column.
     *
     * Only ever pass fixed, developer-authored strings. Never interpolate
     * user input directly into $expression.
     *
     * @param  string $expression Raw SQL fragment, e.g. 'COUNT(*) as total'.
     * @return static
     *
     * @example
     * $this->trip->selectRaw('DATEDIFF(NOW(), created_at) as age_days')->get();
     */
    public function selectRaw(string $expression): static
    {
        if ($this->selects === ['*']) {
            $this->selects = [];
        }

        $this->selects[] = new Raw($expression);
        return $this;
    }

    // =========================================================================
    // Fluent builder — WHERE clauses
    // =========================================================================

    /**
     * Add an AND WHERE clause to the query.
     *
     * Both the column name and the operator are validated against whitelists
     * before being interpolated into the SQL string; the value is always
     * passed as a PDO binding, never interpolated directly.
     *
     * @param  string $column   Column name (must be in fillable ∪ guarded).
     * @param  mixed  $value    Value to bind.
     * @param  string $operator Comparison operator. Allowed: = != < > <= >= LIKE
     * @return static
     * @throws FrameworkException If the column or operator is not allowed.
     *
     * @example
     * $this->trip->where('status', 'active')->get();
     * // → WHERE status = ?
     *
     * $this->trip->where('price', 1000, '>')->get();
     * // → WHERE price > ?
     *
     * $this->trip->where('title', '%Boracay%', 'LIKE')->get();
     * // → WHERE title LIKE ?
     */
    public function where(string $column, mixed $value, string $operator = '='): static
    {
        $this->validateColumn($column, 'WHERE');
        $this->validateOperator($operator);

        $this->wheres[]   = "$column $operator ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Add an OR WHERE clause to the query.
     *
     * @param  string $column   Column name (must be in fillable ∪ guarded).
     * @param  mixed  $value    Value to bind.
     * @param  string $operator Comparison operator. Allowed: = != < > <= >= LIKE
     * @return static
     * @throws FrameworkException If the column or operator is not allowed.
     *
     * @example
     * $this->trip
     *     ->where('status', 'active')
     *     ->orWhere('status', 'pending')
     *     ->get();
     * // → WHERE status = ? OR status = ?
     */
    public function orWhere(string $column, mixed $value, string $operator = '='): static
    {
        $this->validateColumn($column, 'OR WHERE');
        $this->validateOperator($operator);

        // If there are existing clauses, prefix with OR; otherwise treat as first WHERE.
        if (!empty($this->wheres)) {
            $last = array_pop($this->wheres);
            $this->wheres[] = "($last OR $column $operator ?)";
        } else {
            $this->wheres[] = "$column $operator ?";
        }

        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Add a WHERE IN (...) clause to the query.
     *
     * @param  string $column Column name (must be in fillable ∪ guarded).
     * @param  array  $values List of values to match against.
     * @return static
     * @throws FrameworkException If the column is not allowed or $values is empty.
     *
     * @example
     * $this->trip->whereIn('status', ['active', 'pending'])->get();
     * // → WHERE status IN (?, ?)
     */
    public function whereIn(string $column, array $values): static
    {
        $this->validateColumn($column, 'WHERE IN');

        if (empty($values)) {
            throw new FrameworkException("whereIn() requires at least one value.");
        }

        $placeholders   = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = "$column IN ($placeholders)";
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    /**
     * Add a WHERE column IS NULL clause.
     *
     * @param  string $column Column name (must be in fillable ∪ guarded).
     * @return static
     * @throws FrameworkException If the column is not allowed.
     *
     * @example
     * $this->trip->whereNull('deleted_at')->get();
     * // → WHERE deleted_at IS NULL
     */
    public function whereNull(string $column): static
    {
        $this->validateColumn($column, 'WHERE NULL');
        $this->wheres[] = "$column IS NULL";
        return $this;
    }

    /**
     * Add a WHERE column IS NOT NULL clause.
     *
     * @param  string $column Column name (must be in fillable ∪ guarded).
     * @return static
     * @throws FrameworkException If the column is not allowed.
     *
     * @example
     * $this->trip->whereNotNull('published_at')->get();
     * // → WHERE published_at IS NOT NULL
     */
    public function whereNotNull(string $column): static
    {
        $this->validateColumn($column, 'WHERE NOT NULL');
        $this->wheres[] = "$column IS NOT NULL";
        return $this;
    }

    /**
     * Add a WHERE column BETWEEN ? AND ? clause.
     *
     * @param  string $column Column name (must be in fillable ∪ guarded).
     * @param  mixed  $min    Lower bound (inclusive).
     * @param  mixed  $max    Upper bound (inclusive).
     * @return static
     * @throws FrameworkException If the column is not allowed.
     *
     * @example
     * $this->trip->whereBetween('price', 1000, 5000)->get();
     * // → WHERE price BETWEEN ? AND ?
     */
    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->validateColumn($column, 'WHERE BETWEEN');
        $this->wheres[]   = "$column BETWEEN ? AND ?";
        $this->bindings[] = $min;
        $this->bindings[] = $max;
        return $this;
    }

    /**
     * Add a raw, unvalidated WHERE fragment — for conditions that don't fit
     * the whitelist-driven helpers above (subqueries, function calls, etc).
     *
     * Bindings must line up positionally with the '?' placeholders in $sql.
     * Only ever build $sql from fixed, developer-authored strings — never
     * interpolate user input into it directly.
     *
     * @param  string $sql      Raw SQL fragment, e.g. 'YEAR(created_at) = ?'.
     * @param  array  $bindings Values for each '?' placeholder in $sql.
     * @return static
     *
     * @example
     * $this->trip->whereRaw('YEAR(created_at) = ?', [2026])->get();
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[]  = $sql;
        $this->bindings  = array_merge($this->bindings, $bindings);
        return $this;
    }

    // =========================================================================
    // Fluent builder — JOINs
    // =========================================================================

    /**
     * Add an INNER JOIN clause.
     *
     * Returns only rows that have matching values in both tables.
     *
     * @param  string $table      The table to join.
     * @param  string $first      Column on the left  (e.g. 'trips.id').
     * @param  string $second     Column on the right (e.g. 'bookings.trip_id').
     * @return static
     *
     * @example
     * $this->trip
     *     ->join('bookings', 'trips.id', 'bookings.trip_id')
     *     ->get();
     * // → INNER JOIN bookings ON trips.id = bookings.trip_id
     */
    public function join(string $table, string $first, string $second): static
    {
        return $this->addJoin('INNER', $table, $first, $second);
    }

    /**
     * Add a LEFT JOIN clause.
     *
     * Returns all rows from the left table, and matched rows from the right.
     * Unmatched right-side columns are NULL.
     *
     * @param  string $table  The table to join.
     * @param  string $first  Column on the left  (e.g. 'trips.id').
     * @param  string $second Column on the right (e.g. 'bookings.trip_id').
     * @return static
     *
     * @example
     * $this->trip
     *     ->leftJoin('bookings', 'trips.id', 'bookings.trip_id')
     *     ->get();
     * // → LEFT JOIN bookings ON trips.id = bookings.trip_id
     */
    public function leftJoin(string $table, string $first, string $second): static
    {
        return $this->addJoin('LEFT', $table, $first, $second);
    }

    /**
     * Add a RIGHT JOIN clause.
     *
     * Returns all rows from the right table, and matched rows from the left.
     * Unmatched left-side columns are NULL.
     *
     * @param  string $table  The table to join.
     * @param  string $first  Column on the left  (e.g. 'trips.id').
     * @param  string $second Column on the right (e.g. 'bookings.trip_id').
     * @return static
     *
     * @example
     * $this->trip
     *     ->rightJoin('bookings', 'trips.id', 'bookings.trip_id')
     *     ->get();
     * // → RIGHT JOIN bookings ON trips.id = bookings.trip_id
     */
    public function rightJoin(string $table, string $first, string $second): static
    {
        return $this->addJoin('RIGHT', $table, $first, $second);
    }

    /**
     * Add a CROSS JOIN clause.
     *
     * Returns the Cartesian product of both tables — every row in the left
     * table combined with every row in the right. Use with care on large tables.
     *
     * @param  string $table The table to cross-join.
     * @return static
     *
     * @example
     * $this->trip->crossJoin('categories')->get();
     * // → CROSS JOIN categories
     */
    public function crossJoin(string $table): static
    {
        $this->validateIdentifier($table, 'CROSS JOIN');
        $this->joins[] = "CROSS JOIN $table";
        return $this;
    }

    // =========================================================================
    // Fluent builder — ORDER BY / LIMIT / OFFSET shortcuts
    // =========================================================================

    /**
     * Specify the column and direction for ORDER BY.
     *
     * Both the column name and direction are validated against whitelists.
     *
     * @param  string $column    Column name (must be in fillable ∪ guarded).
     * @param  string $direction 'ASC' or 'DESC' (case-insensitive).
     * @return static
     * @throws FrameworkException If the column or direction is not allowed.
     *
     * @example
     * $this->trip->orderBy('created_at', 'DESC')->get();
     * // → ORDER BY created_at DESC
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->validateColumn($column, 'ORDER BY');

        $direction = strtoupper($direction);

        if (!in_array($direction, self::ALLOWED_DIRECTIONS, strict: true)) {
            throw new FrameworkException(
                "Direction '{$direction}' is not allowed. Use ASC or DESC."
            );
        }

        $this->orderByColumn = $column;
        $this->orderDir      = $direction;
        return $this;
    }

    /**
     * Order by a raw, unvalidated expression — e.g. FIELD(), RAND(), or a
     * computed value that doesn't map to a single whitelisted column.
     *
     * Only ever pass fixed, developer-authored strings.
     *
     * @param  string $expression Raw SQL fragment for the ORDER BY clause.
     * @return static
     *
     * @example
     * $this->trip->orderByRaw('RAND()')->get();
     */
    public function orderByRaw(string $expression): static
    {
        $this->orderByColumn = (string) new Raw($expression);
        $this->orderDir      = '';
        return $this;
    }

    /**
     * Shortcut for orderBy('created_at', 'DESC').
     *
     * @return static
     *
     * @example
     * $this->trip->latest()->get();
     * // → ORDER BY created_at DESC
     */
    public function latest(): static
    {
        return $this->orderBy($this->createdAtColumn, 'DESC');
    }

    /**
     * Shortcut for orderBy('created_at', 'ASC').
     *
     * @return static
     *
     * @example
     * $this->trip->oldest()->get();
     * // → ORDER BY created_at ASC
     */
    public function oldest(): static
    {
        return $this->orderBy($this->createdAtColumn, 'ASC');
    }

    /**
     * Set the maximum number of rows to return.
     *
     * @param  int $n Must be a positive integer.
     * @return static
     *
     * @example
     * $this->trip->limit(10)->get();
     * // → LIMIT 10
     */
    public function limit(int $n): static
    {
        $this->limit = $n;
        return $this;
    }

    /**
     * Set the number of rows to skip before returning results.
     * Typically paired with limit() for pagination.
     *
     * @param  int $n Must be a non-negative integer.
     * @return static
     *
     * @example
     * $this->trip->limit(10)->offset(20)->get();
     * // → LIMIT 10 OFFSET 20  (page 3)
     */
    public function offset(int $n): static
    {
        $this->offset = $n;
        return $this;
    }

    // =========================================================================
    // Fluent builder — soft delete scopes
    // =========================================================================

    /**
     * Include soft-deleted rows in the next query, alongside normal ones.
     * No-op if the model doesn't use soft deletes.
     *
     * @return static
     *
     * @example
     * $this->trip->withTrashed()->get();
     */
    public function withTrashed(): static
    {
        $this->trashedMode = 'with';
        return $this;
    }

    /**
     * Restrict the next query to only soft-deleted rows.
     * No-op if the model doesn't use soft deletes.
     *
     * @return static
     *
     * @example
     * $this->trip->onlyTrashed()->get();
     */
    public function onlyTrashed(): static
    {
        $this->trashedMode = 'only';
        return $this;
    }

    // =========================================================================
    // Terminal methods — execute the built query
    // =========================================================================

    /**
     * Execute the built SELECT query and return all matching rows.
     *
     * Resets the builder state after execution so the model instance
     * can be reused immediately for a fresh query.
     *
     * @return array List of rows as associative arrays.
     *
     * @example
     * $trips = $this->trip
     *     ->where('status', 'active')
     *     ->orderBy('created_at', 'DESC')
     *     ->limit(10)
     *     ->get();
     */
    public function get(): array
    {
        $sql    = $this->buildSelect();
        $result = $this->db->query($sql, $this->bindings);
        $this->reset();
        return $result;
    }

    /**
     * Execute the built SELECT query and return only the first matching row.
     *
     * Automatically applies LIMIT 1. Returns null if no row matches.
     * Resets the builder state after execution.
     *
     * @return array|null First matching row, or null.
     *
     * @example
     * $trip = $this->trip
     *     ->where('title', 'Boracay Trip')
     *     ->first();
     */
    public function first(): ?array
    {
        $this->limit(1);
        $sql    = $this->buildSelect();
        $result = $this->db->queryOne($sql, $this->bindings);
        $this->reset();
        return $result;
    }

    /**
     * Execute a COUNT(*) query based on the current builder state.
     *
     * Resets the builder state after execution.
     *
     * @return int Number of matching rows.
     *
     * @example
     * $count = $this->trip->where('status', 'active')->count();
     * // → SELECT COUNT(*) FROM trips WHERE status = ?
     */
    public function count(): int
    {
        $sql = "SELECT COUNT(*) as aggregate FROM {$this->table}";

        if ($this->joins) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $wheres = $this->wheresWithTrashedScope();

        if ($wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }

        $result = $this->db->queryOne($sql, $this->bindings);
        $this->reset();
        return (int) ($result['aggregate'] ?? 0);
    }

    /**
     * Determine whether any row matches the current builder state.
     *
     * Resets the builder state after execution.
     *
     * @return bool True if at least one matching row exists.
     *
     * @example
     * $exists = $this->trip->where('title', 'Boracay')->exists();
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Process all matching rows in chunks to avoid loading large result
     * sets into memory all at once.
     *
     * @param  int      $size     Number of rows per chunk.
     * @param  callable $callback Receives an array of rows; return false to stop early.
     * @return void
     *
     * @example
     * $this->trip->chunk(100, function (array $rows) {
     *     foreach ($rows as $row) { // process }
     * });
     */
    public function chunk(int $size, callable $callback): void
    {
        $page = 0;

        do {
            $rows = $this->limit($size)->offset($page * $size)->get();

            if (empty($rows)) {
                break;
            }

            if ($callback($rows) === false) {
                break;
            }

            $page++;
        } while (count($rows) === $size);
    }

    /**
     * Paginate the results and return both the data and pagination metadata.
     *
     * @param  int $page    Current page number (1-based).
     * @param  int $perPage Number of rows per page.
     * @return array{data: array, total: int, per_page: int, current_page: int, last_page: int}
     *
     * @example
     * $result = $this->trip->where('status', 'active')->paginate(1, 10);
     * // [
     * //   'data'         => [...],
     * //   'total'        => 100,
     * //   'per_page'     => 10,
     * //   'current_page' => 1,
     * //   'last_page'    => 10,
     * // ]
     */
    public function paginate(int $page = 1, int $perPage = 10): array
    {
        // Preserve wheres/joins/trashed-mode for the count query, then run it
        $savedWheres      = $this->wheres;
        $savedBindings    = $this->bindings;
        $savedJoins       = $this->joins;
        $savedTrashedMode = $this->trashedMode;

        $total = $this->count();

        // Restore state for the data query
        $this->wheres      = $savedWheres;
        $this->bindings    = $savedBindings;
        $this->joins       = $savedJoins;
        $this->trashedMode = $savedTrashedMode;

        $data = $this->limit($perPage)
                     ->offset(($page - 1) * $perPage)
                     ->get();

        return [
            'data'         => $data,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / max($perPage, 1)),
        ];
    }

    // =========================================================================
    // CRUD — direct operations (no builder state required)
    // =========================================================================

    /**
     * Return every row in the table (soft-deleted rows excluded by default).
     *
     * @return array
     *
     * @example
     * $trips = $this->trip->all();
     * // → SELECT * FROM trips
     */
    public function all(): array
    {
        return $this->get();
    }

    /**
     * Find a single row by its primary key.
     * Excludes soft-deleted rows unless withTrashed()/onlyTrashed() was called first.
     *
     * @param  int $id Primary key value.
     * @return array|null The row, or null if not found.
     *
     * @example
     * $trip = $this->trip->find(1);
     * // → SELECT * FROM trips WHERE id = ?
     */
    public function find(int $id): ?array
    {
        return $this->where('id', $id)->first();
    }

    /**
     * Find a single row by its UUID column.
     *
     * Only works when $uuid is true on the model. Returns null if no row
     * matches, or if the supplied string is not a well-formed UUID v7.
     * Excludes soft-deleted rows unless withTrashed()/onlyTrashed() was
     * called first.
     *
     * @param  string $uuid The UUID value to look up.
     * @return array|null   The row, or null if not found / invalid UUID.
     * @throws FrameworkException If $uuid is not enabled on this model.
     *
     * @example
     * $product = $this->product->findByUuid('550e8400-e29b-71d4-a716-446655440000');
     * // → SELECT * FROM products WHERE uuid = ? LIMIT 1
     */
    public function findByUuid(string $uuid): ?array
    {
        if (!$this->uuid) {
            throw new FrameworkException(
                'findByUuid() requires $uuid = true on ' . static::class . '.'
            );
        }

        if (!$this->isValidUuid($uuid)) {
            return null;
        }

        return $this->where($this->uuidColumn, $uuid)->first();
    }

    /**
     * Insert a new row and return its generated primary key.
     *
     * The $data array is filtered through fillable/guarded before insertion —
     * unknown or guarded columns are silently stripped. When $timestamps is
     * enabled, created_at and updated_at are stamped automatically.
     *
     * @param  array $data Associative array of column => value pairs.
     * @return string The auto-increment ID of the inserted row.
     *
     * @example
     * $id = $this->trip->create([
     *     'title'       => 'Boracay Trip',
     *     'destination' => 'PH',
     *     'status'      => 'active',
     * ]);
     * // → INSERT INTO trips (title, destination, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
     */
    public function create(array $data): string
    {
        $data = $this->filterFillable($data);

        // Auto-inject UUID before insertion when $uuid is enabled.
        // The column is bypassed from filterFillable() because it lives in
        // $guarded, so we add it directly here after filtering.
        if ($this->uuid) {
            $data[$this->uuidColumn] = $this->generateUuid();
        }

        if ($this->timestamps) {
            $now                             = $this->now();
            $data[$this->createdAtColumn]    = $now;
            $data[$this->updatedAtColumn]    = $now;
        }

        $columns      = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $this->db->execute(
            "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})",
            array_values($data)
        );

        return $this->db->lastInsertId();
    }

    /**
     * Update an existing row by primary key.
     *
     * The $data array is filtered through fillable/guarded before the update.
     * When $timestamps is enabled, updated_at is refreshed automatically.
     *
     * @param  int   $id   Primary key of the row to update.
     * @param  array $data Associative array of column => value pairs.
     * @return int Number of affected rows (0 or 1).
     *
     * @example
     * $affected = $this->trip->update(1, ['status' => 'inactive']);
     * // → UPDATE trips SET status = ?, updated_at = ? WHERE id = ?
     */
    public function update(int $id, array $data): int
    {
        $data = $this->filterFillable($data);

        if ($this->timestamps) {
            $data[$this->updatedAtColumn] = $this->now();
        }

        $sets = implode(', ', array_map(fn($col) => "$col = ?", array_keys($data)));

        return $this->db->execute(
            "UPDATE {$this->table} SET {$sets} WHERE id = ?",
            [...array_values($data), $id]
        );
    }

    /**
     * Delete a row by primary key.
     *
     * If $softDeletes is enabled, this sets $deletedAtColumn to the current
     * timestamp instead of removing the row — use forceDelete() to remove it
     * permanently. If $softDeletes is disabled, this always hard-deletes.
     *
     * @param  int $id Primary key of the row to delete.
     * @return int Number of affected rows (0 or 1).
     *
     * @example
     * $affected = $this->trip->delete(1);
     * // Soft-delete model → UPDATE trips SET deleted_at = ? WHERE id = ?
     * // Regular model     → DELETE FROM trips WHERE id = ?
     */
    public function delete(int $id): int
    {
        if ($this->softDeletes) {
            return $this->db->execute(
                "UPDATE {$this->table} SET {$this->deletedAtColumn} = ? WHERE id = ?",
                [$this->now(), $id]
            );
        }

        return $this->forceDelete($id);
    }

    /**
     * Permanently remove a row, bypassing soft deletes entirely.
     *
     * @param  int $id Primary key of the row to delete.
     * @return int Number of affected rows (0 or 1).
     *
     * @example
     * $affected = $this->trip->forceDelete(1);
     * // → DELETE FROM trips WHERE id = ?
     */
    public function forceDelete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE id = ?",
            [$id]
        );
    }

    /**
     * Restore a soft-deleted row by clearing $deletedAtColumn.
     * No-op (returns 0) if the model doesn't use soft deletes.
     *
     * @param  int $id Primary key of the row to restore.
     * @return int Number of affected rows (0 or 1).
     *
     * @example
     * $affected = $this->trip->restore(1);
     * // → UPDATE trips SET deleted_at = NULL WHERE id = ?
     */
    public function restore(int $id): int
    {
        if (!$this->softDeletes) {
            return 0;
        }

        return $this->db->execute(
            "UPDATE {$this->table} SET {$this->deletedAtColumn} = NULL WHERE id = ?",
            [$id]
        );
    }

    // =========================================================================
    // Relationships
    //
    // These are lightweight, stateless helpers — since Model is a query
    // builder rather than an ActiveRecord instance holding loaded attributes,
    // the "local" side of the relationship is passed in explicitly rather
    // than read off $this. Typical usage is from within a loaded row's
    // controller/service code:
    //
    //     $trip = $this->trip->find(1);
    //     $bookings = $this->trip->hasMany(Booking::class, 'trip_id', $trip['id']);
    // =========================================================================

    /**
     * One-to-many: fetch related rows where $foreignKey on the related table
     * equals $localValue.
     *
     * @param  class-string<Model> $related    Related model class.
     * @param  string              $foreignKey Column on the related table.
     * @param  mixed               $localValue Value to match (usually this model's id).
     * @return array Matching related rows.
     *
     * @example
     * $bookings = $this->trip->hasMany(Booking::class, 'trip_id', $trip['id']);
     */
    public function hasMany(string $related, string $foreignKey, mixed $localValue): array
    {
        return $this->newRelated($related)
            ->where($foreignKey, $localValue)
            ->get();
    }

    /**
     * One-to-one: fetch a single related row where $foreignKey on the
     * related table equals $localValue.
     *
     * @param  class-string<Model> $related    Related model class.
     * @param  string              $foreignKey Column on the related table.
     * @param  mixed               $localValue Value to match (usually this model's id).
     * @return array|null The related row, or null.
     *
     * @example
     * $profile = $this->user->hasOne(Profile::class, 'user_id', $user['id']);
     */
    public function hasOne(string $related, string $foreignKey, mixed $localValue): ?array
    {
        return $this->newRelated($related)
            ->where($foreignKey, $localValue)
            ->first();
    }

    /**
     * Inverse one-to-many: fetch the single owning row on the related table
     * whose $ownerKey equals $foreignValue.
     *
     * @param  class-string<Model> $related      Related model class.
     * @param  mixed               $foreignValue Value held on this row (e.g. $booking['trip_id']).
     * @param  string              $ownerKey     Primary key column on the related table. Defaults to 'id'.
     * @return array|null The owning row, or null.
     *
     * @example
     * $trip = $this->booking->belongsTo(Trip::class, $booking['trip_id']);
     */
    public function belongsTo(string $related, mixed $foreignValue, string $ownerKey = 'id'): ?array
    {
        return $this->newRelated($related)
            ->where($ownerKey, $foreignValue)
            ->first();
    }

    /**
     * Many-to-many: fetch related rows joined through a pivot table.
     *
     * Note: pivot columns ($pivotTable, $foreignPivotKey, $relatedPivotKey)
     * live on the pivot table rather than on either model, so they can't go
     * through the fillable/guarded column whitelist. If $pivotTable has an
     * entry in $pivotColumns, $foreignPivotKey/$relatedPivotKey are checked
     * against that declared list (a real schema whitelist). Otherwise they
     * fall back to format-only validation (letters/digits/underscore — see
     * validateIdentifier()), which blocks injection but doesn't confirm the
     * column actually exists. Only pass fixed, developer-authored column
     * names — never user input.
     *
     * @param  class-string<Model> $related         Related model class.
     * @param  string              $pivotTable      Name of the pivot table.
     * @param  string              $foreignPivotKey Column on the pivot table referencing this model.
     * @param  string              $relatedPivotKey Column on the pivot table referencing the related model.
     * @param  mixed               $localValue      Value to match (usually this model's id).
     * @return array Matching related rows.
     *
     * @example
     * $tags = $this->trip->belongsToMany(
     *     Tag::class, 'trip_tag', 'trip_id', 'tag_id', $trip['id']
     * );
     */
    public function belongsToMany(
        string $related,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        mixed $localValue
    ): array {
        $this->validateIdentifier($pivotTable, 'belongsToMany');
        $this->validatePivotColumn($pivotTable, $foreignPivotKey, 'belongsToMany');
        $this->validatePivotColumn($pivotTable, $relatedPivotKey, 'belongsToMany');

        $instance     = $this->newRelated($related);
        $relatedTable = $instance->table;

        return $instance
            ->join($pivotTable, "{$relatedTable}.id", "{$pivotTable}.{$relatedPivotKey}")
            ->whereRaw("{$pivotTable}.{$foreignPivotKey} = ?", [$localValue])
            ->get();
    }

    /**
     * Instantiate a related model, sharing this model's connection.
     *
     * @param  class-string<Model> $related
     * @return static
     */
    protected function newRelated(string $related): static
    {
        return new $related($this->db);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Build the full SELECT SQL string from current builder state.
     *
     * @return string
     */
    private function buildSelect(): string
    {
        $cols = implode(', ', array_map(strval(...), $this->selects));
        $sql  = "SELECT {$cols} FROM {$this->table}";

        if ($this->joins) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $wheres = $this->wheresWithTrashedScope();

        if ($wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }

        if ($this->orderByColumn) {
            $sql .= $this->orderDir === ''
                ? " ORDER BY {$this->orderByColumn}"
                : " ORDER BY {$this->orderByColumn} {$this->orderDir}";
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    /**
     * Return $this->wheres with the soft-delete scope applied, based on
     * $softDeletes and the current $trashedMode. Does not mutate $this->wheres.
     *
     * @return array
     */
    private function wheresWithTrashedScope(): array
    {
        $wheres = $this->wheres;

        if (!$this->softDeletes) {
            return $wheres;
        }

        $wheres[] = match ($this->trashedMode) {
            'with'  => '1=1',
            'only'  => "{$this->deletedAtColumn} IS NOT NULL",
            default => "{$this->deletedAtColumn} IS NULL",
        };

        return $wheres;
    }

    /**
     * Current datetime in a MySQL-friendly format, used for timestamps
     * and soft-delete markers.
     *
     * @return string
     */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Register a JOIN clause of the given type.
     *
     * @param  string $type   One of INNER | LEFT | RIGHT.
     * @param  string $table  Table to join.
     * @param  string $first  Left-side column  (e.g. 'trips.id').
     * @param  string $second Right-side column (e.g. 'bookings.trip_id').
     * @return static
     * @throws FrameworkException If the join type is not allowed.
     */
    private function addJoin(string $type, string $table, string $first, string $second): static
    {
        if (!in_array($type, self::ALLOWED_JOINS, strict: true)) {
            throw new FrameworkException("Join type '{$type}' is not allowed.");
        }

        $this->validateIdentifier($table, "{$type} JOIN");
        $this->validateIdentifier($first, "{$type} JOIN");
        $this->validateIdentifier($second, "{$type} JOIN");

        $this->joins[] = "{$type} JOIN {$table} ON {$first} = {$second}";
        return $this;
    }

    /**
     * Return the union of fillable and guarded columns.
     * Used as the whitelist for column-name validation.
     *
     * @return array
     */
    private function getAllowedColumns(): array
    {
        return array_merge($this->fillable, $this->guarded);
    }

    /**
     * Validate that a column name is in the allowed list.
     *
     * @param  string $column  Column name to validate.
     * @param  string $context Clause name used in the error message (e.g. 'WHERE').
     * @throws FrameworkException
     */
    private function validateColumn(string $column, string $context): void
    {
        // Allow table-prefixed columns (e.g. 'bookings.trip_id') used in JOINs
        $bare    = str_contains($column, '.') ? explode('.', $column)[1] : $column;
        $allowed = $this->getAllowedColumns();

        if (!empty($allowed) && !in_array($bare, $allowed, strict: true)) {
            throw new FrameworkException(
                "Column '{$column}' is not allowed in {$context}."
            );
        }
    }

    /**
     * Validate that an operator is in the allowed list.
     *
     * @param  string $operator Operator to validate.
     * @throws FrameworkException
     */
    private function validateOperator(string $operator): void
    {
        if (!in_array($operator, self::ALLOWED_OPERATORS, strict: true)) {
            throw new FrameworkException(
                "Operator '{$operator}' is not allowed."
            );
        }
    }

    /**
     * Format-validate a table or column identifier that falls outside the
     * fillable/guarded whitelist (table names, join columns, pivot columns).
     *
     * This does NOT confirm the identifier actually exists in the schema —
     * it only guarantees it cannot contain quotes, spaces, semicolons,
     * comment markers, or anything else that could break out of the
     * identifier position in the generated SQL. Dotted identifiers
     * (e.g. 'trips.id') are validated segment by segment.
     *
     * @param  string $identifier Identifier or dotted identifier to check.
     * @param  string $context    Clause name used in the error message.
     * @throws FrameworkException If any segment doesn't match IDENTIFIER_PATTERN.
     */
    private function validateIdentifier(string $identifier, string $context): void
    {
        foreach (explode('.', $identifier) as $segment) {
            if (!preg_match(self::IDENTIFIER_PATTERN, $segment)) {
                throw new FrameworkException(
                    "Identifier '{$identifier}' is not a valid identifier in {$context}."
                );
            }
        }
    }

    /**
     * Validate a pivot-table column name for belongsToMany().
     *
     * If $pivotTable has an entry in $pivotColumns, $column must be listed
     * there — this is a real schema whitelist, same idea as fillable/guarded
     * for regular columns. If $pivotTable has no entry, falls back to
     * validateIdentifier()'s format-only check.
     *
     * @param  string $pivotTable Pivot table the column belongs to.
     * @param  string $column     Column name to validate.
     * @param  string $context    Clause name used in the error message.
     * @throws FrameworkException If the column is not on the declared whitelist,
     *                            or (fallback case) isn't a well-formed identifier.
     */
    private function validatePivotColumn(string $pivotTable, string $column, string $context): void
    {
        if (isset($this->pivotColumns[$pivotTable])) {
            if (!in_array($column, $this->pivotColumns[$pivotTable], strict: true)) {
                throw new FrameworkException(
                    "Column '{$column}' is not in the declared pivotColumns for '{$pivotTable}' in {$context}."
                );
            }
            return;
        }

        $this->validateIdentifier($column, $context);
    }

    /**
     * Filter an input array through fillable / guarded rules.
     *
     * - If $fillable is non-empty: whitelist — keep only fillable keys.
     * - If $fillable is empty:     blacklist — remove guarded keys.
     *
     * @param  array $data Raw input array.
     * @return array Filtered array safe for INSERT / UPDATE.
     */
    private function filterFillable(array $data): array
    {
        if (!empty($this->fillable)) {
            return array_intersect_key($data, array_flip($this->fillable));
        }

        return array_diff_key($data, array_flip($this->guarded));
    }

    /**
     * Generate a cryptographically random RFC 9562 UUID v7.
     *
     * Layout (128 bits):
     *   - bits  0–47  : Unix timestamp in milliseconds (big-endian)
     *   - bits 48–51  : version = 0b0111 (7)
     *   - bits 52–63  : 12 random bits
     *   - bits 64–65  : variant = 0b10 (RFC 4122 / 9562)
     *   - bits 66–127 : 62 random bits
     *
     * UUID v7s sort chronologically, making them index-friendly in B-tree
     * databases — inserts land at the end of the index rather than causing
     * page splits across random positions as v4 does.
     *
     * Format: xxxxxxxx-xxxx-7xxx-yxxx-xxxxxxxxxxxx
     *
     * @return string A 36-character UUID string.
     */
    private function generateUuid(): string
    {
        $bytes = random_bytes(16);

        // Overwrite bytes 0–5 with the current Unix timestamp in milliseconds.
        $ms       = (int) (microtime(true) * 1000);
        $bytes[0] = chr(($ms >> 40) & 0xff);
        $bytes[1] = chr(($ms >> 32) & 0xff);
        $bytes[2] = chr(($ms >> 24) & 0xff);
        $bytes[3] = chr(($ms >> 16) & 0xff);
        $bytes[4] = chr(($ms >>  8) & 0xff);
        $bytes[5] = chr( $ms        & 0xff);

        // Set version to 7 (0111xxxx in the high nibble of byte 6).
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);

        // Set variant to RFC 4122 / 9562 (10xxxxxx in the high bits of byte 8).
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Check whether a string looks like a well-formed UUID v7.
     *
     * Used in findByUuid() to avoid a pointless DB round-trip for
     * obviously invalid inputs (e.g. route params that aren't UUIDs).
     *
     * @param  string $uuid
     * @return bool
     */
    private function isValidUuid(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    /**
     * Reset all builder state so the model instance can be reused.
     *
     * Called automatically after every terminal method (get, first, count,
     * exists, chunk, paginate).
     */
    private function reset(): void
    {
        $this->wheres        = [];
        $this->bindings      = [];
        $this->joins         = [];
        $this->limit         = null;
        $this->offset        = null;
        $this->orderByColumn = null;
        $this->orderDir      = 'ASC';
        $this->selects       = ['*'];
        $this->trashedMode   = null;
    }
}