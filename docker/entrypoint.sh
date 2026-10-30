#!/bin/sh
# Démarrage du conteneur.
#   app        : migrations, caches Laravel, puis Nginx et PHP-FPM (supervisord)
#   scheduler  : planificateur Laravel (remplace la tâche planifiée Windows)
#   autre      : commande lancée telle quelle (ex. php artisan patrimoine:verifier)
set -e
cd /var/www/html

prepare() {
    mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
    [ -d /sauvegardes ] && chown www-data:www-data /sauvegardes || true

    if [ -z "$APP_KEY" ]; then
        echo "APP_KEY manquante : générez-la (docker compose run --rm --no-deps app php artisan key:generate --show)" >&2
        echo "puis copiez-la dans docker/.env." >&2
        exit 1
    fi

    echo "Attente de la base de données ${DB_HOST}:${DB_PORT:-3306}…"
    i=0
    until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "Base injoignable après 2 minutes : vérifiez le service db et les valeurs DB_* de docker/.env." >&2
            exit 1
        fi
        sleep 2
    done
}

artisan() {
    su-exec www-data php artisan "$@"
}

case "$1" in
    certificats)
        # Autorité interne et certificat du serveur « db », créés une seule fois (volume certificats).
        if [ ! -f /certs/server-cert.pem ]; then
            cd /certs
            openssl req -x509 -newkey rsa:3072 -nodes -days 3650 -subj "/CN=Patrimoine CA interne" \
                -keyout ca-key.pem -out ca.pem
            openssl req -newkey rsa:3072 -nodes -subj "/CN=db" -keyout server-key.pem -out server.csr
            printf 'subjectAltName=DNS:db\nextendedKeyUsage=serverAuth\n' > server.ext
            openssl x509 -req -in server.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -days 3650 \
                -extfile server.ext -out server-cert.pem
            rm -f server.csr server.ext ca.srl ca-key.pem
            # MySQL (uid 999 dans l'image officielle) doit lire sa clé ; personne d'autre.
            chown 999:999 server-key.pem server-cert.pem
            chmod 600 server-key.pem
            chmod 644 ca.pem server-cert.pem
            echo "Certificats créés."
        fi
        exit 0
        ;;
    app)
        prepare
        artisan migrate --force
        artisan optimize
        artisan patrimoine:verifier --reparer || true
        exec supervisord -c /etc/supervisord.conf
        ;;
    scheduler)
        prepare
        # Laisse le conteneur app passer les migrations avant le premier passage.
        sleep 20
        exec su-exec www-data php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
