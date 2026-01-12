<?php

echo "Testing Kafka Connection...\n";
echo "============================\n\n";

// Check if rdkafka extension is loaded
if (!extension_loaded('rdkafka')) {
    echo "❌ ERROR: rdkafka extension is NOT loaded!\n";
    echo "Please check your php.ini file.\n";
    exit(1);
}

echo "✓ rdkafka extension is loaded\n\n";

// Test connection
try {
    $conf = new RdKafka\Conf();
    $conf->set('metadata.broker.list', 'localhost:29092');
    $conf->set('socket.timeout.ms', '10000');
    $conf->set('api.version.request', 'true');
    
    echo "Attempting to connect to: localhost:29092\n";
    
    $producer = new RdKafka\Producer($conf);
    
    echo "✓ Producer created\n";
    
    // Get metadata to verify connection
    $metadata = $producer->getMetadata(true, null, 5000);
    
    echo "✓ Connected to Kafka!\n\n";
    echo "Broker Info:\n";
    foreach ($metadata->getBrokers() as $broker) {
        echo "  - Broker ID: {$broker->getId()}, Host: {$broker->getHost()}:{$broker->getPort()}\n";
    }
    
    echo "\nAvailable Topics:\n";
    $topics = $metadata->getTopics();
    foreach ($topics as $topic) {
        if (strpos($topic->getTopic(), 'legacy') !== false || strpos($topic->getTopic(), 'revamp') !== false) {
            echo "  - {$topic->getTopic()}\n";
        }
    }
    
    echo "\n✅ Kafka connection test PASSED!\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: Failed to connect to Kafka\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nTroubleshooting:\n";
    echo "1. Check if Docker containers are running: docker ps\n";
    echo "2. Check if Kafka is healthy: docker logs sync-kafka\n";
    echo "3. Verify port 29092 is accessible: netstat -an | findstr 29092\n";
    exit(1);
}

