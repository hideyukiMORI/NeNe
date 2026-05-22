#!/bin/sh

set -eu

mkdir -p ./data 2>/dev/null
mkdir -p ./view/config 2>/dev/null
mkdir -p ./view/compile 2>/dev/null
mkdir -p ./view/plugins 2>/dev/null
mkdir -p ./log 2>/dev/null

if id www-data >/dev/null 2>&1; then
    chown -R www-data:www-data ./data ./view/compile ./view/plugins ./log
fi

chmod 775 ./data ./view/compile ./view/plugins ./log
