#!/usr/bin/env bash

set -euo pipefail

IMAGE_TAG="${1:-waq3/wp:latest-php8.5}"

docker build -t "$IMAGE_TAG" --pull .
