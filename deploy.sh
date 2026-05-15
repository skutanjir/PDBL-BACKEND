#!/bin/bash
set -e

echo "Membangun Docker image terbaru..."
IMAGE_TAG="$(date +%Y%m%d%H%M%S)-${RANDOM}"
IMAGE="asia-southeast2-docker.pkg.dev/pdbl-backend/laravel-app/laravel:${IMAGE_TAG}"
gcloud builds submit --tag "$IMAGE" .

echo "Deploying ke Cloud Run..."
gcloud run deploy laravel-app --image="$IMAGE" --region=asia-southeast2 --quiet

echo "Mengarahkan traffic ke revision terbaru..."
gcloud run services update-traffic laravel-app --region=asia-southeast2 --to-latest --quiet

echo "Selesai!"
