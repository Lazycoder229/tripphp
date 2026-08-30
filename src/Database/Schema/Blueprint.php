<?php

declare(strict_types=1);

namespace Framework\Database\Schema;

/**
 * Blueprint
 * 
 * Fluent table builder for creating and altering database tables.
 * 
 * @package Framework\Database\Schema
 */
final class Blueprint
{
    /** @var Column[] */
    private array $columns = [];

    /** @var string[] */
    private array $dropColumns = [];

    /** @var array<int, array{columns: array, name: ?string, type: string}> */
    private array $indexes = [];

    /** @var array<int, array{from: string, to: string}> */
    private array $renameColumns = [];

    /** @var string[] */
    private array $dropIndexes = [];

    /** @var string[] */
    private array $dropForeignKeys = [];

    public function __construct(private readonly string $table)
    {
    }

    public function getTable(): string
    {
        return $this->table;
    }

    // -------------------------------------------------------------
    // Primary / Auto-Incrementing Columns
    // -------------------------------------------------------------
    public function id(string $column = 'id'): Column
    {
        return $this->addColumn($column, 'id');
    }

    public function increments(string $column = 'id'): Column
    {
        return $this->addColumn($column, 'increments');
    }

    public function bigIncrements(string $column = 'id'): Column
    {
        return $this->addColumn($column, 'bigIncrements');
    }

    public function tinyIncrements(string $column = 'id'): Column
    {
        return $this->addColumn($column, 'tinyInteger')->unsigned()->autoIncrement()->primary();
    }

    public function smallIncrements(string $column = 'id'): Column
    {
        return $this->addColumn($column, 'smallInteger')->unsigned()->autoIncrement()->primary();
    }

    // -------------------------------------------------------------
    // String & Text Columns
    // -------------------------------------------------------------
    public function string(string $column, int $length = 255): Column
    {
        return $this->addColumn($column, 'string', ['length' => $length]);
    }

    public function char(string $column, int $length = 255): Column
    {
        return $this->addColumn($column, 'char', ['length' => $length]);
    }

    public function text(string $column): Column
    {
        return $this->addColumn($column, 'text');
    }

    public function mediumText(string $column): Column
    {
        return $this->addColumn($column, 'mediumText');
    }

    public function longText(string $column): Column
    {
        return $this->addColumn($column, 'longText');
    }

    /**
     * Add a CHAR(36) NOT NULL UNIQUE column for storing a UUID.
     *
     * The column is named 'uuid' by default. Pass a custom name when your
     * schema uses a different column (e.g. 'public_id', 'external_id').
     *
     * The UNIQUE constraint is applied automatically — UUIDs are always
     * unique identifiers, and the constraint lets the DB enforce that at
     * the storage level regardless of application logic.
     *
     * Compatible with both UUID v4 and UUID v7. Pair with $model->uuid = true
     * so the Model base class auto-generates and injects the value on create().
     *
     * @example
     * $table->uuid();            // → `uuid` CHAR(36) NOT NULL UNIQUE
     * $table->uuid('public_id'); // → `public_id` CHAR(36) NOT NULL UNIQUE
     */
    public function uuid(string $column = 'uuid'): Column
    {
        return $this->addColumn($column, 'char', ['length' => 36])->unique();
    }

    /**
     * Add a CHAR(26) NOT NULL UNIQUE column for storing a ULID.
     *
     * ULIDs (Universally Unique Lexicographically Sortable Identifiers) are
     * 26-character Base32 strings that encode a millisecond timestamp and
     * 80 bits of randomness — sortable by creation time, like UUID v7, but
     * more compact in string form.
     *
     * @example
     * $table->ulid();           // → `ulid` CHAR(26) NOT NULL UNIQUE
     * $table->ulid('short_id'); // → `short_id` CHAR(26) NOT NULL UNIQUE
     */
    public function ulid(string $column = 'ulid'): Column
    {
        return $this->addColumn($column, 'char', ['length' => 26])->unique();
    }

