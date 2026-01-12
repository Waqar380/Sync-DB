<?php

echo "Testing PostgreSQL Connection...\n";
echo "================================\n\n";

$host = '127.0.0.1';
$port = '5433';
$dbname = 'legacy_db';
$user = 'postgres';
$password = 'postgres';

echo "Connection Details:\n";
echo "  Host: $host\n";
echo "  Port: $port\n";
echo "  Database: $dbname\n";
echo "  User: $user\n\n";

// Test 1: Check if port is open
echo "Test 1: Port accessibility...\n";
$connection = @fsockopen($host, $port, $errno, $errstr, 5);
if ($connection) {
    echo "✓ Port 5432 is accessible\n";
    fclose($connection);
} else {
    echo "✗ Port 5432 is not accessible: $errstr ($errno)\n";
    exit(1);
}

echo "\n";

// Test 2: Try PDO connection
echo "Test 2: PDO Connection...\n";
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    echo "DSN: $dsn\n";
    
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    
    echo "✓ PDO connection successful!\n";
    
    // Test query
    $stmt = $pdo->query("SELECT current_database(), version()");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "\nConnected to:\n";
    echo "  Database: " . $row['current_database'] . "\n";
    echo "  Version: " . substr($row['version'], 0, 50) . "...\n";
    
    // Test tables
    $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
    echo "\nTables in public schema:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  - " . $row['tablename'] . "\n";
    }
    
    echo "\n✅ All tests passed!\n";
    
} catch (PDOException $e) {
    echo "✗ PDO connection failed!\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    exit(1);
}

