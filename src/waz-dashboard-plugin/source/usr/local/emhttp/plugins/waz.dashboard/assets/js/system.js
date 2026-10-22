(function () {
  'use strict';

  if (window.WAZSystem && typeof window.WAZSystem.destroy === 'function') {
    window.WAZSystem.destroy();
  }

  var root = document.querySelector('#waz-system-tile .waz-system');
  if (!root) return;

  var endpoint = '/plugins/waz.dashboard/include/metrics.php';
  var stopped = false;
  var snapshotTimer = null;
  var hbaTimer = null;
  var hbaHasData = false;
  var hbaAvailable = root.getAttribute('data-hba-available') !== 'no';
  var previousCpu = null;
  var previousRapl = null;
  var previousNetwork = null;
  var lastSnapshotAt = 0;
  var coreSignature = null;
  var coreNodes = {};
  var coreSummaries = {};
  var socketSummaries = {};
  var expansionKey = 'waz.dashboard.cpuExpansion.v1';
  var expansion = readExpansion();
  var smoothLoads = {};
  var cpuHistory = [];
  var rxHistory = [];
  var txHistory = [];
  var MAX_HISTORY = 300;

  function node(id) { return document.getElementById(id); }

  function text(id, value) {
    var target = node(id);
    if (target) target.textContent = value;
  }

  function finite(value) {
    return typeof value === 'number' && isFinite(value);
  }

  function clamp(value, minimum, maximum) {
    return Math.max(minimum, Math.min(maximum, value));
  }

  function percent(value) {
    return finite(value) ? Math.round(clamp(value, 0, 100)) + '%' : '--';
  }

  function temperature(value) {
    return finite(value) ? value.toFixed(value % 1 === 0 ? 0 : 1) + '°C' : '--';
  }

  function watts(value) {
    return finite(value) ? value.toFixed(value >= 100 ? 0 : 1) + ' W' : '--';
  }

  function bytes(value) {
    if (!finite(value)) return '--';
    var units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    var amount = Math.max(0, value);
    var unit = 0;
    while (amount >= 1024 && unit < units.length - 1) {
      amount /= 1024;
      unit += 1;
    }
    var digits = amount >= 100 || unit === 0 ? 0 : amount >= 10 ? 1 : 2;
    return amount.toFixed(digits) + ' ' + units[unit];
  }

  function rate(value) {
    if (!finite(value)) return '--';
    var bits = Math.max(0, value) * 8;
    if (bits >= 1000000000) return (bits / 1000000000).toFixed(bits >= 10000000000 ? 0 : 1) + ' Gb/s';
    if (bits >= 1000000) return (bits / 1000000).toFixed(bits >= 100000000 ? 0 : 1) + ' Mb/s';
    if (bits >= 1000) return (bits / 1000).toFixed(bits >= 100000 ? 0 : 1) + ' kb/s';
    return Math.round(bits) + ' b/s';
  }

  function counterDelta(current, previous) {
    if (!current || !previous) return null;
    if (![current.total, current.idle, previous.total, previous.idle].every(finite)) return null;
    var total = Number(current.total) - Number(previous.total);
    var idle = Number(current.idle) - Number(previous.idle);
    if (total <= 0 || idle < 0 || idle > total) return null;
    return { busy: total - idle, total: total };
  }

  function cpuDelta(current, previous) {
    var delta = counterDelta(current, previous);
    return delta ? 100 * delta.busy / delta.total : null;
  }

  function readExpansion() {
    var saved = Object.create(null);
    try {
      var parsed = JSON.parse(window.localStorage.getItem(expansionKey) || '{}');
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        Object.keys(parsed).forEach(function (key) {
          if (/^(socket|core):/.test(key) && parsed[key] === true) saved[key] = true;
        });
      }
    } catch (error) { /* Storage may be blocked or contain an older value. */ }
    return saved;
  }

  function saveExpansion() {
    try { window.localStorage.setItem(expansionKey, JSON.stringify(expansion)); }
    catch (error) { /* The view still works without persistent browser storage. */ }
  }

  function rememberExpansion(details, key) {
    details.open = expansion[key] === true;
    details.addEventListener('toggle', function () {
      if (details.open) expansion[key] = true;
      else delete expansion[key];
      saveExpansion();
    });
  }

  function coreKey(core) {
    return String(core.packageId == null ? 'unknown' : core.packageId) + ':' + core.coreId;
  }

  function cpuSummary(details, label, description) {
    var summary = document.createElement('summary');
    summary.className = 'waz-cpu-summary';
    var name = document.createElement('span');
    name.className = 'waz-cpu-summary-name';
    var heading = document.createElement('b');
    heading.textContent = label;
    var count = document.createElement('small');
    count.textContent = description;
    name.appendChild(heading);
    name.appendChild(count);
    var load = document.createElement('em');
    load.textContent = '--';
    var temp = document.createElement('span');
    temp.className = 'waz-cpu-summary-temp';
    temp.textContent = '--';
    summary.appendChild(name);
    summary.appendChild(load);
    summary.appendChild(temp);
    details.appendChild(summary);
    return { value: load, temp: temp };
  }

  function combinedLoad(threads, counters) {
    var busy = 0;
    var total = 0;
    var seen = Object.create(null);
    threads.forEach(function (thread) {
      var key = 'cpu' + thread.cpuId;
      if (seen[key]) return;
      seen[key] = true;
      var delta = counterDelta(counters[key], previousCpu && previousCpu[key]);
      if (delta) { busy += delta.busy; total += delta.total; }
    });
    return total > 0 ? busy * 100 / total : null;
  }

  function smooth(key, value) {
    if (!finite(value)) return null;
    smoothLoads[key] = finite(smoothLoads[key]) ? smoothLoads[key] * 0.58 + value * 0.42 : value;
    return smoothLoads[key];
  }

  function raplWatts(current, sampledAt) {
    if (!current || !previousRapl || !finite(sampledAt) || !finite(previousRapl.sampledAt)) return null;
    var seconds = (sampledAt - previousRapl.sampledAt) / 1000;
    if (seconds <= 0 || seconds > 10) return null;
    var total = 0;
    var found = false;
    Object.keys(current).forEach(function (name) {
      var now = current[name];
      var before = previousRapl.domains[name];
      if (!now || !before) return;
      var change = Number(now.energyUj) - Number(before.energyUj);
      if (change < 0 && Number(now.maxEnergyUj) > 0) change += Number(now.maxEnergyUj);
      if (isFinite(change) && change >= 0) {
        total += change;
        found = true;
      }
    });
    return found ? total / 1000000 / seconds : null;
  }

  function pushHistory(target, value) {
    target.push(finite(value) ? value : 0);
    if (target.length > MAX_HISTORY) target.shift();
  }

  function drawLine(id, values, height, fixedMaximum) {
    var line = node(id);
    if (!line || !values.length) return;
    var maximum = fixedMaximum || Math.max.apply(Math, values.concat([1]));
    var divisor = Math.max(1, values.length - 1);
    line.setAttribute('points', values.map(function (value, index) {
      var x = index / divisor * 600;
      var y = height - clamp(value / maximum, 0, 1) * height;
      return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' '));
  }

  function rebuildCores(cores) {
    var target = node('waz-core-list');
    if (!target) return;
    target.textContent = '';
    coreNodes = {};
    coreSummaries = {};
    socketSummaries = {};
    smoothLoads = {};
    var sockets = Object.create(null);
    var retained = Object.create(null);
    if (!cores.length) target.textContent = 'CPU topology unavailable';
    cores.forEach(function (core) {
      var key = String(core.packageId == null ? 'unknown' : core.packageId);
      if (!sockets[key]) sockets[key] = [];
      sockets[key].push(core);
    });
    Object.keys(sockets).sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); }).forEach(function (socketId) {
      var socketCores = sockets[socketId];
      var threads = [].concat.apply([], socketCores.map(function (core) { return core.threads || []; }));
      var socket = document.createElement('details');
      socket.className = 'waz-cpu-socket';
      var socketKey = 'socket:' + socketId;
      retained[socketKey] = expansion[socketKey] === true;
      rememberExpansion(socket, socketKey);
      var socketNodes = cpuSummary(socket, 'SOCKET ' + socketId, socketCores.length + (socketCores.length === 1 ? ' core · ' : ' cores · ') + threads.length + (threads.length === 1 ? ' thread' : ' threads'));
      socketNodes.temp.title = 'Hottest available core temperature';
      socketSummaries[socketId] = socketNodes;
      var grid = document.createElement('div');
      grid.className = 'waz-socket-cores';
      socketCores.forEach(function (core) {
        var card = document.createElement('details');
        card.className = 'waz-core';
        var key = coreKey(core);
        retained['core:' + key] = expansion['core:' + key] === true;
        rememberExpansion(card, 'core:' + key);
        var threadCount = (core.threads || []).length;
        var summaryNodes = cpuSummary(card, 'CORE ' + core.coreId, threadCount + (threadCount === 1 ? ' thread' : ' threads'));
        coreSummaries[key] = summaryNodes;
        var threadList = document.createElement('div');
        threadList.className = 'waz-core-threads';

        (core.threads || []).forEach(function (thread) {
          var row = document.createElement('div');
          row.className = 'waz-thread';
          var threadLabel = document.createElement('b');
          threadLabel.textContent = 'CPU ' + thread.cpuId;
          var meter = document.createElement('span');
          meter.className = 'waz-mini-meter';
          var fill = document.createElement('i');
          meter.appendChild(fill);
          var value = document.createElement('em');
          value.textContent = '--';
          row.appendChild(threadLabel);
          row.appendChild(meter);
          row.appendChild(value);
          threadList.appendChild(row);
          coreNodes[String(thread.cpuId)] = { fill: fill, value: value };
        });
        card.appendChild(threadList);
        grid.appendChild(card);
      });
      socket.appendChild(grid);
      target.appendChild(socket);
    });
    expansion = retained;
    saveExpansion();
  }

  function renderCpu(cpu) {
    var counters = cpu.counters || {};
    var overall = smooth('overall', cpuDelta(counters.cpu, previousCpu && previousCpu.cpu));
    text('waz-system-model', cpu.model || 'Processor unavailable');
    text('waz-cpu-count', (cpu.cores || []).length + ' cores / ' + (cpu.threadCount || 0) + ' threads');

    var signature = (cpu.cores || []).map(function (core) {
      return coreKey(core) + ':' + (core.threads || []).map(function (thread) { return thread.cpuId; }).join(',');
    }).join('|');
    if (signature !== coreSignature) {
      rebuildCores(cpu.cores || []);
      coreSignature = signature;
    }

    var sockets = Object.create(null);
    (cpu.cores || []).forEach(function (core) {
      var socketId = String(core.packageId == null ? 'unknown' : core.packageId);
      if (!sockets[socketId]) sockets[socketId] = { threads: [], temperatures: [] };
      sockets[socketId].threads = sockets[socketId].threads.concat(core.threads || []);
      if (finite(core.tempC)) sockets[socketId].temperatures.push(core.tempC);
      var summary = coreSummaries[coreKey(core)];
      if (summary) {
        summary.value.textContent = percent(smooth('core:' + coreKey(core), combinedLoad(core.threads || [], counters)));
        summary.temp.textContent = temperature(core.tempC);
      }
      (core.threads || []).forEach(function (thread) {
        var key = String(thread.cpuId);
        var load = smooth('cpu' + key, cpuDelta(counters['cpu' + key], previousCpu && previousCpu['cpu' + key]));
        var targets = coreNodes[key];
        if (!targets) return;
        targets.value.textContent = percent(load);
        targets.fill.style.width = (finite(load) ? clamp(load, 0, 100) : 0) + '%';
      });
    });
    Object.keys(sockets).forEach(function (socketId) {
      var targets = socketSummaries[socketId];
      if (!targets) return;
      var socket = sockets[socketId];
      targets.value.textContent = percent(smooth('socket:' + socketId, combinedLoad(socket.threads, counters)));
      targets.temp.textContent = temperature(socket.temperatures.length ? Math.max.apply(Math, socket.temperatures) : null);
    });

    if (finite(overall)) {
      pushHistory(cpuHistory, overall);
      drawLine('waz-cpu-chart-line', cpuHistory, 90, 100);
    }
    previousCpu = counters;
    return overall;
  }

  function renderMemory(memory) {
    var total = Number(memory.totalBytes);
    var available = Number(memory.availableBytes);
    var used = finite(total) && finite(available) ? Math.max(0, total - available) : null;
    var usage = finite(used) && total > 0 ? used * 100 / total : null;
    text('waz-summary-memory', percent(usage));
    text('waz-summary-memory-detail', bytes(used) + ' / ' + bytes(total));
    text('waz-memory-percent', percent(usage));
    text('waz-memory-used', bytes(used) + ' used');
    text('waz-memory-available', bytes(available) + ' available');
    text('waz-memory-hardware', memory.hardwareSummary || bytes(total) + ' usable');
    var bar = node('waz-memory-bar');
    if (bar) bar.style.width = (finite(usage) ? clamp(usage, 0, 100) : 0) + '%';
  }

  function renderFilesystem(prefix, filesystem) {
    filesystem = filesystem || {};
    var available = filesystem.available === true;
    var usage = available ? Number(filesystem.usagePercent) : null;
    text('waz-' + prefix + '-percent', available ? percent(usage) : 'N/A');
    text('waz-' + prefix + '-used', available ? bytes(Number(filesystem.usedBytes)) + ' used' : 'Unavailable');
    text('waz-' + prefix + '-total', available ? bytes(Number(filesystem.totalBytes)) + ' total' : '--');
    var bar = node('waz-' + prefix + '-bar');
    if (bar) bar.style.width = (available && finite(usage) ? clamp(usage, 0, 100) : 0) + '%';
  }

  function processLabel(process) {
    if (!process) return '';
    if (process.container) return process.name + ' / ' + process.container;
    return process.name || ('PID ' + process.pid);
  }

  function renderGpu(gpu) {
    gpu = gpu || {};
    var available = gpu.available === true && gpu.stale !== true;
    text('waz-summary-gpu', available ? percent(gpu.loadPercent) : 'N/A');
    var processes = Array.isArray(gpu.processes) ? gpu.processes : [];
    text('waz-summary-gpu-detail', available
      ? (processes.length ? processes.map(processLabel).join(', ') : 'No processes') + ' · video ' + percent(gpu.videoLoadPercent)
      : 'GPU data unavailable');
  }

  function renderNetwork(network, sampledAt) {
    network = network || {};
    var current = network.counters || {};
    var rx = null;
    var tx = null;
    if (previousNetwork && finite(sampledAt) && finite(previousNetwork.sampledAt)) {
      var seconds = (sampledAt - previousNetwork.sampledAt) / 1000;
      if (seconds > 0 && seconds <= 10) {
        rx = Math.max(0, Number(current.rxBytes) - Number(previousNetwork.counters.rxBytes)) / seconds;
        tx = Math.max(0, Number(current.txBytes) - Number(previousNetwork.counters.txBytes)) / seconds;
      }
    }
    previousNetwork = { sampledAt: sampledAt, counters: current };

    text('waz-summary-network-rx', '↓ ' + (finite(rx) ? rate(rx) : '--'));
    text('waz-summary-network-tx', '↑ ' + (finite(tx) ? rate(tx) : '--'));
    text('waz-summary-network-detail', (network.interface || 'Network') + (network.mode ? ' · ' + network.mode : ''));
    var linkParts = [network.interface || 'Network'];
    if (finite(network.speedMbps) && network.speedMbps > 0) linkParts.push(network.speedMbps >= 1000 ? (network.speedMbps / 1000) + ' Gb/s' : network.speedMbps + ' Mb/s');
    if (network.mode) linkParts.push(network.mode);
    text('waz-network-link', linkParts.join(' · '));

    if (finite(rx) && finite(tx)) {
      pushHistory(rxHistory, rx);
      pushHistory(txHistory, tx);
      var scale = Math.max.apply(Math, rxHistory.concat(txHistory).concat([125000]));
      drawLine('waz-network-rx-line', rxHistory, 70, scale);
      drawLine('waz-network-tx-line', txHistory, 70, scale);
    }

    var target = node('waz-network-members');
    if (!target) return;
    target.textContent = '';
    var members = Array.isArray(network.members) ? network.members : [];
    if (!members.length) members = [{ name: network.interface || 'interface', up: network.up, speedMbps: network.speedMbps, duplex: network.duplex }];
    members.forEach(function (member) {
      var row = document.createElement('div');
      row.className = 'waz-member';
      var name = document.createElement('b');
      var dot = document.createElement('i');
      dot.className = 'waz-state-dot' + (member.up ? '' : ' waz-down');
      name.appendChild(dot);
      name.appendChild(document.createTextNode(member.name));
      var details = document.createElement('span');
      details.textContent = member.up ? ((member.speedMbps || '--') + ' Mb/s' + (member.duplex ? ' ' + member.duplex : '')) : 'DOWN';
      row.appendChild(name);
      row.appendChild(details);
      target.appendChild(row);
    });
  }

  function renderStatusBoard(data, cpuPower, cpuLoad) {
    var cooling = data.cooling || {};
    var power = data.power || {};
    var rack = power.rack || {};
    text('waz-summary-cpu', percent(cpuLoad) + ' · ' + temperature(cooling.cpuTempC));
    text('waz-summary-cpu-detail', 'Package power ' + watts(cpuPower));
    text('waz-summary-power', watts(rack.watts));
    text('waz-summary-power-detail', rack.available
      ? 'UPS load ' + percent(rack.loadPercent) + (finite(rack.nominalWatts) ? ' of ' + watts(rack.nominalWatts) : '')
      : 'UPS power unavailable');
    text('waz-summary-cooling', temperature(cooling.coolantTempC));
    text('waz-summary-flow', finite(cooling.flowLph) ? cooling.flowLph.toFixed(1) + ' L/hr' : '--');
  }

  function setFreshness(state, label) {
    var target = node('waz-system-freshness');
    if (!target) return;
    target.className = 'waz-freshness ' + state;
    target.textContent = '';
    target.appendChild(document.createElement('i'));
    target.appendChild(document.createTextNode(' ' + label));
  }

  function renderSnapshot(data) {
    var sampledAt = Number(data.sampledAtMs) || Date.now();
    var cpuPower = raplWatts((data.power || {}).raplDomains || {}, sampledAt);
    var cpuLoad = renderCpu(data.cpu || {});
    renderMemory(data.memory || {});
    renderFilesystem('log', (data.filesystems || {}).log);
    renderGpu(data.gpu || {});
    renderNetwork(data.network || {}, sampledAt);
    renderStatusBoard(data, cpuPower, cpuLoad);
    previousRapl = { sampledAt: sampledAt, domains: (data.power || {}).raplDomains || {} };
    lastSnapshotAt = Date.now();
    setFreshness('', 'LIVE');
    text('waz-system-updated', 'Updated ' + new Date(sampledAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
  }

  function fetchJson(url) {
    return fetch(url, { cache: 'no-store', credentials: 'same-origin' }).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    });
  }

  function hbaStatusLabel(controller) {
    var status = String(controller.status || '').toLowerCase();
    if (status === 'ok' || status === 'normal') return 'NORMAL';
    if (status === '') return '';
    return status.toUpperCase();
  }

  function renderHbaCell(position, controller) {
    var label = node('waz-hba-' + position + '-label');
    var value = node('waz-hba-' + position + '-temp');
    var detail = node('waz-hba-' + position + '-detail');
    if (!label || !value || !detail) return;

    if (!controller) {
      label.textContent = 'HBA ' + position;
      value.textContent = 'N/A';
      value.className = 'waz-temp-unknown';
      detail.textContent = 'Controller not detected';
      return;
    }

    var controllerId = controller.controller === undefined ? position : controller.controller;
    var temperatureC = controller.temp_c === null || controller.temp_c === '' ? null : Number(controller.temp_c);
    var state = [controller.status, controller.temp_band, controller.cfg_band, controller.error].join(' ').toLowerCase();
    label.textContent = 'HBA /C' + controllerId;
    value.textContent = finite(temperatureC) ? temperature(temperatureC) : 'N/A';
    value.className = /critical|alert|fault|error/.test(state)
      ? 'waz-temp-fault'
      : (/warning|warn|hot|attention/.test(state) ? 'waz-temp-attention' : '');
    var parts = [];
    if (controller.model) parts.push(String(controller.model));
    var status = hbaStatusLabel(controller);
    if (status) parts.push(status);
    detail.textContent = parts.join(' · ') || 'HBA Viewer';
  }

  function renderHba(data) {
    var controllers = data && Array.isArray(data.controllers) ? data.controllers.slice() : [];
    controllers.sort(function (left, right) {
      return Number(left.controller || 0) - Number(right.controller || 0);
    });
    renderHbaCell(0, controllers[0]);
    renderHbaCell(1, controllers[1]);
    hbaHasData = controllers.length > 0;
  }

  function hbaLoop() {
    if (stopped) return;
    if (!hbaAvailable) {
      text('waz-hba-0-detail', 'HBA Viewer not installed');
      text('waz-hba-1-detail', 'HBA Viewer not installed');
      return;
    }
    fetch('/plugins/hbaviewer/export.php?format=json&_=' + Date.now(), {
      cache: 'no-store',
      credentials: 'same-origin'
    })
      .then(function (response) {
        if (response.status === 404 || response.status === 410) {
          hbaAvailable = false;
          hbaHasData = false;
          renderHbaCell(0, null);
          renderHbaCell(1, null);
        }
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(renderHba)
      .catch(function (error) {
        if (!hbaHasData) {
          var message = !hbaAvailable ? 'HBA Viewer not installed' : 'HBA Viewer warming…';
          text('waz-hba-0-detail', message);
          text('waz-hba-1-detail', message);
        }
      })
      .then(function () {
        if (!stopped && hbaAvailable) hbaTimer = window.setTimeout(hbaLoop, hbaHasData ? 60000 : 5000);
      });
  }

  function snapshotLoop() {
    if (stopped) return;
    var started = Date.now();
    fetchJson(endpoint + '?section=snapshot&_=' + started)
      .then(renderSnapshot)
      .catch(function () {
        if (!lastSnapshotAt || Date.now() - lastSnapshotAt > 4000) {
          setFreshness('waz-is-stale', 'DATA STALE');
          text('waz-system-updated', 'Unable to read live snapshot');
        }
      })
      .then(function () {
        if (!stopped) snapshotTimer = window.setTimeout(snapshotLoop, Math.max(100, 1000 - (Date.now() - started)));
      });
  }

  window.WAZSystem = {
    version: root.getAttribute('data-version'),
    destroy: function () {
      stopped = true;
      if (snapshotTimer) window.clearTimeout(snapshotTimer);
      if (hbaTimer) window.clearTimeout(hbaTimer);
    },
    refresh: function () { if (!stopped) snapshotLoop(); }
  };

  snapshotLoop();
  hbaLoop();
})();
