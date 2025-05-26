#!/bin/bash

echo "Starting Support System..."

# Start the containers
docker-compose up -d

echo "Waiting for services to start..."
sleep 30

# Wait for Redmine to be ready
echo "Waiting for Redmine to initialize..."
until curl -s http://localhost:3000 > /dev/null; do
    echo "Waiting for Redmine..."
    sleep 10
done

echo "Running Redmine initialization..."
docker exec -it support-redmine bundle exec rails runner /docker-entrypoint-init.d/01_create_test_project.rb

echo ""
echo "=== Support System Started ==="
echo "MediaWiki: http://localhost:8080"
echo "Redmine: http://localhost:3000"
echo "OpenSearch: http://localhost:9200"
echo "OpenSearch Dashboards: http://localhost:5601"
echo ""
echo "Default credentials:"
echo "Redmine admin: admin/admin"
echo "MediaWiki admin: admin/adminpass"
echo ""
echo "Please check the container logs for the Redmine API key and update your MediaWiki configuration."
