WIKI_CONTAINER=$(docker compose -f docker-compose.yml ps -q wiki)

echo "Importing wiki data..."
echo ""

if docker exec $WIKI_CONTAINER test -f /data/initial_import_data_xmls.zip; then
    echo "Data found"
else
    echo "Copying data into container"
    docker cp data/initial_import_data_xmls.zip $WIKI_CONTAINER:/data/initial_import_data_xmls.zip
fi

docker cp installinitialwikidata.sh $WIKI_CONTAINER:/installinitialwikidata.sh
docker exec $WIKI_CONTAINER chmod 755 /installinitialwikidata.sh
docker exec $WIKI_CONTAINER /bin/bash /installinitialwikidata.sh
docker exec $WIKI_CONTAINER rm -rf /data/initial_import_data_xmls/
echo "✓ Initial data backup installed"