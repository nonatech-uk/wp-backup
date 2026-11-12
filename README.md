# YAWP — Yet Another WordPress (Backup) Plugin

Incremental S3 backups with Object Lock for WordPress sites running in containers. Optimised for slow-moving sites — after an initial full backup, incremental backups only run if someone logged in that day.

## Features

- **Full & incremental backups** — tar.gz archive of files + full database dump
- **S3 Object Lock** — COMPLIANCE mode for immutable, ransomware-proof storage
- **Login-triggered incrementals** — no login, no backup, no wasted storage
- **Scheduled full backups** — configurable interval (or manual-only)
- **No SDK dependencies** — minimal S3 client with SigV4 signing using PHP curl
- **Encrypted credentials** — AWS secret key stored with XSalsa20-Poly1305 (libsodium)
- **GitHub auto-updater** — updates via GitHub releases, no WordPress.org listing needed

## Requirements

- PHP 7.4+
- `curl` and `sodium` extensions
- `exec()` enabled (for `tar`)
- S3 bucket with Object Lock enabled

## Installation

```bash
cd /path/to/wp-content/plugins/
git clone git@github.com:nonatech-uk/yawp.git
```

Activate in WordPress admin, then configure under **Settings → YAWP Backup**.

## S3 Bucket Setup

```bash
aws s3api create-bucket --bucket yawp-pitstop --region eu-central-1 \
  --create-bucket-configuration LocationConstraint=eu-central-1 \
  --object-lock-enabled-for-bucket

aws s3api put-bucket-versioning --bucket yawp-pitstop \
  --versioning-configuration Status=Enabled

aws s3api put-object-lock-configuration --bucket yawp-pitstop \
  --object-lock-configuration '{"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"COMPLIANCE","Days":90}}}'
```

IAM policy needs: `s3:PutObject`, `s3:GetObject`, `s3:ListBucket`, `s3:AbortMultipartUpload`, `s3:ListMultipartUploadParts`. No `s3:DeleteObject` — COMPLIANCE mode objects can't be deleted anyway.

## How It Works

1. **Activation** — schedules a daily WP-Cron event at 03:00 UTC
2. **Login hook** — any `wp_login` sets a date flag in `wp_options`
3. **Daily check** — if no full backup exists, runs full; if login flag is set, runs incremental; otherwise skips
4. **Backup** — exports DB (gzipped), streams files into chunked tar.gz parts on disk, uploads each part to S3 with Object Lock headers, writes a manifest
5. **Incremental** — scanner filters files by mtime against `yawp_last_backup_time` so only changed files end up in archive parts (DB is always dumped in full)
6. **Step pacing** — each cron run keeps executing steps for a time budget (240 s from CLI cron, 50 s from web cron, 10 s from the admin status poll), guarded by an atomic lock so overlapping runs never touch the same state

### Hosts with `DISABLE_WP_CRON`

Managed hosts (IONOS and others) often disable WP-Cron and run it themselves every ~15 minutes, which makes `spawn_cron()` a no-op. Backups still complete, but each tick only gets one budget's worth of work. Where you have SSH, add a system cron to drive it every minute:

```
* * * * * cd ~/path/to/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

## License

GPL v2 or later. See [LICENSE](LICENSE).
