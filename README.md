# voip-gatekeeper

This service handles Telnyx webhooks with Slim and verifies requests using the `Telnyx-Signature-Ed25519` and `Telnyx-Timestamp` headers.

## Configuration

The application requires these runtime settings:

```text
TELNYX_PUBLIC_KEY_BASE64
CALL_FORWARD_DESTINATION_TYPE
CALL_FORWARD_NUMBER
CALL_FORWARD_SIP_URI
CALL_FORWARD_FALLBACK_TO_VOICEMAIL
CALL_FORWARD_TIMEOUT_SECONDS
TELNYX_API_KEY
TELNYX_TTS_VOICE
TELNYX_TTS_LANGUAGE
WHITELISTED_CALLERS
```

- `TELNYX_PUBLIC_KEY_BASE64`: base64-encoded Telnyx webhook public key for the environment
- `CALL_FORWARD_DESTINATION_TYPE`: required forwarding mode, either `e164` or `sip`
- `CALL_FORWARD_NUMBER`: destination phone number in E.164 format, required only when `CALL_FORWARD_DESTINATION_TYPE=e164`, for example `+3620XXXXXXX`
- `CALL_FORWARD_SIP_URI`: destination SIP URI, required only when `CALL_FORWARD_DESTINATION_TYPE=sip`, for example `sip:your-user@pbx.example.com`
- `CALL_FORWARD_FALLBACK_TO_VOICEMAIL`: optional boolean for both `sip` and `e164` forwarding. When `true`, unanswered or failed forwarded calls are sent to the application's Hungarian voicemail flow instead of falling through to the destination's default voicemail.
- `CALL_FORWARD_TIMEOUT_SECONDS`: optional integer between `5` and `120` for both `sip` and `e164` forwarding. This sets the dial ring timeout so fallback to voicemail can start sooner than the provider or carrier default.
- `CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL` and `CALL_FORWARD_SIP_TIMEOUT_SECONDS`: legacy aliases still accepted for backward compatibility.
- `TELNYX_API_KEY`: optional Telnyx API key used only by the new Call Control webhook path. It is required if you point a Voice API Call Control application at this service.
- `TELNYX_TTS_VOICE`: optional TTS voice. Default is `Azure.hu-HU-NoemiNeural` so the voicemail and announcements use a Hungarian voice out of the box. Set it explicitly if your Telnyx account uses a different Hungarian voice or if you want to fall back to `alice`.
- `TELNYX_TTS_LANGUAGE`: optional language used only when `TELNYX_TTS_VOICE=alice`. Default is `hu-HU`.
- `WHITELISTED_CALLERS`: optional comma-separated list of caller numbers in E.164 format, for example `+36201234567,+36701234567`. Whitelisted callers are connected directly to the configured forwarding destination without the normal announcement and confirmation flow. All other callers continue through the normal incoming-call flow, where pressing `1` records a voicemail instead of calling you.

For your original use case, `sip` mode is the recommended way to ring a softphone app on your current phone without needing a second SIM or a second receiving mobile number. The generated TeXML now uses Telnyx's documented `<Sip>` noun with `answerOnBridge="true"`, so the inbound caller remains in ringing state until the SIP endpoint answers instead of being prematurely answered before bridging. If you also enable `CALL_FORWARD_FALLBACK_TO_VOICEMAIL=true`, the service will try the SIP softphone first and then switch the caller to voicemail when the SIP leg is not answered or fails. Use `CALL_FORWARD_TIMEOUT_SECONDS` to shorten how long the SIP app rings before that fallback happens. Reliable background ringing depends on the chosen SIP provider and softphone supporting push notifications.

## Call Control migration slice

The repository now also exposes a Call Control webhook endpoint at `/call-control/incoming`.

This is an initial migration slice intended for direct forwarding flows. When the service receives a `call.initiated` webhook for a whitelisted caller and `TELNYX_API_KEY` is configured, it answers the inbound leg and sends a Telnyx Call Control `dial` command to the configured forwarding destination.

The outbound Call Control dial now carries a correlated `client_state` and uses one of two forwarding strategies:

- without voicemail fallback, it keeps the simpler `link_to` plus `bridge_on_answer` behavior so Telnyx bridges as soon as the destination answers
- with `CALL_FORWARD_FALLBACK_TO_VOICEMAIL=true`, it keeps the legs unbridged, enables Telnyx answering machine detection on the outbound dial, manually bridges only after a `call.machine.detection.ended` result of `human`, and switches the inbound caller to the Hungarian voicemail flow when Telnyx classifies the outbound answer as `machine` or `not_sure`

When forwarding fails and `CALL_FORWARD_FALLBACK_TO_VOICEMAIL=true`, the Call Control path now keeps the inbound leg alive, plays a Hungarian voicemail prompt, starts recording on `call.speak.ended`, and finishes with a spoken thank-you after `call.recording.saved`.

For incoming `call.initiated` webhooks, the Call Control path now treats `state=bridging` as an already-active inbound leg. In that case it skips the `answer` command and starts direct forwarding or the voicemail prompt immediately, which avoids the invalid-answer rejection previously seen from Telnyx.

Current scope and limitation:

