# Rackline 0.2.2 · 2026.11.08.04

This directory preserves the complete, self-contained Rackline installer and its payload. It is separate from WAZ and does not change the WAZ installer or update endpoint.

## Provenance

- Reviewed runtime: `3a914477f0f2c5e088a32779c44c2a45dfb07029`.
- Verified source and CI head: `e4142e73242d59e035d9f357bdcd5545f0d94cc1`.
- [Successful exact-head checks](https://github.com/TheIlluminate92/unraid-dashboard/actions/runs/42454524022): PHP 8.2/8.4, transport tests and 47 synthetic browser scenarios. The existing WAZ checks also passed.
- These files are byte-for-byte copies of the successful PHP 8.4 CI artifact `13606937031`. The PHP 8.2 artifact is identical. All 17 runtime files were compared against the reviewed source.
- No rebuilding or installer modification occurred during publication.

## Install or update

Use the `rackline.dashboard.plg` file from this directory at a pinned commit, retain that exact filename, and verify it against `SHA256SUMS` before installation. The PLG embeds its complete TGZ payload; a separate TGZ download is not required to install. Use Unraid's native plugin installer (`plugin install /path/to/rackline.dashboard.plg`) in the authorized server-management workflow.

Installation stages and validates the payload before replacing only Rackline's runtime. Preserve the current installer and configuration for rollback before an update. No reboot, service restart, fan change or container update is part of this installer. There is no automatic update URL in this build.

## Validation boundaries

At publication, the full package and CI gates have passed; this record does not claim that the new build has passed installed-host acceptance. Synthetic browser checks do not prove native authenticated browser behavior. See [implementation and acceptance details](../../../docs/RACKLINE.md) for capabilities, limitations and rollback requirements.

Active SMART reads require an explicit confirmed action and qualified current-device identity. They are not self-tests or scheduled daily reads. Unsupported controller/device paths fail closed. Do not remove or reassign a drive during a read. Optional GPU attribution, host-process sampling and missing native default bay colors remain documented limitations.
