# Deployment Guide — PDBL Backend (Google Cloud Run)

Project ID : `pdbl-backend`
Region     : `asia-southeast2`
Service    : `laravel-app`
Image      : `asia-southeast2-docker.pkg.dev/pdbl-backend/laravel-app/laravel:latest`

---

## One-Time Setup

Run these commands once before the first deploy. They do not need to be repeated on updates.

### 1. Enable required APIs

```bash
gcloud services enable \
  run.googleapis.com \
  cloudbuild.googleapis.com \
  artifactregistry.googleapis.com \
  secretmanager.googleapis.com \
  storage.googleapis.com \
  --project=pdbl-backend
```

### 2. Create Artifact Registry repository (if not exists)

```bash
gcloud artifacts repositories create laravel-app \
  --repository-format=docker \
  --location=asia-southeast2 \
  --project=pdbl-backend
```

### 3. Create GCS bucket for persistent file storage

Uploaded photos and team avatars are stored here. Files survive container restarts and new deployments.

```bash
gcloud storage buckets create gs://pdbl-app-storage \
  --location=asia-southeast2 \
  --project=pdbl-backend
```

### 4. Store Firebase credentials in Secret Manager

Do this from the backend project root directory where the JSON file is located.

```bash
gcloud secrets create firebase-credentials \
  --data-file="storage/app/teka-teki-simulator-firebase-adminsdk-4kn1x-b46b53d53d.json" \
  --project=pdbl-backend
```

### 5. Store sensitive environment variables in Secret Manager

```bash
echo -n "base64:nQ8NEb4VyZFP8W7+3kxHoob6JpDruPD8Rl6zir2HgJg=" | gcloud secrets create app-key --data-file=- --project=pdbl-backend
echo -n "deh4LK1LLMz39xJwnxG5Wf3ZlfX1ZLrkJOLNnhqp9947AToE2QsDm34GOKkgL2yz" | gcloud secrets create jwt-secret --data-file=- --project=pdbl-backend
echo -n "AIOFHawoi@2424ajfdkjnf" | gcloud secrets create db-password --data-file=- --project=pdbl-backend
echo -n "dzbd lscu mubu gzat" | gcloud secrets create mail-password --data-file=- --project=pdbl-backend
```

Replace `YOUR_*` with the actual values from your `.env` file.

### 6. Grant Cloud Run service account access

Get the default compute service account:

```bash
PROJECT_NUMBER=$(gcloud projects describe pdbl-backend --format="value(projectNumber)")
SERVICE_ACCOUNT="${PROJECT_NUMBER}-compute@developer.gserviceaccount.com"
echo "Service account: $SERVICE_ACCOUNT"
```

Grant GCS bucket access:

```bash
gcloud storage buckets add-iam-policy-binding gs://pdbl-app-storage \
  --member="serviceAccount:${SERVICE_ACCOUNT}" \
  --role="roles/storage.objectAdmin"
```

Grant Secret Manager access:

```bash
gcloud projects add-iam-policy-binding pdbl-backend \
  --member="serviceAccount:${SERVICE_ACCOUNT}" \
  --role="roles/secretmanager.secretAccessor"
```

Grant Cloud Build access to Artifact Registry:

```bash
CLOUDBUILD_SA="${PROJECT_NUMBER}@cloudbuild.gserviceaccount.com"

gcloud artifacts repositories add-iam-policy-binding laravel-app \
  --location=asia-southeast2 \
  --member="serviceAccount:${CLOUDBUILD_SA}" \
  --role="roles/artifactregistry.writer" \
  --project=pdbl-backend
```

---

## First Deployment

### Step 1 — Build and push image to Artifact Registry

Run from the backend project root (where `Dockerfile` is located):

```bash
gcloud builds submit \
  --tag asia-southeast2-docker.pkg.dev/pdbl-backend/laravel-app/laravel:latest \
  --project=pdbl-backend
```

### Step 2 — Deploy to Cloud Run (full command for first deploy)

This sets all env vars, secrets, volumes, and Cloud Run configuration. Only needed once — subsequent deploys do not need all these flags.

