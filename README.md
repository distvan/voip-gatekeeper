# voip-gatekeeper

This service handles Telnyx webhooks with Slim and verifies requests using the `Telnyx-Signature-Ed25519` and `Telnyx-Timestamp` headers.

## Configuration

The application requires these runtime settings:

```text
TELNYX_PUBLIC_KEY_BASE64
CALL_FORWARD_NUMBER
```

- `TELNYX_PUBLIC_KEY_BASE64`: base64-encoded Telnyx webhook public key for the environment
- `CALL_FORWARD_NUMBER`: destination phone number in E.164 format, for example `+3620XXXXXXX`

## Cloud Run deployment

The repository includes a Dockerfile prepared for Cloud Run:

- it installs Composer dependencies during image build
- it uses a smaller Alpine PHP runtime
- it runs as a non-root user inside the container
- it listens on the Cloud Run `PORT` environment variable
- it routes requests through `public/router.php`

The command examples below use bash syntax and are intended for Google Cloud Shell, Linux, macOS, or Git Bash.

### Deploy with Secret Manager

Create the secret once:

```bash
printf %s "YOUR_BASE64_PUBLIC_KEY" > telnyx-public-key-base64.txt
gcloud secrets create telnyx-public-key-base64 --data-file=telnyx-public-key-base64.txt
```

Add a new version when rotating the key:

```bash
printf %s "YOUR_BASE64_PUBLIC_KEY" > telnyx-public-key-base64.txt
gcloud secrets versions add telnyx-public-key-base64 --data-file=telnyx-public-key-base64.txt
```

Deploy the service:

```bash
gcloud run deploy voip-gatekeeper \
  --source . \
  --region europe-west1 \
  --allow-unauthenticated \
  --min-instances 1 \
  --cpu-boost \
  --set-secrets TELNYX_PUBLIC_KEY_BASE64=telnyx-public-key-base64:latest \
  --set-env-vars CALL_FORWARD_NUMBER=+3620XXXXXXX
```

### Deploy with a plain environment variable

If you do not want to use Secret Manager for this value:

```bash
gcloud run deploy voip-gatekeeper \
  --source . \
  --region europe-west1 \
  --allow-unauthenticated \
  --min-instances 1 \
  --cpu-boost \
  --set-env-vars TELNYX_PUBLIC_KEY_BASE64=YOUR_BASE64_PUBLIC_KEY,CALL_FORWARD_NUMBER=+3620XXXXXXX
```

## Local run

For a local container run:

```bash
docker build -t voip-gatekeeper .
docker run --rm -p 8080:8080 -e TELNYX_PUBLIC_KEY_BASE64=YOUR_BASE64_PUBLIC_KEY -e CALL_FORWARD_NUMBER=+3620XXXXXXX voip-gatekeeper
```

## Runtime notes

The container still uses PHP's built-in web server, which is acceptable for an initial Cloud Run deployment. The image is now tightened by running as a non-root user and by using a dedicated router script so non-file requests are always forwarded to Slim.

## Cold-start recommendations

For this service, the biggest Cloud Run cold-start improvements are operational rather than code-level.

### Recommended order of impact

1. Set a minimum instance count of `1` if webhook latency matters.
2. Enable startup CPU boost.
3. Keep the image small and deterministic.
4. Keep bootstrap work minimal.
5. Keep CLI OPcache enabled in the container runtime.

### Cloud Run settings

Avoiding scale-to-zero is the most effective way to reduce cold starts:

```bash
gcloud run services update voip-gatekeeper \
  --region europe-west1 \
  --min-instances 1
```

Allow Cloud Run to allocate more CPU during startup:

```bash
gcloud run services update voip-gatekeeper \
  --region europe-west1 \
  --cpu-boost
```

These options can also be combined during deploy:

```bash
gcloud run deploy voip-gatekeeper \
  --source . \
  --region europe-west1 \
  --allow-unauthenticated \
  --min-instances 1 \
  --cpu-boost \
  --set-secrets TELNYX_PUBLIC_KEY_BASE64=telnyx-public-key-base64:latest \
  --set-env-vars CALL_FORWARD_NUMBER=+3620XXXXXXX
```

### Why this app is already in a good place

The application bootstrap is intentionally small:

- no database connection is opened on startup
- no `.env` loader runs in production
- no large framework container is initialized
- request verification happens only when a webhook request arrives

That means further cold-start gains will come mainly from Cloud Run settings and PHP runtime tuning, not from refactoring the application code.

### Container-level tuning

The container enables OPcache for the CLI SAPI used by the built-in PHP server. The current runtime flags are:

- `opcache.enable_cli=1`
- `opcache.validate_timestamps=0`

This has lower impact than `min-instances`, but it is the most relevant PHP runtime optimization for the current container approach.