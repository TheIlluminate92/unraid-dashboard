# Attribution

Unraid Dashboard is an integration and presentation layer built on top of the Unraid ecosystem and standard Linux telemetry.

Credit goes to:

- Unraid and Dynamix for the WebUI, Dashboard framework, runtime disk/array state, parity scheduling, Docker Manager, and Docker Organizer
- FolderView Plus for existing Docker folder organization consumed by the Workloads panel
- Disk Location for saved physical drive and shelf assignments
- HBA Viewer for cached controller information and temperatures
- Docker Engine for container state and performance statistics
- apcupsd and NUT for UPS telemetry
- lm-sensors and Linux hwmon for temperature and cooling interfaces
- Intel's `intel_gpu_top` tooling for GPU engine and client telemetry

Unraid Dashboard does not claim ownership of those projects. It reads their available runtime data or configuration when installed. Consult each upstream project for its own license and support terms.

The dashboard layout, requirements, reference-server decisions, and hands-on testing were directed by TheIlluminate92. Implementation and documentation were developed collaboratively with Codex by OpenAI.
