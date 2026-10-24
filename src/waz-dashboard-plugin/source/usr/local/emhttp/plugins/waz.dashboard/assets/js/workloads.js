(function () {
  'use strict';

  if (window.WAZWorkloads && typeof window.WAZWorkloads.destroy === 'function') {
    window.WAZWorkloads.destroy();
  }

  var root = document.querySelector('#waz-workloads-tile .waz-workloads');
  if (!root) return;

  var endpoint = '/plugins/waz.dashboard/include/workloads.php';
  var folderKey = 'waz.dashboard.workloads.selectedFolder';
  var containerKey = 'waz.dashboard.workloads.selectedContainer';
  var stopped = false;
  var inFlight = false;
  var timer = null;
  var resizeObserver = null;
  var nativeLoadlist = typeof window.loadlist === 'function' ? window.loadlist : null;
  var lastData = null;
  var selectedFolder = readStorage(folderKey, 'all');
  var selectedContainer = readStorage(containerKey, '');
  var previousCounters = {};
  var histories = {};
  var MAX_HISTORY = 60;

  function node(id) { return document.getElementById(id); }

  function text(id, value) {
    var target = node(id);
    if (target) target.textContent = value;
  }

  function finite(value) {
    return typeof value === 'number' && isFinite(value);
  }

  function number(value) {
    if (value === null || value === undefined || value === '') return null;
    var converted = Number(value);
    return finite(converted) ? converted : null;
  }

  function clamp(value, minimum, maximum) {
    return Math.max(minimum, Math.min(maximum, value));
  }

  function percent(value, digits) {
    if (!finite(value)) return '--';
    var decimals = digits === undefined ? (Math.abs(value) < 10 && value !== 0 ? 1 : 0) : digits;
    return Math.max(0, value).toFixed(decimals) + '%';
  }

  function bytes(value) {
    if (!finite(value)) return '--';
    var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    var amount = Math.max(0, value);
    var unit = 0;
    while (amount >= 1000 && unit < units.length - 1) {
      amount /= 1000;
      unit += 1;
    }
    var digits = amount >= 100 || unit === 0 ? 0 : amount >= 10 ? 1 : 2;
    return amount.toFixed(digits) + ' ' + units[unit];
  }

  function rate(value) {
    return finite(value) ? bytes(value) + '/s' : '--';
  }

  function duration(seconds) {
    if (!finite(seconds) || seconds < 0) return '--';
    var total = Math.round(seconds);
    var days = Math.floor(total / 86400);
    var hours = Math.floor(total % 86400 / 3600);
    var minutes = Math.floor(total % 3600 / 60);
    if (days) return days + 'd ' + hours + 'h';
    if (hours) return hours + 'h ' + minutes + 'm';
    if (minutes) return minutes + 'm';
    return total + 's';
  }

  function readStorage(key, fallback) {
    try { return window.localStorage.getItem(key) || fallback; } catch (error) { return fallback; }
  }

  function writeStorage(key, value) {
    try { window.localStorage.setItem(key, value); } catch (error) { }
  }

  function setHealth(state, label) {
    var target = node('waz-workloads-health');
    if (!target) return;
    target.className = 'waz-workloads-health' + (state === 'fault' ? ' waz-is-fault' : (state === 'attention' ? ' waz-is-attention' : (state === 'loading' ? ' waz-is-loading' : '')));
    target.textContent = '';
    target.appendChild(document.createElement('i'));
    target.appendChild(document.createTextNode(' ' + label));
  }

  function renderAttention(attention, state) {
    var target = node('waz-workloads-alert');
    if (!target) return;
    var messages = attention && Array.isArray(attention.messages) ? attention.messages.filter(Boolean) : [];
    if (!messages.length || state === 'normal') {
      target.hidden = true;
      target.textContent = '';
      target.removeAttribute('title');
      return;
    }
    var shown = messages.slice(0, 3);
    if (messages.length > shown.length) shown.push('+' + (messages.length - shown.length) + ' more');
    target.hidden = false;
    target.className = 'waz-workloads-alert' + (state === 'fault' ? ' waz-is-fault' : '');
    target.textContent = (state === 'fault' ? 'FAULT · ' : 'ATTENTION · ') + shown.join(' · ');
    target.title = messages.join(' · ');
  }

  function removeNativeTiles() {
    var nativeSystem = document.querySelector('#db_box1 > tbody.system');
    if (nativeSystem && !nativeSystem.contains(root)) nativeSystem.remove();
    var nativeDocker = document.getElementById('docker_view');
    if (nativeDocker && !nativeDocker.contains(root)) nativeDocker.remove();
  }

  function containerByName(name) {
    var containers = lastData && Array.isArray(lastData.containers) ? lastData.containers : [];
    for (var index = 0; index < containers.length; index += 1) {
      if (containers[index].name === name) return containers[index];
    }
    return null;
  }

  function folderById(id) {
    var folders = lastData && Array.isArray(lastData.folders) ? lastData.folders : [];
    for (var index = 0; index < folders.length; index += 1) {
      if (folders[index].id === id) return folders[index];
    }
    return null;
  }

  function renderFolders(folders) {
    folders = Array.isArray(folders) ? folders : [];
    if (!folders.some(function (folder) { return folder.id === selectedFolder; })) selectedFolder = 'all';
    var target = node('waz-folder-tabs');
    text('waz-folder-count', Math.max(0, folders.length - 1) + ' FOLDERS');
    if (!target) return;
    target.textContent = '';
    folders.forEach(function (folder) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'waz-folder-tab' + (folder.id === selectedFolder ? ' waz-selected' : '');
      button.dataset.folder = folder.id;
      button.style.setProperty('--folder-color', folder.color || '#22b8f0');
      var visual;
      if (folder.icon) {
        visual = document.createElement('img');
        visual.src = folder.icon;
        visual.alt = '';
        visual.addEventListener('error', function () {
          var fallback = document.createElement('span');
          fallback.className = 'waz-folder-fallback';
          fallback.innerHTML = '<i class="fa fa-folder-open"></i>';
          visual.replaceWith(fallback);
        }, { once: true });
      } else {
        visual = document.createElement('span');
        visual.className = 'waz-folder-fallback';
        visual.innerHTML = '<i class="fa fa-folder-open"></i>';
      }
      var copy = document.createElement('span');
      var label = document.createElement('b');
      label.textContent = String(folder.name || 'Folder').toUpperCase();
      var detail = document.createElement('small');
      detail.textContent = Number(folder.running || 0) + ' / ' + Number(folder.total || 0) + ' running';
      copy.appendChild(label);
      copy.appendChild(detail);
      button.appendChild(visual);
      button.appendChild(copy);
      target.appendChild(button);
    });
  }

  function iconElement(container) {
    var wrapper = document.createElement('span');
    wrapper.className = 'waz-container-icon';
    var image = document.createElement('img');
    image.src = container.icon || '/plugins/dynamix.docker.manager/images/question.png';
    image.alt = '';
    image.addEventListener('error', function () {
      image.src = '/plugins/dynamix.docker.manager/images/question.png';
    }, { once: true });
    var state = document.createElement('i');
    state.className = 'waz-container-state';
    wrapper.appendChild(image);
    wrapper.appendChild(state);
    return wrapper;
  }

  function renderContainers() {
    var folder = folderById(selectedFolder) || folderById('all');
    var names = folder && Array.isArray(folder.containers) ? folder.containers : [];
    var containers = names.map(containerByName).filter(Boolean);
    var target = node('waz-container-grid');
    text('waz-container-section-title', (folder ? String(folder.name).toUpperCase() : 'ALL') + ' CONTAINERS');
    text('waz-container-count', containers.length + ' SHOWN');
    if (!target) return;
    target.textContent = '';
    if (!containers.length) {
      var empty = document.createElement('span');
      empty.className = 'waz-workloads-muted';
      empty.textContent = 'No containers in this folder';
      target.appendChild(empty);
      return;
    }
    containers.forEach(function (container) {
      var card = document.createElement('div');
      card.id = container.id;
      card.className = 'waz-container-card waz-' + container.state + (container.health === 'unhealthy' ? ' waz-unhealthy' : '') + (container.name === selectedContainer ? ' waz-selected' : '');
      card.dataset.container = container.name;
      card.title = container.name + ' · ' + container.status + ' · click for native controls';
      card.tabIndex = 0;
      card.setAttribute('role', 'button');
      card.appendChild(iconElement(container));
      var label = document.createElement('b');
      label.textContent = container.name;
      card.appendChild(label);
      if (container.gpuActive || container.videoActive || container.updateStatus === 1) {
        var badges = document.createElement('span');
        badges.className = 'waz-container-badges';
        if (container.gpuActive || container.videoActive) {
          var gpu = document.createElement('i');
          gpu.className = 'fa ' + (container.videoActive ? 'fa-film' : 'fa-bolt');
          gpu.title = container.videoActive ? 'Active GPU video client' : 'Active GPU client';
          badges.appendChild(gpu);
        }
        if (container.updateStatus === 1) {
          var update = document.createElement('i');
          update.className = 'fa fa-cloud-download waz-update';
          update.title = 'Update available';
          badges.appendChild(update);
        }
        card.appendChild(badges);
      }
      target.appendChild(card);
    });
  }

  function renderTopProcesses(processes) {
    var target = node('waz-top-processes');
    if (!target) return;
    target.textContent = '';
    processes = Array.isArray(processes) ? processes.slice(0, 4) : [];
    if (!processes.length) {
      var empty = document.createElement('span');
      empty.className = 'waz-workloads-muted';
      empty.textContent = 'No process data available';
      target.appendChild(empty);
      return;
    }
    processes.forEach(function (process) {
      var row = document.createElement('div');
      row.className = 'waz-process-row';
      row.title = process.command || process.name;
      var label = document.createElement('span');
      label.textContent = process.name + ' · ' + process.pid;
      var owner = document.createElement('span');
      owner.className = 'waz-process-owner';
      owner.textContent = process.container || 'HOST';
      var cpu = document.createElement('span');
      cpu.className = 'waz-process-cpu';
      cpu.textContent = percent(number(process.cpuPercent));
      var memory = document.createElement('span');
      memory.className = 'waz-process-memory';
      memory.textContent = bytes(number(process.memoryBytes));
      row.appendChild(label);
      row.appendChild(owner);
      row.appendChild(cpu);
      row.appendChild(memory);
      target.appendChild(row);
    });
  }

  function currentRates(selected) {
    var stats = selected && selected.stats || {};
    var key = selected && selected.name || '';
    var now = number(stats.sampledAtMs);
    var current = {
      sampledAtMs: now,
      networkRxBytes: number(stats.networkRxBytes) || 0,
      networkTxBytes: number(stats.networkTxBytes) || 0,
      blockReadBytes: number(stats.blockReadBytes) || 0,
      blockWriteBytes: number(stats.blockWriteBytes) || 0
    };
    var previous = previousCounters[key];
    previousCounters[key] = current;
    var result = { rx: null, tx: null, read: null, write: null };
    if (!previous || !finite(now) || !finite(previous.sampledAtMs)) return result;
    var seconds = (now - previous.sampledAtMs) / 1000;
    if (seconds <= 0 || seconds > 30) return result;
    result.rx = Math.max(0, current.networkRxBytes - previous.networkRxBytes) / seconds;
    result.tx = Math.max(0, current.networkTxBytes - previous.networkTxBytes) / seconds;
    result.read = Math.max(0, current.blockReadBytes - previous.blockReadBytes) / seconds;
    result.write = Math.max(0, current.blockWriteBytes - previous.blockWriteBytes) / seconds;
    return result;
  }

  function pushHistory(name, value) {
    if (!histories[name]) histories[name] = [];
    histories[name].push(finite(value) ? Math.max(0, value) : 0);
    if (histories[name].length > MAX_HISTORY) histories[name].shift();
  }

  function drawLine(id, values, fixedMaximum) {
    var line = node(id);
    if (!line) return;
    values = Array.isArray(values) && values.length ? values : [0];
    var maximum = fixedMaximum || Math.max.apply(Math, values.concat([1]));
    var divisor = Math.max(1, values.length - 1);
    line.setAttribute('points', values.map(function (value, index) {
      var x = index / divisor * 180;
      var y = 34 - clamp(value / maximum, 0, 1) * 32;
      return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' '));
  }

  function resetInspector() {
    text('waz-selected-name', 'SELECT A CONTAINER');
    text('waz-selected-state', 'Click an icon above for live details and native controls');
    text('waz-selected-badge', 'IDLE');
    ['cpu', 'memory', 'network', 'disk', 'gpu'].forEach(function (name) { text('waz-selected-' + name, '--'); });
    text('waz-selected-pools', '--');
    text('waz-selected-addresses', '--');
    text('waz-selected-detail', '--');
    var icon = node('waz-selected-icon');
    if (icon) icon.innerHTML = '<i class="fa fa-cube"></i>';
    var badge = node('waz-selected-badge');
    if (badge) badge.className = 'waz-selected-badge';
  }

  function renderInspector(selected) {
    if (!selected) {
      resetInspector();
      return;
    }
    var stats = selected.stats || {};
    var rates = currentRates(selected);
    var icon = node('waz-selected-icon');
    if (icon) {
      icon.textContent = '';
      var image = document.createElement('img');
      image.src = selected.icon || '/plugins/dynamix.docker.manager/images/question.png';
      image.alt = '';
      icon.appendChild(image);
    }
    text('waz-selected-name', String(selected.name).toUpperCase());
    text('waz-selected-state', selected.image + ' · ' + selected.status);
    text('waz-selected-badge', selected.health === 'unhealthy' ? 'UNHEALTHY' : selected.state.toUpperCase());
    var badge = node('waz-selected-badge');
    if (badge) badge.className = 'waz-selected-badge waz-' + (selected.health === 'unhealthy' ? 'unhealthy' : selected.state);
    text('waz-selected-cpu', stats.available ? percent(number(stats.cpuPercent)) : (selected.state === 'stopped' ? 'STOPPED' : '--'));
    text('waz-selected-memory', stats.available ? bytes(number(stats.memoryBytes)) + ' · ' + percent(number(stats.memoryPercent)) : '--');
    text('waz-selected-network', '↓ ' + rate(rates.rx) + ' · ↑ ' + rate(rates.tx));
    text('waz-selected-disk', 'R ' + rate(rates.read) + ' · W ' + rate(rates.write));
    var gpu = selected.gpu || {};
    text('waz-selected-gpu', gpu.active ? (percent(number(gpu.loadPercent)) + (gpu.videoActive ? ' · VIDEO ' + percent(number(gpu.videoLoadPercent)) : '')) : 'INACTIVE');

    var historyPrefix = selected.name + ':';
    pushHistory(historyPrefix + 'cpu', number(stats.cpuPercent));
    pushHistory(historyPrefix + 'network', (rates.rx || 0) + (rates.tx || 0));
    pushHistory(historyPrefix + 'disk', (rates.read || 0) + (rates.write || 0));
    drawLine('waz-selected-cpu-line', histories[historyPrefix + 'cpu'], 100);
    drawLine('waz-selected-network-line', histories[historyPrefix + 'network']);
    drawLine('waz-selected-disk-line', histories[historyPrefix + 'disk']);

    var pools = selected.storage && Array.isArray(selected.storage.pools) ? selected.storage.pools : [];
    text('waz-selected-pools', pools.length ? pools.join(' · ') : 'No host mounts');
    var addresses = Array.isArray(selected.addresses) ? selected.addresses.slice() : [];
    if (Array.isArray(selected.ports) && selected.ports.length) addresses.push('ports ' + selected.ports.join(', '));
    text('waz-selected-addresses', addresses.length ? addresses.join(' · ') : 'No published address');
    var details = [];
    if (selected.health && selected.health !== 'none') details.push('Health ' + selected.health);
    if (finite(number(selected.uptimeSeconds))) details.push('Up ' + duration(number(selected.uptimeSeconds)));
    details.push(Number(selected.restartCount || 0) + ' restarts');
    if (stats.processCount) details.push(stats.processCount + ' PIDs');
    text('waz-selected-detail', details.join(' · '));
  }

  function renderSummary(data) {
    var summary = data.summary || {};
    text('waz-workloads-running', Number(summary.running || 0) + ' / ' + Number(summary.total || 0));
    var docker = summary.dockerVdisk || {};
    text('waz-workloads-docker', docker.available ? bytes(number(docker.usedBytes)) + ' / ' + bytes(number(docker.totalBytes)) : 'N/A');
    text('waz-workloads-updates', String(Number(summary.updates || 0)));
    text('waz-workloads-gpu', String(Number(summary.gpuContainers || 0)));
  }

  function renderSnapshot(data) {
    lastData = data;
    if (!containerByName(selectedContainer)) {
      selectedContainer = '';
      writeStorage(containerKey, '');
    }
    renderSummary(data);
    renderFolders(data.folders || []);
    renderContainers();
    renderTopProcesses(data.topProcesses || []);
    renderInspector(data.selected && data.selected.name === selectedContainer ? data.selected : null);
    setHealth(data.state || 'normal', data.state === 'attention' ? 'ATTENTION' : 'NORMAL');
    renderAttention(data.attention || {}, data.state || 'normal');
    text('waz-workloads-subtitle', Number((data.summary || {}).running || 0) + ' running · click an icon for controls');
    text('waz-workloads-updated', 'Updated ' + new Date(Number(data.sampledAtMs) || Date.now()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
  }

  function openNativeMenu(container) {
    if (typeof window.addDockerContainerContext !== 'function') {
      window.location.href = '/Docker';
      return;
    }
    var menu = container.menu || {};
    window.addDockerContainerContext(
      container.name,
      container.imageId,
      menu.template || '',
      container.state === 'stopped' ? 0 : 1,
      container.state === 'paused' ? 1 : 0,
      Number(container.updateStatus || 3),
      container.autostart ? 1 : 0,
      menu.webUi || '',
      menu.tailscaleWebUi || '',
      menu.shell || '',
      container.id,
      menu.support || '',
      menu.project || '',
      menu.registry || '',
      menu.donate || '',
      menu.readme || ''
    );
  }

  function selectContainer(name, openMenu) {
    var container = containerByName(name);
    if (!container) return;
    selectedContainer = name;
    writeStorage(containerKey, name);
    renderContainers();
    text('waz-selected-name', name.toUpperCase());
    text('waz-selected-state', 'Loading live telemetry…');
    if (openMenu) openNativeMenu(container);
    schedule(0);
  }

  function handleClick(event) {
    var folder = event.target.closest('.waz-folder-tab');
    if (folder && root.contains(folder)) {
      selectedFolder = folder.dataset.folder || 'all';
      writeStorage(folderKey, selectedFolder);
      renderFolders(lastData ? lastData.folders : []);
      renderContainers();
      return;
    }
    var card = event.target.closest('.waz-container-card');
    if (card && root.contains(card)) selectContainer(card.dataset.container || '', true);
  }

  function handleKeydown(event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    var card = event.target.closest('.waz-container-card');
    if (!card || !root.contains(card)) return;
    event.preventDefault();
    selectContainer(card.dataset.container || '', true);
  }

  function syncHeight() {
    if (root.hasAttribute("data-dashboard-layout")) { root.style.height = ""; return; }
    var system = document.querySelector('#waz-system-tile .waz-system');
    if (!system || window.innerWidth <= 620) {
      root.style.height = '';
      return;
    }
    root.style.height = Math.ceil(system.getBoundingClientRect().height) + 'px';
  }

  function schedule(delay) {
    if (stopped) return;
    if (timer) window.clearTimeout(timer);
    timer = window.setTimeout(workloadsLoop, delay);
  }

  function workloadsLoop() {
    if (stopped || inFlight) return;
    inFlight = true;
    var started = Date.now();
    var query = selectedContainer ? '&selected=' + encodeURIComponent(selectedContainer) : '';
    fetch(endpoint + '?_=' + started + query, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (data) {
        if (data.error) throw new Error(data.error);
        renderSnapshot(data);
      })
      .catch(function () {
        setHealth('fault', 'DATA STALE');
        renderAttention({ messages: ['Docker telemetry is unavailable'] }, 'fault');
        text('waz-workloads-updated', 'Unable to read Docker snapshot');
      })
      .then(function () {
        inFlight = false;
        syncHeight();
        schedule(Math.max(500, 5000 - (Date.now() - started)));
      });
  }

  removeNativeTiles();
  root.addEventListener('click', handleClick);
  root.addEventListener('keydown', handleKeydown);
  window.addEventListener('resize', syncHeight);
  var system = document.querySelector('#waz-system-tile .waz-system');
  if (window.ResizeObserver && system) {
    resizeObserver = new ResizeObserver(syncHeight);
    resizeObserver.observe(system);
  }
  window.loadlist = function () {
    if (nativeLoadlist) nativeLoadlist.apply(window, arguments);
    window.setTimeout(removeNativeTiles, 250);
    schedule(0);
  };

  window.WAZWorkloads = {
    version: root.getAttribute('data-version'),
    destroy: function () {
      stopped = true;
      if (timer) window.clearTimeout(timer);
      if (resizeObserver) resizeObserver.disconnect();
      root.removeEventListener('click', handleClick);
      root.removeEventListener('keydown', handleKeydown);
      window.removeEventListener('resize', syncHeight);
      if (nativeLoadlist) window.loadlist = nativeLoadlist;
      else delete window.loadlist;
    },
    refresh: function () { schedule(0); },
    select: function (name) { selectContainer(name, false); }
  };

  window.setTimeout(syncHeight, 0);
  workloadsLoop();
})();
