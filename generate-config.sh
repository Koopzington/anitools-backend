#!/usr/bin/env bash

if [ -f .env ]; then
  read -r -p "A config file already exists, are you sure you want to continue? [y/N] " response
  case $response in
    [yY][eE][sS]|[yY])
      mv .env .env_backup
      chmod 600 .env_backup
      ;;
    *)
      exit 1
    ;;
  esac
fi

cat << EOF > .env
# ------------------------------
# AniTools Backend Configuration

DB_USER=anitools
DB_DATABASE=anitools

DB_PASSWORD=$(LC_ALL=C </dev/urandom tr -dc A-Za-z0-9 | head -c 28)

EOF

chmod 600 .env
