#!/bin/bash
echo "Menghapus cache docker..."
gcloud builds submit --no-cache --tag asia-southeast2-docker.pkg.dev/pdbl-backend/laravel-app/laravel:latest .
echo "Deploying ke Cloud Run..."
gcloud run deploy laravel-app --image=asia-southeast2-docker.pkg.dev/pdbl-backend/laravel-app/laravel:latest --region=asia-southeast2
echo "Selesai!"