    // -------------------------------------------------------------
    // Numeric Columns
    // -------------------------------------------------------------
    public function integer(string $column): Column
    {
        return $this->addColumn($column, 'integer');
    }

    public function tinyInteger(string $column): Column
    {
        return $this->addColumn($column, 'tinyInteger');
    }

    public function smallInteger(string $column): Column
    {
        return $this->addColumn($column, 'smallInteger');
    }

    public function mediumInteger(string $column): Column
    {
        return $this->addColumn($column, 'mediumInteger');
    }

    public function bigInteger(string $column): Column
    {
        return $this->addColumn($column, 'bigInteger');
    }

    public function unsignedInteger(string $column): Column
    {
        return $this->addColumn($column, 'integer')->unsigned();
    }

    public function unsignedTinyInteger(string $column): Column
    {
        return $this->addColumn($column, 'tinyInteger')->unsigned();
    }

    public function unsignedSmallInteger(string $column): Column
    {
        return $this->addColumn($column, 'smallInteger')->unsigned();
    }

    public function unsignedMediumInteger(string $column): Column
    {
        return $this->addColumn($column, 'mediumInteger')->unsigned();
    }

    public function unsignedBigInteger(string $column): Column
    {
        return $this->addColumn($column, 'bigInteger')->unsigned();
    }

    public function boolean(string $column): Column
    {
        return $this->addColumn($column, 'boolean');
    }

