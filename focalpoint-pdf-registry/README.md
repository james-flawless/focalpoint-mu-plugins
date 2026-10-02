# Focal Point PDF Registry

This must-use plugin provides the shared OUS, USA, and Management-site audit registry for user PDFs in the `aspirations`, `coaching`, and `feedback` categories.

The registry is written to:

```text
wp-content/uploads/sites/4/pdf_data/PDF_uploads.json
```

The physical PDF remains the source of truth. Registry write failures are logged but do not roll back an otherwise successful upload, open, or deletion operation.

The plugin loader also makes the Management theme's shared PDF email-notification module available across the multisite. This lets both source-theme and Management-theme upload handlers enqueue the same schema without duplicating queue logic. Missing/unavailable notification code remains non-fatal to a completed upload.

## Recorded events

- Live uploads record the recipient, category, stored path, uploader, source site, and source interface.
- Recipient opens record first/last-opened dates and an observed open count. Admin and manager previews do not count as recipient opens.
- Admin deletions retain a tombstone record rather than removing the audit history.
- Reconciliation can add existing filesystem PDFs and mark active records as `missing` when their files disappear outside the normal deletion flow.

## Existing management cron commands

The plugin does not register its own schedule. `rayner_focalpoint_mgmt/cli_cron/fp_mgmt__cron.php` exposes:

```text
PDF-REGISTRY-DRY-RUN
PDF-REGISTRY-MIGRATE
PDF-REGISTRY-RECONCILE
PDF-EMAIL-NOTIFICATIONS-DRY-RUN
PDF-EMAIL-NOTIFICATIONS-CAPTURE
PDF-EMAIL-NOTIFICATIONS-SEND-TEST <job_id>
PDF-EMAIL-NOTIFICATIONS-SEND-RECIPIENT <job_id>
PDF-EMAIL-NOTIFICATIONS-STATUS
PDF-EMAIL-NOTIFICATIONS-PROCESS
```

Always run the dry run before the initial migration. The initial migration and reconciliation are repeatable because record IDs are deterministically generated from recipient site, username, category, and stored filename.

The notification dry run never mutates the queue or registry. The capture command is restricted to the recognised staging host in `capture` mode; it writes redacted previews to `PDF_email_notifications_capture.json` and never calls `wp_mail()`.

Stage 5 test delivery is restricted to the canonical production environment with explicit send enablement and the exact `salesexcellence@rayner.com` override. It requires one job ID and cannot bulk-send or deliver to intended recipients.

Stage 6 recipient delivery remains exact-job only and requires all production guards plus `FP_PDF_EMAIL_NOTIFICATIONS_RECIPIENT_SEND_ENABLED === true`. It resolves the current source-account email in memory, never stores or logs the raw address, and does not add an automatic schedule.

Stage 7 status monitoring is read only. Automated processing requires the separate `FP_PDF_EMAIL_NOTIFICATIONS_AUTOMATED_PROCESSING_ENABLED === true` guard, processes a bounded oldest-first batch, and is intended for the existing hosting/system cron rather than a plugin-owned or WordPress Cron schedule. The Management theme runbook documents activation, rollback, exit codes, retries, SMTP monitoring, and Outlook Focused/Other guidance.

## Verified state

Deployed migration and lifecycle testing was completed on 2026-09-24. A before/after reconciliation comparison retained all 23 record IDs, 19 active / 4 deleted statuses, 19 unread / 4 read states, and all uploader/open/deletion audit history. Expected maintenance changes were limited to `updated_at`, newest-first ordering, and one equivalent UTC-to-BST timestamp representation. Controlled recipient notification job `email_9f5e8ad3bea6593e` was delivered successfully on 2026-10-01; its privacy-safe queue record matched the live-upload registry record and the intended recipient confirmed receipt.

## Deferred frontend

A frontend display for this registry is planned for a later phase. This plugin currently provides storage, event updates, migration, and reconciliation only; it does not expose a frontend dashboard or define its eventual permissions, filters, or presentation.
