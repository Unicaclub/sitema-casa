<?php

namespace ERP\Core;

/**
 * Sistema de Banco de Dados com Query Builder
 * Suporta multiple conexões, transações e cache
 */
class Database 
{
    private $connections = [];
    private $defaultConnection;
    private $config;
    private $transactionLevel = 0;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->defaultConnection = $config['default'];
    }
    
    /**
     * Obtém conexão PDO
     */
    public function connection(string $name = null): \PDO
    {
        $name = $name ?? $this->defaultConnection;
        
        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->createConnection($name);
        }
        
        return $this->connections[$name];
    }
    
    /**
     * Cria nova conexão PDO
     */
    private function createConnection(string $name): \PDO
    {
        $config = $this->config['connections'][$name];
        
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $config['driver'],
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );
        
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'"
        ];
        
        return new \PDO($dsn, $config['username'], $config['password'], $options);
    }
    
    /**
     * Query Builder - SELECT
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }
    
    /**
     * Executa query raw
     */
    public function query(string $sql, array $params = [], string $connection = null): \PDOStatement
    {
        $pdo = $this->connection($connection);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Executa query e retorna primeira linha
     */
    public function first(string $sql, array $params = [], string $connection = null): ?array
    {
        $result = $this->query($sql, $params, $connection)->fetch();
        return $result ?: null;
    }
    
    /**
     * Executa query e retorna todas as linhas
     */
    public function select(string $sql, array $params = [], string $connection = null): array
    {
        return $this->query($sql, $params, $connection)->fetchAll();
    }
    
    /**
     * Executa INSERT
     */
    public function insert(string $table, array $data, string $connection = null): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, $data, $connection);
        
        return (int) $this->connection($connection)->lastInsertId();
    }
    
    /**
     * Executa UPDATE
     */
    public function update(string $table, array $data, array $where, string $connection = null): int
    {
        $set = implode(', ', array_map(fn($col) => "{$col} = :{$col}", array_keys($data)));
        $whereClause = implode(' AND ', array_map(fn($col) => "{$col} = :where_{$col}", array_keys($where)));
        
        $sql = "UPDATE {$table} SET {$set} WHERE {$whereClause}";
        
        // Prefixar parâmetros WHERE para evitar conflitos
        $params = $data;
        foreach ($where as $key => $value) {
            $params["where_{$key}"] = $value;
        }
        
        $stmt = $this->query($sql, $params, $connection);
        return $stmt->rowCount();
    }
    
    /**
     * Executa DELETE
     */
    public function delete(string $table, array $where, string $connection = null): int
    {
        $whereClause = implode(' AND ', array_map(fn($col) => "{$col} = :{$col}", array_keys($where)));
        $sql = "UPDATE {$table} SET deleted_at = NOW() WHERE {$whereClause}";
        
        $stmt = $this->query($sql, $where, $connection);
        return $stmt->rowCount();
    }
    
    /**
     * Delete físico (usar com cuidado)
     */
    public function forceDelete(string $table, array $where, string $connection = null): int
    {
        $whereClause = implode(' AND ', array_map(fn($col) => "{$col} = :{$col}", array_keys($where)));
        $sql = "DELETE FROM {$table} WHERE {$whereClause}";
        
        $stmt = $this->query($sql, $where, $connection);
        return $stmt->rowCount();
    }
    
    /**
     * Inicia transação
     */
    public function beginTransaction(string $connection = null): void
    {
        if ($this->transactionLevel === 0) {
            $this->connection($connection)->beginTransaction();
        }
        $this->transactionLevel++;
    }
    
    /**
     * Confirma transação
     */
    public function commit(string $connection = null): void
    {
        if ($this->transactionLevel === 1) {
            $this->connection($connection)->commit();
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }
    
    /**
     * Desfaz transação
     */
    public function rollback(string $connection = null): void
    {
        if ($this->transactionLevel === 1) {
            $this->connection($connection)->rollback();
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }
    
    /**
     * Executa função em transação
     */
    public function transaction(\Closure $callback, string $connection = null)
    {
        $this->beginTransaction($connection);
        
        try {
            $result = $callback($this);
            $this->commit($connection);
            return $result;
        } catch (\Throwable $e) {
            $this->rollback($connection);
            throw $e;
        }
    }
}

/**
 * Query Builder para construção de queries dinâmicas
 */
class QueryBuilder 
{
    private $database;
    private $table;
    private $select = ['*'];
    private $joins = [];
    private $where = [];
    private $orderBy = [];
    private $groupBy = [];
    private $having = [];
    private $limit = null;
    private $offset = null;
    private $params = [];
    
    public function __construct(Database $database, string $table)
    {
        $this->database = $database;
        $this->table = $table;
    }
    
    /**
     * Define colunas do SELECT
     */
    public function select(...$columns): self
    {
        $this->select = empty($columns) ? ['*'] : $columns;
        return $this;
    }
    
    /**
     * Adiciona JOIN
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "INNER JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }
    
    /**
     * Adiciona LEFT JOIN
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "LEFT JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }
    
    /**
     * Adiciona condição WHERE
     */
    public function where(string $column, $operator, $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $paramKey = 'param_' . count($this->params);
        $this->where[] = "{$column} {$operator} :{$paramKey}";
        $this->params[$paramKey] = $value;
        
        return $this;
    }
    
    /**
     * Adiciona condição WHERE com OR
     */
    public function orWhere(string $column, $operator, $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $paramKey = 'param_' . count($this->params);
        $this->where[] = "OR {$column} {$operator} :{$paramKey}";
        $this->params[$paramKey] = $value;
        
        return $this;
    }
    
    /**
     * Adiciona WHERE IN
     */
    public function whereIn(string $column, array $values): self
    {
        $placeholders = [];
        foreach ($values as $value) {
            $paramKey = 'param_' . count($this->params);
            $placeholders[] = ":{$paramKey}";
            $this->params[$paramKey] = $value;
        }
        
        $this->where[] = "{$column} IN (" . implode(',', $placeholders) . ")";
        return $this;
    }
    
    /**
     * Adiciona WHERE LIKE
     */
    public function whereLike(string $column, string $value): self
    {
        return $this->where($column, 'LIKE', "%{$value}%");
    }
    
    /**
     * Adiciona ORDER BY
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = "{$column} {$direction}";
        return $this;
    }
    
    /**
     * Adiciona GROUP BY
     */
    public function groupBy(...$columns): self
    {
        $this->groupBy = array_merge($this->groupBy, $columns);
        return $this;
    }
    
    /**
     * Adiciona LIMIT
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }
    
    /**
     * Adiciona OFFSET
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * Paginação
     */
    public function paginate(int $page, int $perPage = 15): array
    {
        $this->limit($perPage);
        $this->offset(($page - 1) * $perPage);
        
        // Query para contar total
        $countQuery = $this->buildCountQuery();
        $total = $this->database->first($countQuery, $this->params)['total'];
        
        // Query para dados
        $data = $this->get();
        
        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
            'from' => ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total)
        ];
    }
    
    /**
     * Constrói query de contagem
     */
    private function buildCountQuery(): string
    {
        $sql = "SELECT COUNT(*) as total FROM {$this->table}";
        
        if (! empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        
        if (! empty($this->where)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }
        
        return $sql;
    }
    
    /**
     * Executa query e retorna resultados
     */
    public function get(): array
    {
        $sql = $this->buildQuery();
        return $this->database->select($sql, $this->params);
    }
    
    /**
     * Retorna primeiro resultado
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }
    
    /**
     * Constrói query SQL
     */
    private function buildQuery(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->select);
        $sql .= " FROM {$this->table}";
        
        if (! empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        
        if (! empty($this->where)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }
        
        if (! empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        
        if (! empty($this->having)) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }
        
        if (! empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        return $sql;
    }
}
