# Rackline 0.2.6 candidate / 2026.11.10.01

Shorten only the exact shelf Watchdog message “Controller is running under supervision” to “Running”, and the exact Chassis / IPMI Message “Command acknowledged; RPM telemetry is shown separately” to “Command acknowledged”. Leave State values, genuine messages and all warning/fault/unknown/stale behavior unchanged. Omit shelf Telemetry rows only when their message is absent/blank; stale transport still shows cached-value warnings.

Only `assets/js/app.js` runtime presentation changes from published 0.2.5; no CSS, collector, controller, identity/SMART or native-control changes. Source version/build tokens update as usual.

Files: [self-contained installer](rackline.dashboard.plg), [matching payload](rackline.dashboard.tgz), [SHA256SUMS](SHA256SUMS). Two isolated builds reproduce exactly. The PLG embeds the TGZ. Use the exact published commit after publication; that commit is not known during preparation.

Local validation: all eight new wording tests and 42 WAZ Node tests pass. The full Rackline suite reports 218 passed, one failure and two skips out of 221: the failure/skips require PHP, which is unavailable in this executor. Isolated package tests report 13 passed and one real-PHP-lint skip. JavaScript syntax, Python browser-suite syntax and 82 literal browser scripts/expressions compile. PHP/parser/HTTP/portability checks remain blocked by the missing runtime; browser and Unix-socket execution were not retried. Two additional prepared browser cases explicitly check shortened messages, unchanged states, empty Top/Bottom telemetry omission, long-message retention, recovery and stale transport warnings. Exact-head CI must complete these gates before installation.

Publication and host-owner deployment remain pending. Preserve existing 0.2.5 and earlier recovery; no prior immutable artifacts are replaced. No GitHub Release/tag or main merge is implied. No private host evidence or screenshots are included.
