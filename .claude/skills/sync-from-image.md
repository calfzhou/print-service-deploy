# Sync from Docker Image

Sync this repo's Dockerfile and related files from the upstream `tzishue/cloud-printer:latest` Docker image.

## When to use

Use this skill when the user says the Docker image has been updated and wants to update the `docker` branch accordingly.

## Prerequisites

- Docker CLI available and able to pull from Docker Hub
- Git configured with push access to the remote

## Workflow

### 1. Sync with remote

Fetch and rebase onto `origin/docker` before making changes, so we push cleanly.

### 2. Pull the latest image

```
docker pull tzishue/cloud-printer:latest
```

Record the new image short ID (first 12 chars of image ID) and digest.

### 3. Extract files from the image

Create a temporary container and export its filesystem via `docker export | tar xf -` to an `extracted/` directory. Extract these paths:

| Image path | Repo path |
|---|---|
| `/entrypoint.sh` | `entrypoint.sh` |
| `/etc/cups/cupsd.conf` | `config/cupsd.conf` |
| `/etc/supervisor/conf.d/supervisord.conf` | `config/supervisord.conf` |
| `/etc/ssh/sshd_config` | (reference only, configured via sed in Dockerfile) |
| `/opt/websocket_printer/printer_client.php` | `app/printer_client.php` |
| `/opt/websocket_printer/generate_qrcode.sh` | `app/generate_qrcode.sh` |
| `/opt/websocket_printer/update.sh` | `app/update.sh` |
| `/opt/websocket_printer/cupsd.conf.default` | (same as `config/cupsd.conf`, no separate file) |
| `/usr/share/cups/doc-root/` | `Chinese_Language/doc-root/` (excluding `default.en/` subdir) |
| `/usr/share/cups/templates/` | `Chinese_Language/templates/` (excluding `default.en/` subdir) |

### 4. Compare extracted files with current branch

Run `diff` for each file pair. Note:
- `config/cupsd.conf`: The image's copy has `DefaultLanguage zh_CN` appended at build time (via `RUN echo`). Strip that last line before comparing.
- `Chinese_Language/` directories: Exclude the `default.en/` subdirectories (those are English backups created at build time).

### 5. Reconstruct the Dockerfile

Get the image's build history via `docker history --no-trunc` and compare with the current `Dockerfile`. Key things to check:

- **LABEL version**: Update to match `version=X.Y.Z` from the image labels
- **Package list**: Compare the `apt-get install` list exactly. Packages may be added, removed, or reordered.
- **RUN layers**: Check mkdir, sed, chmod commands for changes
- **COPY instructions**: Check if new files are added or paths change
- **EXPOSE / VOLUME / HEALTHCHECK / ENTRYPOINT**: Compare for changes

### 6. Update repo files

For each file that differs:
- Copy the extracted file to the corresponding repo path
- For `entrypoint.sh`: Also update the version string if it changed
- For `cupsd.conf`: Do NOT include the `DefaultLanguage zh_CN` line (it's added by a `RUN echo` in the Dockerfile)
- For `Chinese_Language/`: Copy the full directories excluding `default.en/`

### 7. Update IMAGE_SOURCE

Write `IMAGE_SOURCE` with the new provenance metadata:

```
# Source image provenance — auto-generated, do not edit manually
image: docker.io/tzishue/cloud-printer:latest
id: <full image ID>
digest: <repo digest>
created: <image .Created timestamp>
label_version: <version label>
extracted_at: <current UTC time>
```

### 8. Clean up and commit

- Remove the `extracted/` directory
- Stage all changed files (be specific, don't use `git add -A`)
- Commit with message format:

```
Update from cloud-printer image <short-id> (built <YYYY-MM-DD>)

Changes from upstream:
- <bullet list of what changed>

Image: docker.io/tzishue/cloud-printer:latest
Digest: <digest>

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
```

### 9. Push to docker branch

```
git push origin <current-branch>:docker
```

If push fails due to non-fast-forward, fetch and rebase first, then retry.

## File mapping summary

| Repo file/dir | Source in image | Notes |
|---|---|---|
| `Dockerfile` | Reconstructed from `docker history` | Not directly extracted |
| `entrypoint.sh` | `/entrypoint.sh` | |
| `config/cupsd.conf` | `/etc/cups/cupsd.conf` | Exclude appended `DefaultLanguage zh_CN` |
| `config/supervisord.conf` | `/etc/supervisor/conf.d/supervisord.conf` | |
| `app/printer_client.php` | `/opt/websocket_printer/printer_client.php` | |
| `app/generate_qrcode.sh` | `/opt/websocket_printer/generate_qrcode.sh` | |
| `app/update.sh` | `/opt/websocket_printer/update.sh` | |
| `Chinese_Language/doc-root/` | `/usr/share/cups/doc-root/` | Exclude `default.en/` |
| `Chinese_Language/templates/` | `/usr/share/cups/templates/` | Exclude `default.en/` |
| `IMAGE_SOURCE` | Metadata from `docker inspect` | Auto-generated |
