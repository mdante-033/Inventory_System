<?php
$h=getenv('DB_HOST')?:'localhost';
$p=getenv('DB_PORT')?:'5432';
$db=getenv('DB_NAME')?:(getenv('DB_DATABASE')?:'Inventory_DB');
$u=getenv('DB_USER')?:(getenv('DB_USERNAME')?:'postgres');
$pw=getenv('DB_PASSWORD');
echo "env -> DB_HOST=$h DB_PORT=$p DB_NAME=$db DB_USER=$u DB_PASSWORD=" . ($pw ? '[SET]' : '[NOT SET]') . PHP_EOL;
$dsn = "pgsql:host=$h;port=$p;dbname=$db";
try {
    $pdo = new PDO($dsn, $u, $pw, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    echo "PDO: connected\n";
} catch (PDOException $e) {
    echo "PDO ERROR: " . $e->getMessage() . PHP_EOL;
}
