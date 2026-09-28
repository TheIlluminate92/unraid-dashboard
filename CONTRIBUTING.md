# Contributing

Bug reports, compatibility results, documentation fixes, and conservative improvements are welcome.

## Reports

Include the dashboard version, Unraid version, relevant plugin versions, browser, and the smallest set of steps needed to reproduce the problem. Screenshots are useful when they are reviewed and redacted first.

Do not post hostnames, IP or MAC addresses, disk serial numbers, share names, credentials, tokens, private configuration files, or infrastructure inventories.

## Code changes

Preserve the existing `waz.dashboard` plugin identifier and configuration paths so installed systems continue to update safely. Avoid permanent edits to Unraid core files, avoid waking sleeping disks, and keep data collection local.

Run the repository verification workflow before submitting a change:

```powershell
pwsh -File src/waz-dashboard-plugin/tests/verify.ps1
```

Changes that add outbound networking, cloud services, automatic telemetry, Docker socket writes, broad filesystem access, or destructive system behavior require an explicit redesign and security review.
