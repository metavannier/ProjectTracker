#!/bin/sh
set -e

if [ "$1" = "apache2-foreground" ]; then
    # Web server: PASSWORD and SESSION_SECRET are mandatory, then wait for the
    # database and create / update the tables before accepting requests.
    php /var/www/projecttracker/bin/init-db.php --wait=120 --check-web-config
else
    # Command-line tools (migration, export...): only the database is needed.
    php /var/www/projecttracker/bin/init-db.php --wait=120 >&2
fi

exec docker-php-entrypoint "$@"
