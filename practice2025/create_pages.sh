#!/bin/bash

# Configuration
WIKI_URL="http://localhost:8080/api.php"
USERNAME="Asuadmin"
PASSWORD="asuadminpass"

# Check if jq is installed
if ! command -v jq &> /dev/null; then
    echo "Error: This script requires jq to be installed."
    echo "Please install it with: sudo apt-get install jq"
    exit 1
fi

# First get a login token
echo "Getting login token..."
TOKEN_RESULT=$(curl -s \
  -c cookies.txt \
  "${WIKI_URL}?action=query&meta=tokens&type=login&format=json")
  
LOGIN_TOKEN=$(echo "$TOKEN_RESULT" | jq -r '.query.tokens.logintoken')
echo "Login token: $LOGIN_TOKEN"

if [ -z "$LOGIN_TOKEN" ] || [ "$LOGIN_TOKEN" = "null" ]; then
    echo "Failed to get login token. Response:"
    echo "$TOKEN_RESULT"
    exit 1
fi

# Login with the token
echo "Logging in..."
LOGIN_RESULT=$(curl -s \
  -c cookies.txt \
  -b cookies.txt \
  -d "action=login&format=json" \
  --data-urlencode "lgname=$USERNAME" \
  --data-urlencode "lgpassword=$PASSWORD" \
  --data-urlencode "lgtoken=$LOGIN_TOKEN" \
  "${WIKI_URL}")

echo "Login result: $LOGIN_RESULT"

# Check if login was successful
LOGIN_STATUS=$(echo "$LOGIN_RESULT" | jq -r '.login.result')
if [ "$LOGIN_STATUS" != "Success" ]; then
    echo "Login failed: $LOGIN_STATUS"
    exit 1
fi

# Get CSRF token after successful login
echo "Getting CSRF token..."
CSRF_RESULT=$(curl -s \
  -b cookies.txt \
  "${WIKI_URL}?action=query&meta=tokens&format=json")

CSRF_TOKEN=$(echo "$CSRF_RESULT" | jq -r '.query.tokens.csrftoken')
echo "CSRF token: $CSRF_TOKEN"

if [ -z "$CSRF_TOKEN" ] || [ "$CSRF_TOKEN" = "null" ]; then
    echo "Failed to get CSRF token. Response:"
    echo "$CSRF_RESULT"
    exit 1
fi

# Create pages
for i in {1..5}; do
  TITLE="API Test Page $i"

This page was created automatically via the MediaWiki API.

== Section 1 ==
Content for section 1.

== Section 2 ==
Content for section 2.

[[Category:API Created]]"

  echo "Creating page: $TITLE"
  
  # Create the page
  EDIT_RESULT=$(curl -s \
    -b cookies.txt \
    -d "action=edit&format=json" \
    --data-urlencode "title=$TITLE" \
    --data-urlencode "text=$CONTENT" \
    --data-urlencode "summary=Creating test page via API" \
    --data-urlencode "token=$CSRF_TOKEN" \
    "${WIKI_URL}")
  
  echo "Edit result: $EDIT_RESULT"
  
  EDIT_STATUS=$(echo "$EDIT_RESULT" | jq -r '.edit.result // "error"')
  if [ "$EDIT_STATUS" = "Success" ]; then
    echo "✓ Page $i created successfully"
  else
    echo "✗ Failed to create page $i"
  fi
  
  sleep 1
done

# Clean up
rm cookies.txt
echo "Script completed!"