    public function decimal(string $column, int $precision = 10, int $scale = 2): Column
    {
        return $this->addColumn($column, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function float(string $column): Column
    {
        return $this->addColumn($column, 'float');
    }

    public function double(string $column): Column
    {
        return $this->addColumn($column, 'double');
    }

    // -------------------------------------------------------------
    // Date & Time Columns
    // -------------------------------------------------------------
    public function date(string $column): Column
    {
        return $this->addColumn($column, 'date');
    }

    public function time(string $column): Column
    {
        return $this->addColumn($column, 'time');
    }

    public function datetime(string $column): Column
    {
        return $this->addColumn($column, 'datetime');
    }

    public function timestamp(string $column): Column
    {
        return $this->addColumn($column, 'timestamp');
    }

    public function timestamps(): void
    {
        $this->addColumn('created_at', 'timestamp')->nullable()->default(null);
        $this->addColumn('updated_at', 'timestamp')->nullable()->default(null);
    }

    public function dropTimestamps(): self
    {
        $this->dropColumns[] = 'created_at';
        $this->dropColumns[] = 'updated_at';
        return $this;
    }

    public function softDeletes(string $column = 'deleted_at'): Column
    {
        return $this->addColumn($column, 'timestamp')->nullable()->default(null);
    }

    public function dropSoftDeletes(string $column = 'deleted_at'): self
    {
        $this->dropColumns[] = $column;
        return $this;
    }

    // -------------------------------------------------------------
    // JSON & Enum
    // -------------------------------------------------------------
    public function json(string $column): Column
    {
        return $this->addColumn($column, 'json');
    }

    public function enum(string $column, array $values): Column
    {
        return $this->addColumn($column, 'enum', ['values' => $values]);
    }

    public function rememberToken(): Column
    {
        return $this->string('remember_token', 100)->nullable();
    }

    // -------------------------------------------------------------
    // Foreign Keys & Relationships
    // -------------------------------------------------------------
    public function foreignId(string $column): Column
    {
        return $this->addColumn($column, 'bigInteger')->unsigned();
    }

    public function foreign(string $column): Column
    {
        foreach ($this->columns as $col) {
            if ($col->getName() === $column) {
                return $col;
            }
        }
        return $this->addColumn($column, 'bigInteger')->unsigned();
    }

    // -------------------------------------------------------------
    // Indexes & Constraints
    // -------------------------------------------------------------
    public function index(string|array $columns, ?string $name = null): self
    {
        $cols = (array) $columns;
        $this->indexes[] = ['columns' => $cols, 'name' => $name, 'type' => 'INDEX'];
        return $this;
    }

    public function unique(string|array $columns, ?string $name = null): self
    {
        $cols = (array) $columns;
        $this->indexes[] = ['columns' => $cols, 'name' => $name, 'type' => 'UNIQUE'];
        return $this;
    }

    public function fulltext(string|array $columns, ?string $name = null): self
    {
        $cols = (array) $columns;
        $this->indexes[] = ['columns' => $cols, 'name' => $name, 'type' => 'FULLTEXT'];
        return $this;
    }

    // -------------------------------------------------------------
    // Alterations & Drops
    // -------------------------------------------------------------
    public function dropColumn(string ...$columns): self
    {
        foreach ($columns as $col) {
            $this->dropColumns[] = $col;
        }
        return $this;
    }

    public function renameColumn(string $from, string $to): self
    {
        $this->renameColumns[] = ['from' => $from, 'to' => $to];
        return $this;
    }

    public function dropIndex(string $name): self
    {
        $this->dropIndexes[] = $name;
        return $this;
    }

    public function dropUnique(string $name): self
    {
        $this->dropIndexes[] = $name;
        return $this;
    }

    public function dropForeign(string $name): self
    {
        $this->dropForeignKeys[] = $name;
        return $this;
    }

    private function addColumn(string $name, string $type, array $parameters = []): Column
    {
        $column = new Column($name, $type, $parameters);
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Generates a CREATE TABLE SQL statement.
     */
    public function toSqlCreate(): string
    {
        $definitions = [];

        foreach ($this->columns as $column) {
            $definitions[] = '    ' . $column->toSql();
        }

        // Add explicit indexes
        foreach ($this->indexes as $idx) {
            $cols = implode('`, `', $idx['columns']);
            $idxName = $idx['name'] ?? ('idx_' . $this->table . '_' . implode('_', $idx['columns']));
            $definitions[] = "    {$idx['type']} `{$idxName}` (`{$cols}`)";
        }

        // Add foreign keys
        foreach ($this->columns as $column) {
            if ($column->hasForeignKey()) {
                $definitions[] = '    ' . $column->getForeignKeySql($this->table);
            }
        }

        $body = implode(",\n", $definitions);
        return "CREATE TABLE IF NOT EXISTS `{$this->table}` (\n{$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    /**
     * Generates ALTER TABLE SQL statements.
     *
     * @return string[]
     */
    public function toSqlAlter(): array
    {
        $statements = [];

        // Drop foreign keys first
        foreach ($this->dropForeignKeys as $fk) {
            $statements[] = "ALTER TABLE `{$this->table}` DROP FOREIGN KEY `{$fk}`;";
        }

        // Drop indexes
        foreach ($this->dropIndexes as $idx) {
            $statements[] = "ALTER TABLE `{$this->table}` DROP INDEX `{$idx}`;";
        }

        // Drop columns
        foreach ($this->dropColumns as $col) {
            $statements[] = "ALTER TABLE `{$this->table}` DROP COLUMN `{$col}`;";
        }

        // Rename columns
        foreach ($this->renameColumns as $rename) {
            $statements[] = "ALTER TABLE `{$this->table}` RENAME COLUMN `{$rename['from']}` TO `{$rename['to']}`;";
        }

        // Add new columns
        foreach ($this->columns as $column) {
            $sql = "ALTER TABLE `{$this->table}` ADD " . $column->toSql();
            $statements[] = $sql . ';';

            if ($column->hasForeignKey()) {
                $statements[] = "ALTER TABLE `{$this->table}` ADD " . $column->getForeignKeySql($this->table) . ';';
            }
        }

        // Add new indexes
        foreach ($this->indexes as $idx) {
            $cols = implode('`, `', $idx['columns']);
            $idxName = $idx['name'] ?? ('idx_' . $this->table . '_' . implode('_', $idx['columns']));
            $statements[] = "ALTER TABLE `{$this->table}` ADD {$idx['type']} `{$idxName}` (`{$cols}`);";
        }

        return $statements;
    }
}