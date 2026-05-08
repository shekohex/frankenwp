#!/usr/bin/env bash

set -euo pipefail

IMAGE_TAG="${1:-waq3/wp:latest-php8.3}"

docker build -t "$IMAGE_TAG" --pull .
