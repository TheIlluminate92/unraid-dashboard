(function () {
  'use strict';
  if (window.WAZDashboardLayout) { window.WAZDashboardLayout.start(); return; }
  var storageKey = 'waz.dashboard.statusLayout.v1';
  var groups = { summary: ['cpu', 'gpu', 'power', 'memory', 'coolant', 'hba0', 'network', 'flow', 'hba1'], sections: ['cpu', 'memory', 'network'] };
  function clamp(value, low, high, fallback) {
    value = Number(value);
    return Number.isFinite(value) ? Math.max(low, Math.min(high, Math.round(value))) : fallback;
  }
  function normalize(raw, definitions, defaults) {
    definitions = definitions || groups;
    defaults = defaults || {};
    var result = {};
    Object.keys(definitions).forEach(function (group) {
      var entries = raw && typeof raw[group] === 'object' && raw[group] || {};
      result[group] = {};
      definitions[group].forEach(function (id, index) {
        var item = entries[id] || {};
        result[group][id] = {
          order: clamp(item.order, 0, 50, index),
          columns: clamp(item.columns, group === 'summary' ? 2 : 4, 12, defaults[group] || (group === 'summary' ? 4 : 12)),
          height: item.height == null ? null : clamp(item.height, group === 'summary' ? 56 : 120, 2400, null)
        };
      });
    });
    return result;
  }
  function read(key, definitions, defaults) {
    try { return normalize(JSON.parse(window.localStorage.getItem(key)), definitions, defaults); }
    catch (_) { return normalize(null, definitions, defaults); }
  }
  function mount(root) {
    if (!root || root.dataset.layoutMounted) return;
    root.dataset.layoutMounted = 'yes';
    var containers = Array.prototype.slice.call(root.querySelectorAll('[data-layout-group]'));
    var definitions = {}, defaults = {}, key = root.dataset.layoutKey || storageKey;
    containers.forEach(function (container) {
      definitions[container.dataset.layoutGroup] = items(container).map(function (item) { return item.dataset.layoutItem; });
      defaults[container.dataset.layoutGroup] = clamp(container.dataset.layoutColumns, 2, 12, container.dataset.layoutGroup === 'summary' ? 4 : 12);
    });
    var state = read(key, definitions, defaults), editing = false, dragging = null;
    function save() { try { window.localStorage.setItem(key, JSON.stringify(state)); } catch (_) {} }
    function items(container) { return Array.prototype.filter.call(container.children, function (item) { return item.dataset.layoutItem; }); }
    function apply(container) {
      var group = container.dataset.layoutGroup;
      items(container).sort(function (a, b) { return state[group][a.dataset.layoutItem].order - state[group][b.dataset.layoutItem].order; }).forEach(function (item) {
        var size = state[group][item.dataset.layoutItem];
        item.style.gridColumn = 'span ' + size.columns;
        item.style.height = size.height == null ? '' : size.height + 'px';
        item.classList.toggle('waz-layout-sized', size.height != null);
        container.appendChild(item);
      });
    }
    function recordOrder(container) {
      items(container).forEach(function (item, i) { state[container.dataset.layoutGroup][item.dataset.layoutItem].order = i; });
      save();
    }
    function move(container, item, offset) {
      var visible = items(container).filter(function (x) { return !x.hidden; });
      var index = visible.indexOf(item), target = visible[index + offset];
      if (!target) return;
      container.insertBefore(item, offset < 0 ? target : target.nextSibling);
      recordOrder(container);
    }
    containers.forEach(function (container) {
      var group = container.dataset.layoutGroup;
      items(container).forEach(function (item) {
        var id = item.dataset.layoutItem, label = item.dataset.layoutLabel || id;
        var controls = document.createElement('div');
        controls.className = 'waz-layout-controls';
        controls.hidden = true;
        function button(text, title, action) {
          var b = document.createElement('button'); b.type = 'button'; b.textContent = text;
          b.title = title + ' ' + label; b.setAttribute('aria-label', b.title);
          b.addEventListener('click', function (e) { e.stopPropagation(); action(); });
          controls.appendChild(b); return b;
        }
        button('←', 'Move earlier:', function () { move(container, item, -1); });
        button('→', 'Move later:', function () { move(container, item, 1); });
        function resize(width, height) {
          var size = state[group][id];
          size.columns = clamp(size.columns + width, group === 'summary' ? 2 : 4, 12, size.columns);
          if (height) size.height = clamp((size.height || item.getBoundingClientRect().height) + height, group === 'summary' ? 56 : 120, 2400, null);
          apply(container); save();
        }
        button('−W', 'Narrower:', function () { resize(-1, 0); });
        button('+W', 'Wider:', function () { resize(1, 0); });
        button('−H', 'Shorter:', function () { resize(0, -24); });
        button('+H', 'Taller:', function () { resize(0, 24); });
        button('Auto', 'Reset size:', function () { state[group][id].columns = defaults[group]; state[group][id].height = null; apply(container); save(); });
        item.insertBefore(controls, item.firstChild);
        var grip = document.createElement('span'); grip.className = 'waz-layout-grip'; grip.hidden = true;
        grip.textContent = '↘'; grip.setAttribute('aria-hidden', 'true'); item.appendChild(grip);
        grip.addEventListener('pointerdown', function (e) {
          if (!editing) return;
          e.preventDefault(); e.stopPropagation();
          var startX = e.clientX, startY = e.clientY, rect = item.getBoundingClientRect();
          var columns = state[group][id].columns;
          var step = (container.getBoundingClientRect().width + 8) / 12;
          grip.setPointerCapture(e.pointerId);
          function update(event) {
            state[group][id].columns = clamp(columns + (event.clientX - startX) / step, group === 'summary' ? 2 : 4, 12, columns);
            state[group][id].height = clamp(rect.height + event.clientY - startY, group === 'summary' ? 56 : 120, 2400, null);
            item.style.gridColumn = 'span ' + state[group][id].columns;
            item.style.height = state[group][id].height + 'px';
            item.classList.add('waz-layout-sized');
          }
          function finish() { grip.removeEventListener('pointermove', update); grip.removeEventListener('pointerup', finish); grip.removeEventListener('pointercancel', finish); save(); }
          grip.addEventListener('pointermove', update); grip.addEventListener('pointerup', finish); grip.addEventListener('pointercancel', finish);
        });
        item.addEventListener('dragstart', function (e) {
          e.stopPropagation();
          if (!editing || e.target === grip || /^(BUTTON|INPUT|SELECT)$/.test(e.target.tagName)) { e.preventDefault(); return; }
          dragging = item; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', id);
        });
        item.addEventListener('dragover', function (e) { if (editing && dragging && dragging.parentElement === container) e.preventDefault(); });
        item.addEventListener('drop', function (e) {
          if (!editing || !dragging || dragging === item || dragging.parentElement !== container) return;
          e.preventDefault(); e.stopPropagation();
          var rect = item.getBoundingClientRect();
          container.insertBefore(dragging, e.clientY > rect.top + rect.height / 2 ? item.nextSibling : item);
          recordOrder(container); dragging = null;
        });
        item.addEventListener('dragend', function () { dragging = null; });
      });
      apply(container);
    });
    var toggle = root.querySelector('[data-layout-edit]'), reset = root.querySelector('[data-layout-reset]');
    if (toggle) toggle.addEventListener('click', function () {
      editing = !editing; root.classList.toggle('waz-layout-editing', editing);
      var secondary = root.querySelector('.waz-secondary-panel');
      if (editing && secondary) secondary.open = true;
      toggle.textContent = editing ? 'Done' : 'Edit layout'; toggle.setAttribute('aria-pressed', String(editing));
      if (reset) reset.hidden = !editing;
      containers.forEach(function (container) { items(container).forEach(function (item) { item.draggable = editing; Array.prototype.forEach.call(item.querySelectorAll('.waz-layout-controls, .waz-layout-grip'), function (control) { control.hidden = !editing; }); }); });
    });
    if (reset) reset.addEventListener('click', function () { state = normalize(null, definitions, defaults); containers.forEach(apply); save(); });
  }
  function start() { Array.prototype.forEach.call(document.querySelectorAll('[data-dashboard-layout]'), mount); }
  window.WAZDashboardLayout = window.WAZStatusLayout = { normalize: normalize, mount: mount, start: start };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
