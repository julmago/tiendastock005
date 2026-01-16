<?php

function store_insert_order(PDO $pdo, array $data): int {
  if (empty($data)) {
    throw new InvalidArgumentException('Order data is required.');
  }

  $columns = array_keys($data);
  $placeholders = array_map(static fn($column) => ':'.$column, $columns);

  $sql = "INSERT INTO orders (".implode(',', $columns).") VALUES (".implode(',', $placeholders).")";
  $stmt = $pdo->prepare($sql);
  foreach ($data as $column => $value) {
    $stmt->bindValue(':'.$column, $value);
  }
  $stmt->execute();

  return (int)$pdo->lastInsertId();
}