```bash
gcloud run deploy laravel-app \
  --image=asia-southeast2-docker.pkg.dev/pdbl-backend/laravel-app/laravel:latest \
  --region=asia-southeast2 \
  --project=pdbl-backend \
  --allow-unauthenticated \
  --memory=512Mi \
  --cpu=1 \
  --timeout=60 \
  --min-instances=0 \
  --max-instances=10 \
  --set-env-vars=APP_ENV=production \
  --set-env-vars=APP_DEBUG=false \
  --set-env-vars=APP_URL=https://laravel-app-437363373527.asia-southeast2.run.app \
  --set-env-vars=DB_CONNECTION=pgsql \
  --set-env-vars=DB_HOST=34.101.70.138 \
  --set-env-vars=DB_PORT=5432 \
  --set-env-vars=DB_DATABASE=laravel_db \
  --set-env-vars=DB_USERNAME=laravel_user \
  --set-env-vars=QUEUE_CONNECTION=database \
  --set-env-vars=FILESYSTEM_DISK=public \
  --set-env-vars=LOG_CHANNEL=stack \
  --set-env-vars=LOG_LEVEL=error \
  --set-env-vars=SESSION_DRIVER=database \
  --set-env-vars=SESSION_LIFETIME=43800 \
  --set-env-vars=SESSION_SECURE_COOKIE=true \
  --set-env-vars=MAIL_MAILER=smtp \
  --set-env-vars=MAIL_HOST=smtp.gmail.com \
  --set-env-vars=MAIL_PORT=587 \
  --set-env-vars=MAIL_ENCRYPTION=tls \
  --set-env-vars=MAIL_USERNAME=wudipdbl@gmail.com \
  --set-env-vars=MAIL_FROM_ADDRESS=wudipdbl@gmail.com \
  --set-env-vars="MAIL_FROM_NAME=Wudi App" \
  --set-env-vars=FIREBASE_PROJECT_ID=teka-teki-simulator \
  --set-env-vars=FIREBASE_CREDENTIALS=storage/app/firebase-credentials.json \
  --set-env-vars=JWT_ALGO=HS256 \
  --set-env-vars=JWT_REFRESH_TTL=40320 \
  --set-env-vars=AUTH_GUARD=api \
  --update-secrets=APP_KEY=app-key:latest \
  --update-secrets=JWT_SECRET=jwt-secret:latest \
  --update-secrets=DB_PASSWORD=db-password:latest \
  --update-secrets=MAIL_PASSWORD=mail-password:latest \
  --update-secrets=/var/www/html/storage/app/firebase-credentials.json=firebase-credentials:latest \
  --add-volume=name=gcs-storage,type=cloud-storage,bucket=pdbl-app-storage \
  --add-volume-mount=volume=gcs-storage,mount-path=/var/www/html/storage/app/public
```

After deploy, verify the service URL:

```bash
gcloud run services describe laravel-app \
  --region=asia-southeast2 \
  --project=pdbl-backend \
  --format="value(status.url)"
```

---

## Update / Redeploy

When you push new code and want to deploy an update, only two commands are needed. Env vars, secrets, and volume mounts are already configured on the Cloud Run service and are preserved automatically.

```bash
gcloud builds submit \
  --tag asia-southeast2-docker.pkg.dev/pdbl-backend/laravel-app/laravel:latest \
  --project=pdbl-backend

gcloud run deploy laravel-app \
  --image=asia-southeast2-docker.pkg.dev/pdbl-backend/laravel-app/laravel:latest \
  --region=asia-southeast2 \
  --project=pdbl-backend
```

---

## Rollback to Previous Revision

List recent revisions:

```bash
gcloud run revisions list \
  --service=laravel-app \
  --region=asia-southeast2 \
  --project=pdbl-backend
```

Roll back to a specific revision:

```bash
gcloud run services update-traffic laravel-app \
  --to-revisions=laravel-app-XXXXX=100 \
  --region=asia-southeast2 \
  --project=pdbl-backend
```

Replace `laravel-app-XXXXX` with the revision name from the list output.

---

## Update a Secret Value

If you need to change a secret (e.g. rotate DB password):

```bash
echo -n "NEW_PASSWORD" | gcloud secrets versions add db-password \
  --data-file=- \
  --project=pdbl-backend
```

Then redeploy so Cloud Run picks up the new secret version:

```bash
gcloud run deploy laravel-app \
  --image=asia-southeast2-docker.pkg.dev/pdbl-backend/laravel-app/laravel:latest \
  --region=asia-southeast2 \
  --project=pdbl-backend
```

---

## Update an Environment Variable

```bash
gcloud run services update laravel-app \
  --update-env-vars=KEY=NEW_VALUE \
  --region=asia-southeast2 \
  --project=pdbl-backend
```

This triggers a new revision automatically without rebuilding the image.

---

## Check Logs

```bash
gcloud logging read \
  "resource.type=cloud_run_revision AND resource.labels.service_name=laravel-app" \
  --limit=50 \
  --project=pdbl-backend \
  --format="table(timestamp,textPayload)"
```

Or stream live:

```bash
gcloud beta run services logs tail laravel-app \
  --region=asia-southeast2 \
  --project=pdbl-backend
```

---

## How Persistent Storage Works

Cloud Run mounts the GCS bucket `pdbl-app-storage` directly at `/var/www/html/storage/app/public` using GCS FUSE. This means:

- Uploaded avatars and team images are written to GCS, not the container filesystem
- Files survive container restarts, new deployments, and scale-out to multiple instances
- `php artisan storage:link` creates a symlink `public/storage -> ../storage/app/public` which resolves through the FUSE mount
- Files are served via `https://laravel-app-437363373527.asia-southeast2.run.app/storage/filename.jpg`
- No code changes required — Laravel writes to `storage/app/public/` as normal

The `docker-compose.yml` in this repo is for local development only and is not used by Cloud Run.
