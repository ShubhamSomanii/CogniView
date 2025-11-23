<?php
require __DIR__ . '/_bootstrap.php';
allow_cors();


$body = json_body();
$model = $body['model'] ?? null; // 'openai' | 'gemini' | 'huggingface'
$prompt = trim($body['prompt'] ?? '');


if (!$model || !in_array($model, ['openai', 'gemini', 'huggingface'], true)) { // (CHANGED)
json_out(['error' => 'Invalid model'], 422);
}
if ($prompt === '') {
json_out(['error' => 'Missing prompt'], 422);
}


$dbFile = storage_path('database.sqlite');
if (!file_exists($dbFile)) {
@mkdir(dirname($dbFile), 0777, true);
touch($dbFile);
}


try {
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE IF NOT EXISTS votes (
id INTEGER PRIMARY KEY AUTOINCREMENT,
model TEXT NOT NULL,
prompt TEXT NOT NULL,
created_at TEXT NOT NULL
)');


$stmt = $pdo->prepare('INSERT INTO votes (model, prompt, created_at) VALUES (?, ?, ?)');
$stmt->execute([$model, $prompt, date('c')]);


json_out(['ok' => true]);
} catch (Throwable $e) {
json_out(['error' => 'DB error: ' . $e->getMessage()], 500);
}