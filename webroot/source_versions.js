(() => {
  'use strict';

  const byId = (id) => document.getElementById(id);
  const urlInput = byId('ghUrl');
  let refSelect = byId('ghRef');
  if (!urlInput || !refSelect || typeof FIRMWARE === 'undefined') return;

  // Supports both the server-rendered select and an older project.php input.
  if (refSelect.tagName !== 'SELECT') {
    const oldValue = refSelect.value || '';
    const replacement = document.createElement('select');
    replacement.id = 'ghRef';
    replacement.dataset.previousRef = oldValue;
    replacement.disabled = true;
    replacement.innerHTML = '<option value="">Loading available versions…</option>';
    refSelect.replaceWith(replacement);
    refSelect = replacement;
  }

  let status = byId('ghRefStatus');
  if (!status) {
    status = document.createElement('span');
    status.id = 'ghRefStatus';
    status.className = 'hint';
    refSelect.insertAdjacentElement('afterend', status);
  }

  let loadSequence = 0;
  let debounceTimer = null;

  function option(value, label) {
    const node = document.createElement('option');
    node.value = value;
    node.textContent = label;
    return node;
  }

  function addGroup(label, values, prefix = '') {
    if (!Array.isArray(values) || values.length === 0) return;
    const group = document.createElement('optgroup');
    group.label = label;
    values.forEach((value) => group.appendChild(option(value, prefix + value)));
    refSelect.appendChild(group);
  }

  function selectValue(value) {
    if (!value) {
      refSelect.value = '';
      return;
    }
    const exists = Array.from(refSelect.options).some((o) => o.value === value);
    if (!exists) {
      const recommended = option(value, 'Recommended — ' + value);
      refSelect.insertBefore(recommended, refSelect.children[1] || null);
    }
    refSelect.value = value;
  }

  async function officialDefaults() {
    if (typeof srcApi !== 'function') return null;
    try {
      const result = await srcApi({ action: 'defaults', firmware: FIRMWARE });
      return result && result.ok ? result : null;
    } catch {
      return null;
    }
  }

  async function loadVersions(preferredRef = '') {
    const sequence = ++loadSequence;
    const previous = preferredRef || refSelect.value || refSelect.dataset.previousRef || '';

    refSelect.disabled = true;
    refSelect.innerHTML = '<option value="">Loading available versions…</option>';
    status.textContent = 'Reading all tags and branches from GitHub…';

    let defaults = null;
    if (!urlInput.value.trim() || !preferredRef) {
      defaults = await officialDefaults();
      if (sequence !== loadSequence) return;
      if (defaults && !urlInput.value.trim()) urlInput.value = defaults.url;
    }

    const url = urlInput.value.trim();
    if (!url) {
      refSelect.innerHTML = '<option value="">Repository URL required</option>';
      status.textContent = 'Enter a GitHub repository URL.';
      return;
    }

    try {
      const query = new URLSearchParams({ firmware: FIRMWARE, url });
      const response = await fetch('api/source_versions.php?' + query.toString(), {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const data = await response.json();
      if (sequence !== loadSequence) return;
      if (!response.ok || !data.ok) throw new Error(data.error || 'Version lookup failed');

      refSelect.innerHTML = '';
      refSelect.appendChild(option('', 'Default branch (repository default)'));
      addGroup('Branches', data.branches || []);
      addGroup('Released versions / tags', data.tags || []);

      const desired = preferredRef || previous || defaults?.ref || data.default_ref || '';
      selectValue(desired);
      refSelect.disabled = false;

      const branchCount = Number(data.counts?.branches || 0);
      const tagCount = Number(data.counts?.tags || 0);
      status.textContent = `${tagCount} version${tagCount === 1 ? '' : 's'} and ${branchCount} branch${branchCount === 1 ? '' : 'es'} available${data.cached ? ' (cached)' : ''}.`;
    } catch (error) {
      if (sequence !== loadSequence) return;
      refSelect.innerHTML = '';
      refSelect.appendChild(option('', 'Default branch (version list unavailable)'));
      refSelect.disabled = false;
      status.textContent = error instanceof Error ? error.message : 'Version lookup failed';
    }
  }

  urlInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => loadVersions(''), 650);
  });
  urlInput.addEventListener('change', () => loadVersions(''));

  // Replace the existing button behavior so the official repository and its
  // recommended branch are selected only after the dropdown has been loaded.
  byId('ghDefaultBtn')?.addEventListener('click', async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();
    const defaults = await officialDefaults();
    if (!defaults) {
      status.textContent = 'Unable to load the official repository defaults.';
      return;
    }
    urlInput.value = defaults.url;
    await loadVersions(defaults.ref || '');
  }, true);

  loadVersions('');
})();