- the existing TeXML routes are still present and unchanged
- the Call Control endpoint now handles the whitelisted direct-forwarding lifecycle, including outbound `call.answered`, `call.bridged`, failed `call.hangup`, and Call Control voicemail fallback sequencing
- the Call Control endpoint also handles the non-whitelisted Hungarian voicemail prompt flow
- the Call Control voicemail branch currently ends recording on silence or max length; unlike the TeXML branch, it does not yet stop on `#`

If you point a Telnyx Call Control Voice API application at this service, use `/call-control/incoming` as the webhook URL and set `TELNYX_API_KEY` in the runtime environment.

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
  --set-env-vars CALL_FORWARD_DESTINATION_TYPE=e164,CALL_FORWARD_NUMBER=+3620XXXXXXX
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
  --set-env-vars TELNYX_PUBLIC_KEY_BASE64=YOUR_BASE64_PUBLIC_KEY,CALL_FORWARD_DESTINATION_TYPE=e164,CALL_FORWARD_NUMBER=+3620XXXXXXX,WHITELISTED_CALLERS=+36201234567,+36701234567
```

Deploy the service in SIP softphone mode:

```bash
gcloud run deploy voip-gatekeeper \
  --source . \
  --region europe-west1 \
  --allow-unauthenticated \
  --min-instances 1 \
  --cpu-boost \
  --set-secrets TELNYX_PUBLIC_KEY_BASE64=telnyx-public-key-base64:latest \
  --set-env-vars CALL_FORWARD_DESTINATION_TYPE=sip,CALL_FORWARD_SIP_URI=sip:your-user@pbx.example.com,CALL_FORWARD_FALLBACK_TO_VOICEMAIL=true,CALL_FORWARD_TIMEOUT_SECONDS=12
```

## Local run

For a local container run:

```bash
docker build -t voip-gatekeeper .
docker run --rm -p 8080:8080 -e TELNYX_PUBLIC_KEY_BASE64=YOUR_BASE64_PUBLIC_KEY -e CALL_FORWARD_DESTINATION_TYPE=e164 -e CALL_FORWARD_NUMBER=+3620XXXXXXX -e WHITELISTED_CALLERS=+36201234567,+36701234567 voip-gatekeeper
```

Example for SIP softphone forwarding:

```bash
docker run --rm -p 8080:8080 \
  -e TELNYX_PUBLIC_KEY_BASE64=YOUR_BASE64_PUBLIC_KEY \
  -e CALL_FORWARD_DESTINATION_TYPE=sip \
  -e CALL_FORWARD_SIP_URI=sip:your-user@pbx.example.com \
  -e CALL_FORWARD_FALLBACK_TO_VOICEMAIL=true \
  -e CALL_FORWARD_TIMEOUT_SECONDS=12 \
  -e WHITELISTED_CALLERS=+36201234567,+36701234567 \
  voip-gatekeeper
```

Example with the default Hungarian TTS voice set explicitly:

```bash
docker run --rm -p 8080:8080 \
  -e TELNYX_PUBLIC_KEY_BASE64=YOUR_BASE64_PUBLIC_KEY \
  -e CALL_FORWARD_DESTINATION_TYPE=e164 \
  -e CALL_FORWARD_NUMBER=+3620XXXXXXX \
  -e TELNYX_TTS_VOICE=Azure.hu-HU-NoemiNeural \
  voip-gatekeeper
```

For a local Docker Compose run:

```bash
export TELNYX_PUBLIC_KEY_BASE64=YOUR_BASE64_PUBLIC_KEY
export CALL_FORWARD_DESTINATION_TYPE=e164
export CALL_FORWARD_NUMBER=+3620XXXXXXX
docker compose up --build
```

The sample Compose setup is in [compose.yaml](compose.yaml).

For a lightweight startup check without Telnyx signature headers:

```bash
curl http://localhost:8080/health
```

## Tests

Install development dependencies and run the test suite with:

```bash
composer install
composer test
```

The container image also includes a Docker `HEALTHCHECK` that calls this endpoint internally.

If you add or rename PHP classes under `src/`, rebuild the image before testing. The container uses Composer's optimized authoritative autoloader, so source changes are not picked up by an already-built image.

## Runtime notes

The container still uses PHP's built-in web server, which is acceptable for an initial Cloud Run deployment. The image is now tightened by running as a non-root user and by using a dedicated router script so non-file requests are always forwarded to Slim.

Webhook requests rejected with `403` now emit a JSON log line to stderr with the validation reason and safe request metadata. This is intended to make Cloud Run troubleshooting easier without logging the raw webhook body or signature value.

Those `403` logs also include whether the Telnyx headers were visible through both PSR-7 and `$_SERVER`, along with a SHA-256 fingerprint of the raw request body. This helps isolate header mapping problems versus payload changes in transit.

Signature verification now prefers `php://input` as the raw-body source, with the PSR-7 body stream only as a fallback. The `403` logs include fingerprints for both so you can spot any mismatch between the PHP raw input and the PSR-7 request body.

On startup, the service also logs a SHA-256 fingerprint and decoded length for the configured Telnyx public key. This makes it possible to confirm which key was loaded in production without logging the raw key.

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
  --set-env-vars CALL_FORWARD_DESTINATION_TYPE=e164,CALL_FORWARD_NUMBER=+3620XXXXXXX
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