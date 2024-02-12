docker compose rm db
docker compose -f docker-compose.yml --compatibility up --force-recreate --build
