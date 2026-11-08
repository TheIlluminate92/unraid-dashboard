# Rackline 0.2.4 candidate / 2026.11.08.06

This immutable candidate adds the adaptive performance strip. Compact single-socket layouts keep their existing appearance; larger or multiple-socket topologies get a full-width CPU layer with shorter wrapping memory/network/disk I/O cards beneath. Actual socket/core/SMT identity is retained. No collector or native-action contract changes.

Files in this directory: [self-contained installer](rackline.dashboard.plg), [matching payload](rackline.dashboard.tgz), and [SHA256SUMS](SHA256SUMS). The PLG embeds its payload. Two isolated builds reproduced identical bytes. Keep the exact published commit when distributing these files; the candidate commit is not known at preparation time.

Local validation: 209 Rackline and 42 WAZ Node tests, PHP syntax/parser/stress, synthetic SMART HTTP/process tests, portability, true DOM-absence fallback, and 14 isolated package tests passed. Browser test Python and 69 literal JavaScript expressions/scripts compile. The expanded 320–2048px browser matrix, 200% text/reflow, baseline equivalence, nonoverlap, clipping and thinner-secondary-layer assertions are prepared but have NOT run locally. Prior Chromium and Unix-socket restrictions were respected. Exact-head CI and rendered review are required before installation; no installed-host result is claimed.

The existing 0.2.3 and 0.2.2 distributions remain untouched. Use the authorized release coordinator and host owner; installation retains the preceding runtime for manual rollback and does not restart services. No private host evidence, screenshot, experiments or build logs are packaged. Repository publication is not a main merge, GitHub Release/tag or native-action acceptance.
