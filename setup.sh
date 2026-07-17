#!/bin/bash
# Giant Bomb Wiki - Simple Setup Script

set -e

# Git for Windows: avoid rewriting paths in `docker exec` args (e.g. /bin/bash -> host bash).
export MSYS2_ARG_CONV_EXCL='*'

echo "=========================================="
echo "Giant Bomb Wiki - Setup"
echo "=========================================="
echo ""

# Load environment variables
if [ ! -f .env ]; then
    echo "❌ Error: .env file not found!"
    echo "Please create .env file (copy from .env.example)"
    exit 1
fi

export $(cat .env | grep -v '^#' | xargs)

# Stop existing containers
echo "Stopping any existing containers..."
docker compose -f docker-compose.yml down 2>/dev/null || true
echo "✓ Stopped"
echo ""

# Build all containers
echo "Building containers..."
docker compose -f docker-compose.yml build
echo "✓ Containers built"
echo ""

# Start containers
echo "Starting containers..."
docker compose -f docker-compose.yml up -d
echo "✓ Containers started"
echo ""

# Get dynamic container names
DB_CONTAINER=$(docker compose -f docker-compose.yml ps -q db)
WIKI_CONTAINER=$(docker compose -f docker-compose.yml ps -q wiki)

# Wait for database
echo "Waiting for database to load..."
echo "⏳ This takes 1-2 minutes on first run"
echo "⏳ Subsequent runs are ~10 seconds (data persists in volume)"
echo ""

sleep 5
until docker exec $DB_CONTAINER mariadb -uroot -p${MARIADB_ROOT_PASSWORD} -e "SELECT 1 FROM gb_wiki.page LIMIT 1" &> /dev/null; do
    printf "."
    sleep 3
done
echo ""
echo "✓ Database ready with data loaded"
echo ""

# Wait for wiki container to be able to connect to database
echo "Waiting for wiki container to connect to database..."
until docker exec $WIKI_CONTAINER php -r "new mysqli('db', 'root', '${MARIADB_ROOT_PASSWORD}', 'gb_wiki');" &> /dev/null; do
    printf "."
    sleep 2
done
echo ""
echo "✓ Wiki can connect to database"
echo ""

# Wait for Redis
REDIS_CONTAINER=$(docker compose -f docker-compose.yml ps -q redis)
echo "Waiting for Redis..."
until docker exec $REDIS_CONTAINER redis-cli ping 2>/dev/null | grep -q PONG; do
    printf "."
    sleep 1
done
echo ""
echo "✓ Redis ready"
echo ""

# build fake aws image store
MINIO_CONTAINER=$(docker compose -f docker-compose.yml ps -q local-gcs)
echo "Building minio (aws) image bucket..."
docker exec $MINIO_CONTAINER mc alias set local http://localhost:9000 dev test@test.com
docker exec $MINIO_CONTAINER mc mb local/gb-wiki-mw
docker exec $MINIO_CONTAINER mc anonymous set download local/gb-wiki-mw
docker exec $MINIO_CONTAINER mc anonymous set public local/gb-wiki-mw
echo "✓ Building minio complete."

# Check MediaWiki installation
if docker exec $WIKI_CONTAINER test -f /var/www/html/LocalSettings.php; then
    echo "✓ MediaWiki already installed"
else
    echo "Installing MediaWiki..."
    docker exec $WIKI_CONTAINER /bin/bash /installwiki.sh
    echo "✓ MediaWiki installed"
fi
echo ""

# Run MediaWiki jobs
echo "Processing MediaWiki jobs..."
docker exec $WIKI_CONTAINER php /var/www/html/maintenance/run.php \
    runJobs --memory-limit=512M --maxjobs=100 2>&1 | tail -5
docker exec $WIKI_CONTAINER php /var/www/html/maintenance/run.php \
    /var/www/html/maintenance/import_templates/import_all_templates.php --type=all
echo "✓ Jobs complete"
echo ""

# Get stats
GAME_COUNT=$(docker exec $WIKI_CONTAINER php /var/www/html/maintenance/run.php sql \
    --query="SELECT COUNT(*) as count FROM page WHERE page_title LIKE 'Games/%' AND page_title NOT LIKE 'Games/%/%'" 2>/dev/null | \
    grep -oP '\d+' | tail -1 || echo "checking...")

echo "=========================================="
echo "✅ Giant Bomb Wiki Ready!"
echo "=========================================="
echo ""
echo "🌐 Access at: http://localhost:8080"
echo ""
echo "📊 Status:"
echo "   - Games in wiki: ${GAME_COUNT}"
echo ""
echo "Useful commands:"
echo "   docker compose -f docker-compose.yml logs -f     # View logs"
echo "   docker compose -f docker-compose.yml down        # Stop"
echo "   docker compose -f docker-compose.yml restart     # Restart"
echo ""
echo "To reset everything and start fresh:"
echo "   docker compose -f docker-compose.yml down -v"
echo "   ./setup.sh"
echo ""
