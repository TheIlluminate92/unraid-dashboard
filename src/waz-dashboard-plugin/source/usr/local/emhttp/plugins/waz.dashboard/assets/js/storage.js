(function () {
  'use strict';

  if (window.WAZStorage && typeof window.WAZStorage.destroy === 'function') {
    window.WAZStorage.destroy();
  }

  var root = document.querySelector('#waz-storage-tile .waz-storage');
  if (!root) return;

  var endpoint = '/plugins/waz.dashboard/include/storage.php';
  var selectionKey = 'waz.dashboard.storage.selectedPool';
  var stopped = false;
  var timer = null;
  var resizeObserver = null;
  var lastData = null;
  var selectedDisk = '';
  var previousParity = null;
  var selectedPool = readSelection();

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

  function percent(value) {
    return finite(value) ? Math.round(clamp(value, 0, 100)) + '%' : '--';
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
    if (!finite(value)) return '--';
    return bytes(value) + '/s';
  }

  function temperature(value, sleeping) {
    return sleeping ? '*' : (finite(value) ? Math.round(value) + '°C' : '--');
  }

  function duration(seconds) {
    if (!finite(seconds) || seconds < 0) return '--';
    var total = Math.round(seconds);
    var days = Math.floor(total / 86400);
    var hours = Math.floor(total % 86400 / 3600);
    var minutes = Math.floor(total % 3600 / 60);
    if (days) return days + 'd ' + hours + 'h';
    if (hours) return hours + 'h ' + minutes + 'm';
    return minutes + 'm';
  }

  function dateTime(seconds) {
    if (!finite(seconds) || seconds <= 0) return 'Never';
    return new Date(seconds * 1000).toLocaleString([], {
      month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
    });
  }

  function stateClass(state) {
    return state === 'fault' ? ' waz-is-fault' : (state === 'attention' ? ' waz-is-attention' : '');
  }

  function identity(target, color) {
    if (target) target.style.setProperty('--disk-color', color || '#22b8f0');
  }

  function readSelection() {
    try { return window.localStorage.getItem(selectionKey) || 'cache'; } catch (error) { return 'cache'; }
  }

  function saveSelection(value) {
    selectedPool = value;
    try { window.localStorage.setItem(selectionKey, value); } catch (error) { }
  }

  function diskLocation(disk) {
    return disk && disk.location ? disk.location.groupName.replace('MD1200 ', '') + '-' + disk.location.tray : 'Unmapped';
  }

  function diskLink(disk) {
    var link = document.createElement('a');
    link.href = '/Main/Device?name=' + encodeURIComponent(disk.name);
    var dot = document.createElement('i');
    dot.className = 'waz-disk-state' + stateClass(disk.state) + (disk.spunDown ? ' waz-is-sleeping' : '');
    link.appendChild(dot);
    link.appendChild(document.createTextNode(disk.name.replace(/^disk/i, 'Disk ')));
    link.title = disk.name + ' · ' + disk.device + ' · ' + disk.mediaType;
    return link;
  }

  function setHealth(state, label) {
    var target = node('waz-storage-health');
    if (!target) return;
    target.className = 'waz-storage-health' + (state === 'fault' ? ' waz-is-fault' : (state === 'attention' ? ' waz-is-attention' : (state === 'loading' ? ' waz-is-loading' : '')));
    target.textContent = '';
    target.appendChild(document.createElement('i'));
    target.appendChild(document.createTextNode(' ' + label));
  }

  function renderAttention(attention, state) {
    var target = node('waz-storage-alert');
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
    target.className = 'waz-storage-alert' + (state === 'fault' ? ' waz-is-fault' : '');
    target.textContent = (state === 'fault' ? 'FAULT · ' : 'ATTENTION · ') + shown.join(' · ');
    target.title = messages.join(' · ');
  }

  function renderParity(parity, sampledAt) {
    parity = parity || {};
    var target = node('waz-parity-disks');
    if (target) {
      target.textContent = '';
      (parity.disks || []).forEach(function (disk, index) {
        var card = document.createElement('div');
        card.className = 'waz-parity-disk' + stateClass(disk.state) + (selectedDisk === disk.name ? ' waz-is-selected' : '');
        identity(card, disk.identityColor || '#e55454');
        card.dataset.disk = disk.name;
        var label = document.createElement('b');
        label.textContent = 'Parity ' + (index + 1) + ' · ' + temperature(number(disk.temperatureC), disk.spunDown);
        var detail = document.createElement('small');
        detail.textContent = diskLocation(disk) + ' · ' + disk.statusLabel;
        card.appendChild(label);
        card.appendChild(detail);
        target.appendChild(card);
      });
    }

    var progress = number(parity.progressPercent);
    var bar = node('waz-parity-progress');
    if (bar) bar.style.width = (parity.active && finite(progress) ? clamp(progress, 0, 100) : (parity.valid ? 100 : 0)) + '%';
    var unconfigured = parity.configured === false && !parity.active;
    text('waz-parity-state', unconfigured ? 'NOT CONFIGURED' : (parity.active ? 'CHECKING' : (parity.state === 'fault' ? 'FAULT' : (parity.cancelled ? 'CANCELLED' : (parity.valid ? 'VALID' : 'ATTENTION')))));

    if (unconfigured) {
      text('waz-parity-summary', 'No parity disks assigned');
      text('waz-parity-detail', '');
      previousParity = null;
      return;
    } else if (parity.active) {
      var speed = null;
      var eta = null;
      if (previousParity && finite(sampledAt) && sampledAt > previousParity.sampledAt) {
        var elapsed = (sampledAt - previousParity.sampledAt) / 1000;
        var advanced = number(parity.positionBytes) - previousParity.positionBytes;
        if (elapsed > 0 && advanced >= 0) {
          speed = advanced / elapsed;
          eta = speed > 0 ? (number(parity.sizeBytes) - number(parity.positionBytes)) / speed : null;
        }
      }
      text('waz-parity-summary', String(parity.action || 'Parity check').toUpperCase() + ' · ' + percent(progress));
      text('waz-parity-detail', rate(speed) + ' · ETA ' + duration(eta) + ' · ' + Number(parity.errors || 0) + ' errors');
    } else {
      text('waz-parity-summary', (parity.cancelled ? 'CANCELLED PARITY · ' : 'LAST PARITY · ') + dateTime(number(parity.lastFinishedAt)) + ' · ' + duration(number(parity.lastDurationSeconds)) + ' · ' + Number(parity.errors || 0) + ' errors');
      text('waz-parity-detail', 'NEXT · ' + (number(parity.nextScheduledAt) ? dateTime(number(parity.nextScheduledAt)) : 'Not scheduled'));
    }
    previousParity = { sampledAt: sampledAt, positionBytes: number(parity.positionBytes) || 0 };
  }

  function poolByName(pools, name) {
    return pools.filter(function (pool) { return pool.name === name; })[0] || null;
  }

  function selectPool(name) {
    saveSelection(name);
    if (lastData) renderPools(lastData.pools || []);
  }

  function renderPoolMember(disk) {
    var card = document.createElement('div');
    card.className = 'waz-pool-member' + stateClass(disk.state) + (selectedDisk === disk.name ? ' waz-is-selected' : '');
    card.dataset.disk = disk.name;
    identity(card, disk.identityColor);
    var label = document.createElement('b');
    label.textContent = disk.name + ' · ' + temperature(number(disk.temperatureC), disk.spunDown);
    var detail = document.createElement('small');
    detail.textContent = disk.mediaType + ' · ' + diskLocation(disk) + ' · ' + disk.statusLabel;
    card.appendChild(label);
    card.appendChild(detail);
    return card;
  }

  function renderPools(pools) {
    var tabs = node('waz-pool-tabs');
    var members = node('waz-pool-members');
    pools = Array.isArray(pools) ? pools : [];
    text('waz-pool-count', pools.length + ' POOLS');
    if (!poolByName(pools, selectedPool)) saveSelection(poolByName(pools, 'cache') ? 'cache' : (pools[0] ? pools[0].name : ''));
    var selected = poolByName(pools, selectedPool);

    if (tabs) {
      tabs.textContent = '';
      pools.forEach(function (pool) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'waz-pool-tab' + stateClass(pool.state) + (pool.name === selectedPool ? ' waz-selected' : '');
        button.dataset.pool = pool.name;
        identity(button, pool.identityColor);
        var label = document.createElement('b');
        label.textContent = pool.label.toUpperCase();
        var value = document.createElement('strong');
        value.textContent = percent(number(pool.usagePercent));
        var detail = document.createElement('small');
        detail.textContent = pool.memberCount + ' disk' + (pool.memberCount === 1 ? '' : 's') + ' · ' + (pool.profile || pool.fileSystem || '--');
        button.appendChild(label);
        button.appendChild(value);
        button.appendChild(detail);
        tabs.appendChild(button);
      });
    }

    if (!selected) {
      text('waz-pool-title', 'No pools detected');
      text('waz-pool-usage', '--');
      if (members) members.textContent = '';
      return;
    }
    var detailRoot = node('waz-pool-detail');
    identity(detailRoot, selected.identityColor);
    text('waz-pool-title', selected.label.toUpperCase() + ' · ' + bytes(number(selected.usedBytes)) + ' / ' + bytes(number(selected.sizeBytes)));
    text('waz-pool-usage', percent(number(selected.usagePercent)));
    var bar = node('waz-pool-bar');
    if (bar) bar.style.width = (finite(number(selected.usagePercent)) ? clamp(number(selected.usagePercent), 0, 100) : 0) + '%';
    if (members) {
      members.textContent = '';
      (selected.members || []).forEach(function (disk) { members.appendChild(renderPoolMember(disk)); });
    }
  }

  var selectedBay = '';
  var enclosureScroll = Object.create(null);
  var enclosureKey = 'waz.dashboard.storage.enclosures.v1';
  var enclosureOpen = Object.create(null);
  try {
    var savedEnclosures = JSON.parse(window.localStorage.getItem(enclosureKey));
    if (savedEnclosures && typeof savedEnclosures === 'object' && !Array.isArray(savedEnclosures)) {
      Object.keys(savedEnclosures).forEach(function (id) { if (typeof savedEnclosures[id] === 'boolean') enclosureOpen[id] = savedEnclosures[id]; });
    }
  } catch (_) {}
  function poolLabel(name) { return String(name || '').replace(/[-_]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
  function bayRole(cell) {
    if (cell.disk) return cell.disk.poolName ? poolLabel(cell.disk.poolName) : cell.disk.name.replace(/^disk/i, 'Disk ');
    return cell.present ? 'Unassigned' : 'Empty';
  }
  function detailField(target, label, value) {
    var row = document.createElement('div'), title = document.createElement('dt'), text = document.createElement('dd');
    title.textContent = label; text.textContent = value == null || value === '' ? 'Unavailable' : String(value);
    row.appendChild(title); row.appendChild(text); target.appendChild(row);
  }
  function renderBayDetails(group, cell) {
    var drawer = document.createElement('section'); drawer.className = 'waz-bay-details';
    drawer.setAttribute('aria-label', group.name + ' bay ' + cell.tray + ' details');
    var heading = document.createElement('h3'); heading.textContent = 'Bay ' + cell.tray + ' · ' + bayRole(cell);
    var close = document.createElement('button'); close.type = 'button'; close.textContent = 'Close details';
    close.dataset.bayKey = selectedBay;
    close.addEventListener('click', function () { selectedBay = ''; if (lastData) renderLocations(lastData.locations || {}); });
    drawer.appendChild(heading); drawer.appendChild(close);
    var fields = document.createElement('dl'), disk = cell.disk || {}, inventory = cell.inventory || {};
    detailField(fields, 'Status', cell.disk ? (disk.spunDown ? 'Standby' : String(disk.state || 'unknown')) : (cell.present ? 'Unassigned drive' : 'Empty bay'));
    if (cell.present || cell.disk) {
      detailField(fields, 'Drive', disk.name || 'Not assigned to array/pool');
      detailField(fields, 'Temperature', disk.spunDown ? 'Standby' : temperature(number(disk.temperatureC), false));
      detailField(fields, 'Capacity', bytes(number(inventory.capacityBytes || disk.sizeBytes)));
      detailField(fields, 'Model', inventory.model); detailField(fields, 'Serial', inventory.serial);
      if (disk.usagePercent != null) detailField(fields, 'Used', percent(number(disk.usagePercent)) + ' · ' + bytes(number(disk.usedBytes)));
      if (disk.device) detailField(fields, 'Device', disk.device);
    }
    drawer.appendChild(fields); return drawer;
  }
  function renderLocations(locations) {
    var target = node('waz-location-groups'); if (!target) return;
    var focused = document.activeElement && document.activeElement.dataset ? document.activeElement.dataset.bayKey : '';
    var focusedEnclosure = document.activeElement && document.activeElement.dataset ? document.activeElement.dataset.enclosureKey : '';
    Array.prototype.forEach.call(target.children, function (previous) {
      if (previous.dataset.enclosureId != null && previous.children[1]) enclosureScroll[previous.dataset.enclosureId] = previous.children[1].scrollLeft || 0;
    });
    target.textContent = ''; var occupied = 0, usable = 0, focusTarget = null;
    (locations.groups || []).forEach(function (group) {
      var wrapper = document.createElement('details'); wrapper.className = 'waz-location-group';
      wrapper.dataset.enclosureId = String(group.id);
      wrapper.open = enclosureOpen[group.id] !== false;
      wrapper.addEventListener('toggle', function () {
        enclosureOpen[group.id] = wrapper.open;
        try { window.localStorage.setItem(enclosureKey, JSON.stringify(enclosureOpen)); } catch (_) {}
      });
      var title = document.createElement('summary'); title.textContent = group.name; title.dataset.enclosureKey = String(group.id); wrapper.appendChild(title);
      if (focusedEnclosure === String(group.id)) focusTarget = title;
      var scroll = document.createElement('div'); scroll.className = 'waz-enclosure-scroll';
      var grid = document.createElement('div'); grid.className = 'waz-location-grid';
      grid.dataset.trayDirection = group.trayDirection || 'h';
      grid.style.setProperty('--columns', Number(group.columns) || 1);
      var chosen = null;
      (group.cells || []).forEach(function (cell) {
        var key = String(group.id) + ':' + cell.sourceTray;
        var bay = document.createElement('button'); bay.type = 'button'; bay.className = 'waz-bay'; bay.dataset.bayKey = key;
        if (cell.hidden) { bay.className += ' waz-hidden'; bay.disabled = true; }
        else { usable++; if (cell.disk || cell.present) occupied++; }
        var label = document.createElement('b'); label.textContent = String(cell.tray);
        var role = document.createElement('small'); role.textContent = bayRole(cell);
        if (cell.disk) { bay.className += stateClass(cell.disk.state); identity(bay, cell.disk.identityColor); }
        else bay.className += ' waz-empty';
        var health = cell.disk ? String(cell.disk.state || 'unknown') : (cell.present ? 'unassigned' : 'empty');
        var healthLabel = document.createElement('span'); healthLabel.className = 'waz-bay-state';
        healthLabel.textContent = health === 'normal' ? 'OK' : (health === 'attention' ? 'Warning' : health === 'fault' ? 'Fault' : health);
        bay.setAttribute('aria-label', group.name + ', bay ' + cell.tray + ', ' + bayRole(cell) + ', ' + health);
        bay.setAttribute('aria-pressed', String(selectedBay === key));
        bay.title = 'Bay ' + cell.tray + ' · ' + bayRole(cell) + ' · ' + health;
        if (selectedBay === key) { bay.className += ' waz-is-selected'; chosen = cell; }
        if (focused === key) focusTarget = bay;
        bay.addEventListener('click', function () {
          selectedBay = selectedBay === key ? '' : key;
          if (cell.disk) { selectedDisk = cell.disk.name; if (cell.disk.poolName) saveSelection(cell.disk.poolName); }
          if (lastData) renderLocations(lastData.locations || {});
        });
        bay.appendChild(label); bay.appendChild(role); bay.appendChild(healthLabel); grid.appendChild(bay);
      });
      scroll.appendChild(grid); wrapper.appendChild(scroll);
      if (chosen) wrapper.appendChild(renderBayDetails(group, chosen));
      target.appendChild(wrapper);
      scroll.scrollLeft = enclosureScroll[group.id] || 0;
    });
    if (focusTarget && focusTarget.focus) focusTarget.focus({ preventScroll: true });
    text('waz-location-count', occupied + ' / ' + usable + ' OCCUPIED');
  }

  function renderArrayDisk(disk) {
    var row = document.createElement('div');
    row.className = 'waz-array-disk' + stateClass(disk.state) + (selectedDisk === disk.name ? ' waz-is-selected' : '');
    row.dataset.disk = disk.name;
    identity(row, disk.identityColor);
    row.appendChild(diskLink(disk));
    var temp = document.createElement('span');
    temp.className = 'waz-array-temp';
    temp.textContent = temperature(number(disk.temperatureC), disk.spunDown);
    var usage = document.createElement('span');
    usage.className = 'waz-array-usage';
    var usageValue = document.createElement('span');
    usageValue.textContent = percent(number(disk.usagePercent));
    var meter = document.createElement('span');
    meter.className = 'waz-disk-meter';
    var fill = document.createElement('i');
    fill.style.width = (finite(number(disk.usagePercent)) ? clamp(number(disk.usagePercent), 0, 100) : 0) + '%';
    meter.appendChild(fill);
    usage.appendChild(usageValue);
    usage.appendChild(meter);
    row.appendChild(temp);
    row.appendChild(usage);
    return row;
  }

  function renderArray(array) {
    array = array || {};
    var section = node('waz-array-section');
    if (section) section.hidden = array.configured === false;
    var usage = number(array.usagePercent);
    text('waz-array-summary', array.diskCount + ' DISKS · ' + bytes(number(array.usedBytes)) + ' / ' + bytes(number(array.sizeBytes)) + ' · ' + percent(usage));
    var bar = node('waz-array-bar');
    if (bar) bar.style.width = (finite(usage) ? clamp(usage, 0, 100) : 0) + '%';
    var target = node('waz-array-disks');
    if (!target) return;
    target.textContent = '';
    var disks = Array.isArray(array.disks) ? array.disks : [];
    var split = Math.ceil(disks.length / 2);
    [disks.slice(0, split), disks.slice(split)].forEach(function (items) {
      var column = document.createElement('div');
      column.className = 'waz-array-column';
      items.forEach(function (disk) { column.appendChild(renderArrayDisk(disk)); });
      target.appendChild(column);
    });
  }

  function selectDisk(name, pool) {
    selectedDisk = name || '';
    if (pool) saveSelection(pool);
    if (lastData) renderSnapshot(lastData, true);
  }

  function renderSnapshot(data, localOnly) {
    lastData = data;
    var sampledAt = Number(data.sampledAtMs) || Date.now();
    renderParity(data.parity || {}, sampledAt);
    renderPools(data.pools || []);
    renderLocations(data.locations || { groups: [] });
    renderArray(data.array || {});
    setHealth(data.state || 'normal', data.state === 'fault' ? 'FAULT' : (data.state === 'attention' ? 'ATTENTION' : 'NORMAL'));
    renderAttention(data.attention || {}, data.state || 'normal');
    text('waz-storage-subtitle', (data.array && data.array.configured === false ? 'Pool-only storage' : (data.array && data.array.mdState === 'STARTED' ? 'Array started' : 'Array ' + ((data.array && data.array.mdState) || 'unknown'))) + ' · ' + (data.pools || []).length + ' pools');
    if (!localOnly) text('waz-storage-updated', 'Updated ' + new Date(sampledAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
  }

  function handleClick(event) {
    var pool = event.target.closest('.waz-pool-tab');
    if (pool && root.contains(pool)) {
      selectPool(pool.dataset.pool || '');
      return;
    }
    var disk = event.target.closest('[data-disk]');
    if (disk && root.contains(disk) && event.target.tagName.toLowerCase() !== 'a') {
      selectDisk(disk.dataset.disk || '', disk.dataset.pool || '');
    }
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

  function storageLoop() {
    if (stopped) return;
    var started = Date.now();
    fetch(endpoint + '?_=' + started, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (data) {
        if (data.error) throw new Error(data.error);
        renderSnapshot(data, false);
      })
      .catch(function () {
        setHealth('fault', 'DATA STALE');
        renderAttention({ messages: ['Storage telemetry is unavailable'] }, 'fault');
        text('waz-storage-updated', 'Unable to read storage snapshot');
      })
      .then(function () {
        syncHeight();
        if (!stopped) timer = window.setTimeout(storageLoop, Math.max(500, 5000 - (Date.now() - started)));
      });
  }

  root.addEventListener('click', handleClick);
  window.addEventListener('resize', syncHeight);
  var system = document.querySelector('#waz-system-tile .waz-system');
  if (window.ResizeObserver && system) {
    resizeObserver = new ResizeObserver(syncHeight);
    resizeObserver.observe(system);
  }

  window.WAZStorage = {
    version: root.getAttribute('data-version'),
    destroy: function () {
      stopped = true;
      if (timer) window.clearTimeout(timer);
      if (resizeObserver) resizeObserver.disconnect();
      root.removeEventListener('click', handleClick);
      window.removeEventListener('resize', syncHeight);
    },
    refresh: function () { if (!stopped) storageLoop(); }
  };

  window.setTimeout(syncHeight, 0);
  storageLoop();
})();
