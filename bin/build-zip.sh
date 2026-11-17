#!/usr/bin/env bash
# Build a release zip in dist/ from the committed tree.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(grep -m1 "Version:" fluenthive.php | awk '{print $NF}')
mkdir -p dist
git archive --format=zip --prefix=fluenthive/ -o "dist/fluenthive-${version}.zip" HEAD
echo "dist/fluenthive-${version}.zip"
