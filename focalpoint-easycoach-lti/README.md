# Focal Point EasyCoach LTI

Shared, security-focused LTI 1.3 platform integration for the Rayner Focal
Point WordPress multisite.

The initial `0.1.0` foundation only registers the intended REST routes and
returns a safe `503` response. It does not generate cryptographic keys, create
database tables, launch EasyCoach, accept OAuth clients, or store results.

## Responsibilities

This MU plugin will own:

- LTI 1.3 and OpenID Connect platform endpoints;
- OAuth 2.0 client-credentials token issuing;
- JWT signing and verification;
- Focal Point public JWKS publication;
- stable opaque learner subject mapping;
- Assignment and Grade Services line-item and score endpoints;
- validated result ingestion, idempotency and audit metadata.

The learner-facing roleplay controls and profile presentation remain in the
`rayner_focalpoint` theme. Management KPI ingestion and reporting remain in
the `rayner_focalpoint_mgmt` theme.

## Planned platform routes

All routes use the `focalpoint-lti/v1` REST namespace:

- `GET /jwks`
- `GET|POST /authorize`
- `POST /token`
- `GET /lineitems/{lineitem_id}`
- `POST /lineitems/{lineitem_id}/scores`

WordPress exposes these beneath `/wp-json/` on a standard installation.

## Configuration contract

Configuration belongs in protected server/environment configuration, normally
surfaced to WordPress as constants. Do not commit production values.

```php
define('FP_EASYCOACH_LTI_ENABLED', false);
define('FP_EASYCOACH_LTI_CLIENT_ID', '');
define('FP_EASYCOACH_LTI_DEPLOYMENT_ID', '');
define('FP_EASYCOACH_LTI_ISSUER', '');
define('FP_EASYCOACH_LTI_KEY_ID', '');
define('FP_EASYCOACH_LTI_PRIVATE_KEY_PATH', '');
```

The EasyCoach public JWKS endpoint defaults to the vendor-confirmed URL:

```text
https://lti.easygenerator.com/api/v1/jwks
```

It can be overridden with `FP_EASYCOACH_LTI_TOOL_JWKS_URL` if Easygenerator
changes it or a controlled test double is used.

## Local verification

The inactive foundation has a WordPress-free smoke test that checks plugin
bootstrapping, route registration and fail-closed responses:

```bash
php focalpoint-easycoach-lti/tests/smoke.php
```

## Security rules

- Never store private keys beneath the public web root.
- Never commit private keys, secrets, access tokens or result payloads.
- Do not use email addresses or sequential WordPress user IDs as LTI subjects.
  Subjects will be random opaque identifiers persisted in a dedicated mapping.
- Validate issuer, audience, deployment, nonce, state, timestamps and JWT
  signatures before creating a launch session.
- Validate OAuth scope and client assertions before accepting an AGS score.
- Store a score only after the complete request has been authenticated.
