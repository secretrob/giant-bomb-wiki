for file in *.xml; do
    echo "Importing $file into Docker container..."
    php /var/www/html/maintenance/run.php /var/www/html/maintenance/importDump.php < "$file"
done

php /var/www/html/maintenance/run.php maintenance/rebuildrecentchanges.php
