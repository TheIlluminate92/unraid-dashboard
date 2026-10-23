(function () {
  "use strict";

  if (window.WAZHealth && window.WAZHealth.version) return;

  var config = window.WAZHealthBootstrap || {};
  var endpoint = config.endpoint || "/plugins/waz.health/include/status.php";
  var fanEndpoint = config.fanEndpoint || "/plugins/md12xx.fancontrol/include/api.php";
  var fanAvailable = config.fanAvailable !== false;
  // Unraid publishes the current session token as a page-global variable.
  var csrfToken = String(window.csrf_token || config.csrfToken || "");
  var refreshMs = Math.max(3000, Number(config.refreshMs) || 5000);
  var allowedStates = ["normal", "attention", "fault", "unknown"];
  var subsystemOrder = ["array", "storage", "cooling", "ups"];
  var state = {
    server: { label: "WAZ-SERVER", uptimeSeconds: null },
    overall: { label: "SYSTEM NORMAL", state: "normal", message: "" },
    subsystems: {},
    healthSubsystems: {},
    healthOverall: { label: "SYSTEM NORMAL", state: "normal", message: "" },
    fans: { available: false, enabled: false, mode: "auto", manualSpeed: 20, allowedManualSpeeds: [], healthState: "unknown", shelves: [] },
    uptimeSampledAt: Date.now()
  };
  var banner = null;
  var refreshTimer = null;
  var clockTimer = null;
  var resizeObserver = null;
  var fanBusy = false;

  function normalizeState(value) {
    value = String(value || "unknown").toLowerCase();
    return allowedStates.indexOf(value) >= 0 ? value : "unknown";
  }

  function escapeText(value) {
    return String(value == null ? "" : value);
  }

  function formatUptime(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) return "—";
    var totalMinutes = Math.floor(seconds / 60);
    var days = Math.floor(totalMinutes / 1440);
    var hours = Math.floor((totalMinutes % 1440) / 60);
    var minutes = totalMinutes % 60;
    if (days > 0) return days + "d " + hours + "h";
    if (hours > 0) return hours + "h " + minutes + "m";
    return minutes + "m";
  }

  function localTime() {
    return new Intl.DateTimeFormat(undefined, {
      hour: "numeric",
      minute: "2-digit"
    }).format(new Date());
  }

  function createIndicator(key, label) {
    var item = document.createElement("span");
    item.className = "waz-health__subsystem";
    item.dataset.subsystem = key;

    var name = document.createElement("span");
    name.className = "waz-health__subsystem-label";
    name.textContent = label;

    var dot = document.createElement("span");
    dot.className = "waz-health__dot";
    dot.setAttribute("aria-hidden", "true");

    item.appendChild(name);
    item.appendChild(dot);
    return item;
  }

  function openDashboardModules() {
    if (typeof window.contentMgmt === "function") window.contentMgmt();
  }

  function updateDashboardModulesControl() {
    if (!banner) return;
    var control = banner.querySelector(".waz-health__modules");
    if (control) control.hidden = typeof window.contentMgmt !== "function";
  }

  function stateRank(value) {
    return { unknown: 0, normal: 0, attention: 1, fault: 2 }[normalizeState(value)] || 0;
  }

  function normalizeFanStatus(payload) {
    payload = payload && typeof payload === "object" ? payload : {};
    var controller = payload.controller && typeof payload.controller === "object" ? payload.controller : {};
    var watchdog = payload.watchdog && typeof payload.watchdog === "object" ? payload.watchdog : {};
    var watchdogFault = ["restarting", "recovering", "fault"].indexOf(String(watchdog.state || "").toLowerCase()) >= 0;
    var enabled = payload.enabled === true;
    var healthState = !enabled ? "unknown" : (watchdogFault || payload.stale ? "fault" : normalizeState(controller.state || "normal"));
    var message = watchdogFault
      ? (watchdog.message || "MD12xx controller restart in progress")
      : payload.stale
      ? "MD12xx controller status is stale"
      : (controller.message || "");
    return {
      available: payload.md12xxAvailable !== false,
      ipmi: payload.ipmi && typeof payload.ipmi === 'object' ? payload.ipmi : null,
      version: payload.version || "",
      enabled: enabled,
      mode: payload.mode === "manual" ? "manual" : "auto",
      manualSpeed: Number(payload.manualSpeed) || 20,
      allowedManualSpeeds: Array.isArray(payload.allowedManualSpeeds) ? payload.allowedManualSpeeds : [],
      healthState: healthState,
      message: message,
      stale: payload.stale === true,
      controller: controller,
      watchdog: watchdog,
      shelves: Array.isArray(payload.shelves) ? payload.shelves : []
    };
  }

  function applyFanHealth() {
    state.subsystems = Object.assign({}, state.healthSubsystems || {});
    state.overall = Object.assign({}, state.healthOverall || state.overall);
    var fans = state.fans || {};
    var ipmi = fans.ipmi || {};
    if (ipmi.supported && (ipmi.state === 'fault' || (ipmi.stale && ipmi.config && ipmi.config.enabled))) {
      var chassisMessage = ipmi.stale ? 'Chassis cooling status is stale' : (ipmi.message || 'Chassis cooling requires attention');
      state.subsystems.cooling = Object.assign({}, state.subsystems.cooling || {}, { label: 'COOLING', state: 'fault', message: chassisMessage });
      state.overall = Object.assign({}, state.overall, { label: 'FAULT', state: 'fault', message: chassisMessage });
    }
    if (!fans.available || !fans.enabled || stateRank(fans.healthState) === 0) return;
    var cooling = Object.assign({ label: "COOLING", state: "unknown", message: "" }, state.subsystems.cooling || {});
    if (stateRank(fans.healthState) > stateRank(cooling.state)) {
      cooling.state = normalizeState(fans.healthState);
      cooling.message = fans.message || "MD12xx fan control requires attention";
      cooling.metrics = Object.assign({}, cooling.metrics || {}, { md12xx: fans });
      state.subsystems.cooling = cooling;
    }
    if (stateRank(fans.healthState) > stateRank(state.overall.state)) {
      state.overall.state = normalizeState(fans.healthState);
      state.overall.label = state.overall.state === "fault" ? "FAULT" : "ATTENTION";
      state.overall.message = fans.message || "MD12xx fan control requires attention";
    }
  }

  function setFanMode(value) {
    if (fanBusy || !fanAvailable || !state.fans.enabled) return;
    var manual = value !== "auto";
    var speed = manual ? Number(value) : Number(state.fans.manualSpeed || 20);
    var prompt = manual
      ? "Set all enabled MD12xx shelves to " + speed + "% manual fan speed?"
      : "Return all enabled MD12xx shelves to automatic temperature control?";
    if (!window.confirm(prompt)) {
      renderFans();
      return;
    }

    fanBusy = true;
    renderFans();
    if (!csrfToken) {
      fanBusy = false;
      renderFans();
      window.alert("The current Unraid session token is unavailable; reload this page and try again");
      return;
    }
    var body = new URLSearchParams();
    body.set("action", "control");
    body.set("csrf_token", csrfToken);
    body.set("mode", manual ? "manual" : "auto");
    if (manual) body.set("speed", String(speed));
    fetch(fanEndpoint, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
      body: body.toString()
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) throw new Error(payload.error || "Fan control update failed");
          state.fans = normalizeFanStatus(payload.status || payload);
          applyFanHealth();
        });
      })
      .catch(function (error) {
        window.alert(error.message || "Fan control update failed");
      })
      .finally(function () {
        fanBusy = false;
        renderFans();
        window.setTimeout(refresh, 1200);
      });
  }

  function createFanControl() {
    var control = document.createElement("span");
    control.className = "waz-health__fans";
    control.dataset.field = "fans";
    control.hidden = true;
    control.innerHTML = '<span class="waz-health__fans-label"><span class="waz-health__dot" aria-hidden="true"></span>MD12XX</span>' +
      '<span class="waz-health__fan-readings"></span>';

    var select = document.createElement("select");
    select.className = "waz-health__fan-select";
    select.setAttribute("aria-label", "MD12xx fan control mode and manual speed");
    var automatic = document.createElement("option");
    automatic.value = "auto";
    automatic.textContent = "AUTO";
    select.appendChild(automatic);
    select.addEventListener("change", function () { setFanMode(select.value); });
    control.appendChild(select);
    return control;
  }

  function createBanner() {
    var root = document.createElement("section");
    root.id = "waz-health-banner";
    root.className = "waz-health";
    root.setAttribute("role", "status");
    root.setAttribute("aria-live", "polite");
    root.setAttribute("aria-label", "WAZ server health");

    var inner = document.createElement("div");
    inner.className = "waz-health__inner";

    var identity = document.createElement("span");
    identity.className = "waz-health__identity";
    identity.dataset.field = "server";

    var overall = document.createElement("span");
    overall.className = "waz-health__overall";
    overall.innerHTML = '<span class="waz-health__dot" aria-hidden="true"></span><span data-field="overall">SYSTEM NORMAL</span>';

    var dividerOne = document.createElement("span");
    dividerOne.className = "waz-health__divider";
    dividerOne.setAttribute("aria-hidden", "true");

    var subsystems = document.createElement("span");
    subsystems.className = "waz-health__subsystems";
    subsystemOrder.forEach(function (key) {
      subsystems.appendChild(createIndicator(key, key.toUpperCase()));
    });

    var dividerTwo = dividerOne.cloneNode(true);
    dividerTwo.classList.add("waz-health__fan-divider");

    var fanControl = createFanControl();

    var dividerThree = dividerOne.cloneNode(true);
    dividerThree.classList.add("waz-health__fan-divider");

    var meta = document.createElement("span");
    meta.className = "waz-health__meta";

    var modules = document.createElement("button");
    modules.type = "button";
    modules.className = "waz-health__modules";
    modules.hidden = true;
    modules.title = "Manage dashboard modules";
    modules.setAttribute("aria-label", "Manage dashboard modules");
    var modulesIcon = document.createElement("i");
    modulesIcon.className = "fa fa-fw fa-wrench";
    modulesIcon.setAttribute("aria-hidden", "true");
    modules.appendChild(modulesIcon);
    modules.addEventListener("click", openDashboardModules);

    meta.appendChild(modules);
    meta.insertAdjacentHTML("beforeend", '<time class="waz-health__time" data-field="time"></time><span class="waz-health__uptime"><span aria-hidden="true">↑</span><span data-field="uptime">—</span></span>');

    inner.appendChild(identity);
    inner.appendChild(overall);
    inner.appendChild(dividerOne);
    inner.appendChild(subsystems);
    inner.appendChild(dividerTwo);
    var right = document.createElement("span");
    right.className = "waz-health__right";
    var chassis = document.createElement('a');
    chassis.className = 'waz-health__fans';
    chassis.dataset.field = 'chassis';
    chassis.href = '/MD12xxFanControl';
    chassis.hidden = true;
    chassis.title = 'Open chassis cooling controls';
    right.appendChild(chassis);
    right.appendChild(fanControl);
    right.appendChild(dividerThree);
    right.appendChild(meta);
    inner.appendChild(right);
    root.appendChild(inner);

    var issues = document.createElement("div");
    issues.className = "waz-health__issues";
    issues.dataset.field = "issues";
    issues.setAttribute("aria-label", "Active health details");
    root.appendChild(issues);
    return root;
  }

  function renderIssues() {
    if (!banner) return;
    var issuesNode = banner.querySelector('[data-field="issues"]');
    if (!issuesNode) return;

    var activeIssues = [];
    subsystemOrder.forEach(function (key) {
      var value = state.subsystems[key] || {};
      var issueState = normalizeState(value.state);
      if (issueState !== "attention" && issueState !== "fault") return;
      activeIssues.push({
        label: value.label || key.toUpperCase(),
        state: issueState,
        message: value.message || ((value.label || key.toUpperCase()) + " reported " + issueState)
      });
    });

    var overallState = normalizeState(state.overall.state);
    if (!activeIssues.length && (overallState === "attention" || overallState === "fault")) {
      activeIssues.push({
        label: state.overall.label || "SYSTEM",
        state: overallState,
        message: state.overall.message || "System health requires attention"
      });
    }

    activeIssues.sort(function (left, right) {
      var rank = { fault: 2, attention: 1 };
      return (rank[right.state] || 0) - (rank[left.state] || 0);
    });

    issuesNode.replaceChildren();
    activeIssues.forEach(function (issue) {
      var item = document.createElement("span");
      item.className = "waz-health__issue";
      item.dataset.state = issue.state;

      var dot = document.createElement("span");
      dot.className = "waz-health__dot";
      dot.setAttribute("aria-hidden", "true");

      var label = document.createElement("strong");
      label.textContent = issue.label;

      var message = document.createElement("span");
      message.textContent = issue.message;

      item.appendChild(dot);
      item.appendChild(label);
      item.appendChild(message);
      issuesNode.appendChild(item);
    });

    banner.classList.toggle("waz-health--expanded", activeIssues.length > 0);
  }

  function updateClockAndUptime() {
    if (!banner) return;
    var timeNode = banner.querySelector('[data-field="time"]');
    var uptimeNode = banner.querySelector('[data-field="uptime"]');
    if (timeNode) {
      timeNode.textContent = localTime();
      timeNode.setAttribute("datetime", new Date().toISOString());
    }
    if (uptimeNode) {
      var elapsed = Math.max(0, (Date.now() - state.uptimeSampledAt) / 1000);
      var seconds = Number(state.server.uptimeSeconds);
      uptimeNode.textContent = formatUptime(Number.isFinite(seconds) ? seconds + elapsed : NaN);
    }
  }

  function render() {
    if (!banner) return;
    var serverNode = banner.querySelector('[data-field="server"]');
    var overallNode = banner.querySelector('[data-field="overall"]');
    var overall = banner.querySelector(".waz-health__overall");

    if (serverNode) serverNode.textContent = escapeText(state.server.label || "WAZ-SERVER");
    if (overallNode) overallNode.textContent = escapeText(state.overall.label || "STATUS UNKNOWN");
    if (overall) overall.dataset.state = normalizeState(state.overall.state);

    subsystemOrder.forEach(function (key) {
      var item = banner.querySelector('[data-subsystem="' + key + '"]');
      var value = state.subsystems[key] || { label: key.toUpperCase(), state: "unknown" };
      if (!item) return;
      item.dataset.state = normalizeState(value.state);
      var label = item.querySelector(".waz-health__subsystem-label");
      if (label) label.textContent = escapeText(value.label || key.toUpperCase());
      item.setAttribute("aria-label", (value.label || key) + ": " + normalizeState(value.state));
    });

    renderFans();

    renderIssues();
    updateClockAndUptime();
    updateDashboardModulesControl();
  }

  function renderFans() {
    if (!banner) return;
    var control = banner.querySelector('[data-field="fans"]');
    if (!control) return;
    var fans = state.fans || {};
    var available = fans.available === true;
    var ipmi = fans.ipmi || {};
    var chassis = banner.querySelector('[data-field="chassis"]');
    var chassisAvailable = ipmi.available === true;
    if (chassis) {
      chassis.hidden = !chassisAvailable;
      chassis.dataset.state = ipmi.stale || ipmi.state === 'fault' ? 'fault' : 'normal';
      chassis.textContent = 'IPMI · ' + (ipmi.stale ? 'STALE' : ipmi.mode === 'firmware' ? 'iDRAC AUTO' : ((ipmi.mode === 'manual' ? 'MANUAL ' : 'CURVE ') + (ipmi.targetPercent == null ? '—' : ipmi.targetPercent + '%'))) + (Number.isFinite(ipmi.averageRpm) ? ' · ' + ipmi.averageRpm + ' RPM' : '');
    }
    var enabled = fans.enabled === true;
    var mode = fans.mode === "manual" ? "manual" : "auto";
    var select = control.querySelector("select");
    var readings = control.querySelector(".waz-health__fan-readings");
    control.hidden = !available;
    Array.prototype.forEach.call(banner.querySelectorAll(".waz-health__fan-divider"), function (divider) {
      divider.hidden = !available && !chassisAvailable;
    });
    if (!available) return;
    control.dataset.state = enabled ? normalizeState(fans.healthState || "unknown") : "unknown";
    control.classList.toggle("waz-health__fans--disabled", !enabled);
    control.classList.toggle("waz-health__fans--manual", mode === "manual");
    if (select) {
      var speeds = (Array.isArray(fans.allowedManualSpeeds) ? fans.allowedManualSpeeds : [])
        .map(Number)
        .filter(function (speed, index, values) { return Number.isFinite(speed) && speed >= 20 && speed <= 100 && values.indexOf(speed) === index; })
        .sort(function (left, right) { return left - right; });
      var signature = speeds.join(",");
      if (select.dataset.speeds !== signature) {
        select.replaceChildren();
        var automatic = document.createElement("option");
        automatic.value = "auto";
        automatic.textContent = "AUTO";
        select.appendChild(automatic);
        speeds.forEach(function (speed) {
          var option = document.createElement("option");
          option.value = String(speed);
          option.textContent = "MANUAL " + speed + "%";
          select.appendChild(option);
        });
        select.dataset.speeds = signature;
      }
      select.value = mode === "manual" ? String(fans.manualSpeed || 20) : "auto";
      select.disabled = !enabled || fanBusy;
      select.title = enabled ? "Controls all enabled MD12xx shelves through the fan-control plugin" : "Enable the controller in the MD12xx Fan Control plugin";
    }
    if (readings) readings.hidden = mode !== "auto";
    if (readings) {
      readings.replaceChildren();
      (fans.shelves || []).forEach(function (shelf, index) {
        var node = document.createElement("span");
        var label = String(shelf.name || shelf.model || ("SHELF " + (index + 1))).replace(/^MD12(?:00|20)\s*/i, "").trim();
        var rpm = Number(shelf.averageRpm);
        node.textContent = (label || ("SHELF " + (index + 1))).toUpperCase() + " " + (Number.isFinite(rpm) && rpm > 0 ? Math.round(rpm) + " RPM" : "—");
        node.title = [
          shelf.name || shelf.model || ("MD12xx shelf " + (index + 1)),
          shelf.temperatureC == null ? "temperature unavailable" : shelf.temperatureC + "°C from " + (shelf.temperatureSource || "assigned disks"),
          shelf.targetPercent == null ? "target unavailable" : shelf.targetPercent + "% target",
          shelf.telemetryMessage || "fan telemetry current"
        ].join(" · ");
        readings.appendChild(node);
      });
    }
    control.setAttribute("aria-label", enabled
      ? "MD12xx fan control: " + mode + (mode === "manual" ? " at " + fans.manualSpeed + " percent" : "")
      : "MD12xx fan control is disabled");
  }

  function mergeSnapshot(next) {
    if (!next || typeof next !== "object") return;
    if (next.server && typeof next.server === "object") {
      state.server = Object.assign({}, state.server, next.server);
      if (next.server.uptimeSeconds != null) state.uptimeSampledAt = Date.now();
    }
    if (next.overall && typeof next.overall === "object") {
      state.healthOverall = Object.assign({}, state.healthOverall, next.overall);
    }
    if (next.subsystems && typeof next.subsystems === "object") {
      state.healthSubsystems = Object.assign({}, next.subsystems);
    }
    applyFanHealth();
    render();
  }

  function refresh() {
    var healthRequest = fetch(endpoint, {
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" }
    })
      .then(function (response) {
        if (!response.ok) throw new Error("WAZ Health endpoint returned " + response.status);
        return response.json();
      })
      .then(function (snapshot) { return snapshot; });
    var fanRequest = !fanAvailable ? Promise.resolve({ available: false, enabled: false }) : fetch(fanEndpoint + "?_=" + Date.now(), {
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" }
    })
      .then(function (response) {
        if (response.status === 404 || response.status === 410) fanAvailable = false;
        if (!response.ok) throw new Error("MD12xx Fan Control endpoint returned " + response.status);
        return response.json();
      })
      .then(normalizeFanStatus)
      .catch(function () {
        return { available: false, enabled: false, mode: "auto", manualSpeed: 20, allowedManualSpeeds: [], healthState: "unknown", shelves: [] };
      });

    return Promise.all([healthRequest, fanRequest])
      .then(function (results) {
        var snapshot = results[0];
        state.fans = results[1];
        mergeSnapshot(snapshot);
        if (banner) banner.dataset.connection = "online";
        return snapshot;
      })
      .catch(function (error) {
        if (banner) banner.dataset.connection = "offline";
        if (window.console && console.debug) console.debug("WAZ Health refresh failed", error);
        return null;
      });
  }

  function updateStickyOffset(menu) {
    if (!banner || !menu) return;
    var style = window.getComputedStyle(menu);
    var navIsTopSticky = style.position === "sticky" && document.documentElement.classList.contains("Theme--nav-top");
    banner.style.setProperty("--waz-health-sticky-top", navIsTopSticky ? menu.offsetHeight + "px" : "0px");
  }

  function mount() {
    if (document.getElementById("waz-health-banner")) {
      banner = document.getElementById("waz-health-banner");
      return;
    }

    var menu = document.getElementById("menu");
    var display = document.getElementById("displaybox");
    banner = createBanner();

    if (menu && menu.parentNode) {
      menu.insertAdjacentElement("afterend", banner);
      updateStickyOffset(menu);
      if (window.ResizeObserver) {
        resizeObserver = new ResizeObserver(function () { updateStickyOffset(menu); });
        resizeObserver.observe(menu);
      } else {
        window.addEventListener("resize", function () { updateStickyOffset(menu); });
      }
    } else if (display && display.parentNode) {
      display.parentNode.insertBefore(banner, display);
    } else {
      document.body.insertBefore(banner, document.body.firstChild);
    }

    render();
    refresh();
    refreshTimer = window.setInterval(refresh, refreshMs);
    clockTimer = window.setInterval(updateClockAndUptime, 60000);
  }

  window.WAZHealth = {
    version: "@@APP_VERSION@@",
    refresh: refresh,
    setState: function (next) {
      mergeSnapshot(next);
      return JSON.parse(JSON.stringify(state));
    },
    getState: function () {
      return JSON.parse(JSON.stringify(state));
    },
    destroy: function () {
      if (refreshTimer) window.clearInterval(refreshTimer);
      if (clockTimer) window.clearInterval(clockTimer);
      if (resizeObserver) resizeObserver.disconnect();
      if (banner && banner.parentNode) banner.parentNode.removeChild(banner);
      banner = null;
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mount, { once: true });
  } else {
    mount();
  }
})();
