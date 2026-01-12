#!/bin/bash

# ============================================
# Debezium Connector Registration Script
# ============================================
# This script registers both PostgreSQL and MySQL
# connectors with Debezium Connect
# ============================================

CONNECT_HOST="${CONNECT_HOST:-localhost}"
CONNECT_PORT="${CONNECT_PORT:-8083}"
CONNECT_URL="http://${CONNECT_HOST}:${CONNECT_PORT}"

echo "=========================================="
echo "Debezium Connector Registration"
echo "=========================================="
echo "Connect URL: ${CONNECT_URL}"
echo ""

# Wait for Kafka Connect to be ready
echo "Waiting for Kafka Connect to be ready..."
until curl -s -f "${CONNECT_URL}/connectors" > /dev/null 2>&1; do
    echo "Kafka Connect is not ready yet. Retrying in 5 seconds..."
    sleep 5
done
echo "✓ Kafka Connect is ready!"
echo ""

# Function to register a connector
register_connector() {
    local CONNECTOR_FILE=$1
    local CONNECTOR_NAME=$(jq -r '.name' "$CONNECTOR_FILE")
    
    echo "----------------------------------------"
    echo "Registering: $CONNECTOR_NAME"
    echo "----------------------------------------"
    
    # Check if connector already exists
    if curl -s "${CONNECT_URL}/connectors/${CONNECTOR_NAME}" | grep -q "error_code"; then
        echo "Connector does not exist. Creating..."
        
        RESPONSE=$(curl -s -X POST \
            -H "Content-Type: application/json" \
            --data @"$CONNECTOR_FILE" \
            "${CONNECT_URL}/connectors")
        
        if echo "$RESPONSE" | grep -q "error"; then
            echo "✗ Failed to create connector: $CONNECTOR_NAME"
            echo "$RESPONSE" | jq '.'
            return 1
        else
            echo "✓ Successfully created connector: $CONNECTOR_NAME"
        fi
    else
        echo "Connector already exists. Updating configuration..."
        
        CONFIG=$(jq '.config' "$CONNECTOR_FILE")
        
        RESPONSE=$(curl -s -X PUT \
            -H "Content-Type: application/json" \
            --data "$CONFIG" \
            "${CONNECT_URL}/connectors/${CONNECTOR_NAME}/config")
        
        if echo "$RESPONSE" | grep -q "error"; then
            echo "✗ Failed to update connector: $CONNECTOR_NAME"
            echo "$RESPONSE" | jq '.'
            return 1
        else
            echo "✓ Successfully updated connector: $CONNECTOR_NAME"
        fi
    fi
    
    echo ""
}

# Register PostgreSQL connector
if [ -f "postgres-connector.json" ]; then
    register_connector "postgres-connector.json"
else
    echo "✗ postgres-connector.json not found!"
fi

# Register MySQL connector
if [ -f "mysql-connector.json" ]; then
    register_connector "mysql-connector.json"
else
    echo "✗ mysql-connector.json not found!"
fi

# List all connectors
echo "=========================================="
echo "Current Connectors:"
echo "=========================================="
curl -s "${CONNECT_URL}/connectors" | jq '.'
echo ""

# Check connector status
echo "=========================================="
echo "Connector Status:"
echo "=========================================="
for CONNECTOR in $(curl -s "${CONNECT_URL}/connectors" | jq -r '.[]'); do
    echo "Connector: $CONNECTOR"
    curl -s "${CONNECT_URL}/connectors/${CONNECTOR}/status" | jq '.'
    echo ""
done

echo "=========================================="
echo "Registration Complete!"
echo "=========================================="


