# Rackline 0.2.5 / 2026.11.08.07

Immutable candidate for the owner-authorized update on both existing pilots. Publication and exact-head CI must pass before installation. No installed-host acceptance is claimed by this preparation record; later outcomes belong in [the operating guide](../../../docs/RACKLINE.md).

The source baseline is published 0.2.4 at `b6ef4d3a1604f2c4ad4ccc81a4a1da1b7fe9f464`. Only the CSS and frontend presentation change: larger Cooling text with wrapping cards, and saved Disk Location comments as bay labels with verified-name or cached-model fallback. Identity warnings and disabled unverified actions remain. No PHP, collector, model, native control or installer logic changes; the separate identity-hardening prototype is excluded.

The self-contained [installer](rackline.dashboard.plg) embeds the [matching payload](rackline.dashboard.tgz). Two isolated builds reproduce identical bytes. SHA256:

```
b064422e85202f973a0bfddb6750818efe5426c6b62fe34f67b772657465251a  rackline.dashboard.plg
4e671eb265623e07fe11edb3d4c5bb91be97eb6054d7d9b2ce831d17b20d2e8f  rackline.dashboard.tgz
```

All 255 Rackline/WAZ Node tests and isolated package/portability checks pass. Local browser execution remains restricted; the updated desktop/mobile/200% matrix must pass on this exact published source before rollout. Synthetic tests never substitute for live installation and UI verification.

Install through the existing host-owner/native plugin workflow. Preserve exact 0.2.4 runtime, registration, installer and all prior recovery before updating. Retain recovery after acceptance; do not test rollback by interrupting workloads. No core/container restart, fan change, active SMART capture, self-test, array operation or sleeping-drive wake is part of this update. The rolling WAZ distribution, existing Rackline releases, main branch and PR merge status remain unchanged.
