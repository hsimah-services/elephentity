    ];
    $database->beginTransaction();
    try {
        foreach ($statements as $sql) {
            if ($database->exec($sql) === false) {
                $detail = $database->errorInfo()[2] ?? null;
                throw new \RuntimeException('SQLite schema installation failed: ' . (is_string($detail) ? $detail : 'unknown error'));
            }
        }
        $database->commit();
    } catch (\Throwable $error) {
        $database->rollBack();
        throw $error;
    }
};
