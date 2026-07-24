/* HotFetched v3.8.0: page order, audio layout, melody application, and stable firmware filenames. */
(() => {
  'use strict';

  const byId = id => document.getElementById(id);
  const normalizedText = node => String(node?.textContent || '').replace(/\s+/g, ' ').trim().toUpperCase();
  const headingSelector = 'h1,h2,h3,h4,h5,h6,legend';

  const dispatchValue = node => {
    if (!node) return;
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
  };

  const presets = () => (typeof CFG_META !== 'undefined' && CFG_META.event_presets) || {};

  const csvSeq = text => {
    const nums = String(text || '')
      .split(',')
      .map(v => Number.parseInt(v.trim(), 10))
      .filter(Number.isFinite);
    if (nums.length < 2 || nums.length % 2) return [];
    const seq = [];
    for (let i = 0; i < nums.length; i += 2) seq.push([nums[i], nums[i + 1]]);
    return seq;
  };

  const powerOnSeq = () => {
    const sel = byId('cf_startup_tune');
    if (!sel || sel.value === 'keep' || sel.value === 'silent') return [];
    if (sel.value === 'custom') return csvSeq(byId('cf_startup_tune_custom')?.value);
    return Array.isArray(presets()[sel.value]) ? presets()[sel.value] : [];
  };

  const lines = seq => !seq.length
    ? '; (no printer power-on sound selected)'
    : seq.map(([f, ms]) => Number(f) === 0 ? `G4 P${ms}` : `M300 S${f} P${ms}`).join('\n');

  function hostCardsGrid(box) {
    if (!box) return null;
    const candidates = [...box.querySelectorAll('div,section')]
      .map(node => ({
        node,
        cards: [...node.children].filter(child => child.classList?.contains('src-card')).length
      }))
      .filter(item => item.cards >= 2)
      .sort((a, b) => a.node.querySelectorAll('*').length - b.node.querySelectorAll('*').length);
    return candidates[0]?.node || null;
  }

  function renderPowerOnCard() {
    const box = byId('sndSnippets');
    if (!box) return;

    let card = byId('hfPowerOnSoundCard');
    if (!card) {
      card = document.createElement('div');
      card.id = 'hfPowerOnSoundCard';
      card.className = 'src-card';
      card.innerHTML = '<h3></h3><p class="empty">Runs from firmware immediately after Marlin starts.</p><pre class="snd-code"></pre><div class="actions"><button type="button" class="btn sm">▶ Play</button><button type="button" class="btn sm">Copy</button></div>';
      const buttons = card.querySelectorAll('button');
      buttons[0].addEventListener('click', () => {
        if (typeof window.togglePlay === 'function') window.togglePlay(buttons[0], powerOnSeq);
      });
      buttons[1].addEventListener('click', () => navigator.clipboard.writeText(lines(powerOnSeq())));
    }

    // Keep the firmware sound card inside the existing sound-card grid instead
    // of placing it loose above the section.
    const grid = hostCardsGrid(box);
    if (grid) {
      if (grid.firstElementChild !== card) grid.prepend(card);
    } else if (box.firstElementChild !== card) {
      box.prepend(card);
    }

    const selected = byId('cf_startup_tune')?.value || 'keep';
    const title = 'Printer powered on — ' + selected;
    const code = lines(powerOnSeq());
    if (card.querySelector('h3').textContent !== title) card.querySelector('h3').textContent = title;
    if (card.querySelector('pre').textContent !== code) card.querySelector('pre').textContent = code;
  }

  function applySeqToSelectedTarget(seq, name = 'melody') {
    const target = document.querySelector('#sndSnippets .snd-import-card select');
    if (!target || !Array.isArray(seq) || !seq.length) return false;

    const key = target.value === 'startup' ? 'startup_tune' : target.value;
    const sel = byId('cf_' + key);
    const custom = byId('cf_' + key + '_custom');
    if (!sel || !custom) return false;

    sel.value = 'custom';
    custom.value = seq.flat().join(',');
    dispatchValue(sel);
    dispatchValue(custom);

    if (typeof window.cfgApplyVisibility === 'function') window.cfgApplyVisibility();
    if (typeof window.cfgExtrasVisibility === 'function') window.cfgExtrasVisibility();

    custom.title = custom.value;
    renderPowerOnCard();

    const msg = document.querySelector('#sndSnippets .snd-import-card .msg');
    if (msg) {
      const destination = target.options[target.selectedIndex]?.textContent?.replace(/^Apply to:\s*/, '') || key;
      msg.textContent = `Applied “${name}” to ${destination} — submit configuration to write it.`;
    }
    return true;
  }

  document.addEventListener('click', ev => {
    const btn = ev.target.closest('button');
    if (!btn || btn.textContent.trim() !== 'Apply' || !btn.closest('.snd-import-card')) return;

    // Let the page's own parser run first, then make sure every changed field
    // is observed by the configuration form.
    setTimeout(() => {
      dispatchValue(byId('cf_startup_tune'));
      dispatchValue(byId('cf_startup_tune_custom'));
      ['ev_print_start', 'ev_print_pause', 'ev_print_error', 'ev_print_end', 'ev_connect'].forEach(key => {
        dispatchValue(byId('cf_' + key));
        dispatchValue(byId('cf_' + key + '_custom'));
      });
      refresh();
    }, 0);
  });

  document.addEventListener('click', async ev => {
    const btn = ev.target.closest('.snd-myrow button');
    if (!btn || btn.textContent.trim() !== 'Use') return;

    ev.preventDefault();
    ev.stopImmediatePropagation();

    const row = btn.closest('.snd-myrow');
    const label = row?.querySelector('.snd-myname')?.textContent || '';
    const name = label.replace(/\s+\(\d+\s+notes\)\s*$/, '');
    btn.disabled = true;

    try {
      const response = await fetch('api/soundlib.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          action: 'custom_get',
          csrf: (typeof CSRF !== 'undefined' ? CSRF : null),
          name
        })
      });
      const payload = await response.json();
      if (!payload.ok || !applySeqToSelectedTarget(payload.seq, name)) {
        throw new Error(payload.error || 'Unable to apply melody');
      }
    } catch (error) {
      const msg = document.querySelector('#sndSnippets .snd-import-card .msg');
      if (msg) msg.textContent = error.message || 'Unable to apply melody';
    } finally {
      btn.disabled = false;
    }
  }, true);

  function normalizeTargetLabel() {
    const target = document.querySelector('#sndSnippets .snd-import-card select');
    if (!target) return;
    for (const option of target.options) {
      if (option.value === 'startup' && option.textContent !== 'Apply to: Printer powered on (firmware)') {
        option.textContent = 'Apply to: Printer powered on (firmware)';
      }
    }
  }

  const isConverterHeading = text =>
    text.startsWith('BOOT & STATUS IMAGES') || text.startsWith('CUSTOM BOOT & STATUS IMAGES');

  const isLayoutHeading = text => [
    'DISPLAY',
    'BOOT & DISPLAY IMAGES',
    'AUDIO',
    'AUDIO (HOST EVENTS)',
    'BED LEVELING',
    'PROBE'
  ].includes(text) || isConverterHeading(text);

  const findHeading = predicate => [...document.querySelectorAll(headingSelector)]
    .find(node => predicate(normalizedText(node)));

  function sectionAncestor(heading) {
    if (!heading) return null;
    let node = heading.parentElement;
    while (node && node !== document.body) {
      const layoutHeadings = [...node.querySelectorAll(headingSelector)]
        .filter(h => isLayoutHeading(normalizedText(h)));
      const hasControls = node.querySelector('input,select,textarea,button');
      if (hasControls && layoutHeadings.length === 1 && layoutHeadings[0] === heading) return node;
      node = node.parentElement;
    }
    return null;
  }

  function converterBlock() {
    const heading = findHeading(isConverterHeading);
    if (!heading) return null;

    const existing = heading.closest('[data-hf-boot-status-images]');
    if (existing) return existing;

    const parent = heading.parentElement;
    if (!parent) return null;

    const wrapper = document.createElement('section');
    wrapper.dataset.hfBootStatusImages = '1';
    wrapper.className = 'hf-boot-status-images-section';
    parent.insertBefore(wrapper, heading);

    let node = heading;
    while (node) {
      const next = node.nextSibling;
      if (node !== heading && node.nodeType === Node.ELEMENT_NODE) {
        const el = node;
        const ownText = el.matches?.(headingSelector) ? normalizedText(el) : '';
        const containsLayoutHeading = [...(el.querySelectorAll?.(headingSelector) || [])]
          .some(h => isLayoutHeading(normalizedText(h)));

        if (
          el.matches?.('form') ||
          ['DISPLAY', 'BOOT & DISPLAY IMAGES', 'AUDIO', 'AUDIO (HOST EVENTS)', 'BED LEVELING', 'PROBE'].includes(ownText) ||
          containsLayoutHeading
        ) break;
      }
      wrapper.appendChild(node);
      node = next;
    }

    return wrapper;
  }

  function directChild(root, node) {
    if (!root || !node || !root.contains(node)) return null;
    let current = node;
    while (current.parentElement && current.parentElement !== root) current = current.parentElement;
    return current.parentElement === root ? current : null;
  }

  function reorderSnippetContent() {
    const box = byId('sndSnippets');
    if (!box) return;

    const importCard = box.querySelector('.snd-import-card');
    const hostHeading = [...box.querySelectorAll(headingSelector)]
      .find(node => normalizedText(node).startsWith('HOST EVENT G-CODE'));
    if (!importCard || !hostHeading) return;

    const importTop = directChild(box, importCard);
    if (!importTop) return;

    const hostCardNames = /^(PRINTER POWERED ON|PRINT START|PRINT PAUSED|PRINT ERROR|PRINT END|CONNECTIVITY ISSUE)\b/;
    const hostNodes = [...box.children].filter(child => {
      if (child === importTop) return false;
      if (child.contains(hostHeading)) return true;

      const text = normalizedText(child);
      if (text.startsWith('HOST EVENT G-CODE')) return true;
      if (text.includes('PASTE THESE INTO YOUR SLICER/HOST EVENT HOOKS')) return true;

      const cardHeadings = [...child.querySelectorAll('h1,h2,h3,h4,h5,h6')]
        .map(normalizedText);
      if (cardHeadings.some(textValue => hostCardNames.test(textValue))) return true;

      if (child.id === 'hfPowerOnSoundCard') return true;
      return false;
    });

    for (const node of hostNodes) {
      // Move only nodes that are currently below the import card. Re-inserting
      // already-correct nodes would create a MutationObserver refresh loop.
      const position = node.compareDocumentPosition(importTop);
      if (position & Node.DOCUMENT_POSITION_PRECEDING) box.insertBefore(node, importTop);
    }
  }

  function findCommonGrid(ids) {
    const controls = ids.map(byId).filter(Boolean);
    if (!controls.length) return null;

    let node = controls[0].parentElement;
    while (node && node !== document.body) {
      if (
        controls.every(control => node.contains(control)) &&
        getComputedStyle(node).display === 'grid'
      ) return node;
      node = node.parentElement;
    }
    return null;
  }

  function applyAudioLayout() {
    const audioGrid = findCommonGrid(['cf_speaker', 'cf_startup_tune', 'cf_startup_tune_custom']);
    if (audioGrid) audioGrid.dataset.hfFirmwareAudioGrid = '1';

    const hostIds = [
      'cf_ev_print_start', 'cf_ev_print_start_custom',
      'cf_ev_print_pause', 'cf_ev_print_pause_custom',
      'cf_ev_print_error', 'cf_ev_print_error_custom',
      'cf_ev_print_end', 'cf_ev_print_end_custom',
      'cf_ev_connect', 'cf_ev_connect_custom'
    ];
    const hostGrid = findCommonGrid(hostIds);
    if (hostGrid) hostGrid.dataset.hfHostAudioGrid = '1';

    ['startup_tune_custom', 'ev_print_start_custom', 'ev_print_pause_custom',
     'ev_print_error_custom', 'ev_print_end_custom', 'ev_connect_custom'].forEach(key => {
      const input = byId('cf_' + key);
      if (input) input.title = input.value || '';
    });
  }

  function injectLayoutStyles() {
    if (byId('hfAudioLayoutStyles')) return;

    const style = document.createElement('style');
    style.id = 'hfAudioLayoutStyles';
    style.textContent = `
      [data-hf-firmware-audio-grid],
      [data-hf-host-audio-grid] {
        align-items: end !important;
      }

      [data-hf-firmware-audio-grid] {
        grid-template-columns: minmax(240px, .75fr) minmax(320px, 1fr) minmax(420px, 1.15fr) !important;
      }

      [data-hf-host-audio-grid] {
        grid-template-columns: minmax(280px, .8fr) minmax(460px, 1.2fr) !important;
      }

      [data-hf-firmware-audio-grid] > *,
      [data-hf-host-audio-grid] > * {
        min-width: 0 !important;
      }

      [data-hf-firmware-audio-grid] input,
      [data-hf-firmware-audio-grid] select,
      [data-hf-firmware-audio-grid] textarea,
      [data-hf-host-audio-grid] input,
      [data-hf-host-audio-grid] select,
      [data-hf-host-audio-grid] textarea {
        box-sizing: border-box !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
      }

      [data-hf-host-audio-grid] label,
      [data-hf-firmware-audio-grid] label {
        overflow-wrap: anywhere;
      }

      .hf-firmware-download-busy {
        opacity: .65;
        pointer-events: none;
      }

      @media (max-width: 1150px) {
        [data-hf-firmware-audio-grid],
        [data-hf-host-audio-grid] {
          grid-template-columns: 1fr !important;
        }
      }
    `;
    document.head.appendChild(style);
  }

  function reorderMainSections() {
    const converter = converterBlock();
    const audioHeading = findHeading(text => text === 'AUDIO');
    const hostHeading = findHeading(text => text === 'AUDIO (HOST EVENTS)');
    const audio = sectionAncestor(audioHeading) || audioHeading;
    const host = sectionAncestor(hostHeading) || hostHeading;

    // Boot & Status Images belongs immediately above Audio.
    if (converter && audio && converter !== audio && audio.parentElement) {
      audio.parentElement.insertBefore(converter, audio);
    }

    // Host-event selections follow firmware audio.
    if (audio && host && audio !== host && audio.parentElement === host.parentElement) {
      audio.insertAdjacentElement('afterend', host);
    }

    // The generated G-code / melody tools follow the audio controls.
    const snippets = byId('sndSnippets');
    if (host && snippets && host !== snippets && !host.contains(snippets) && host.parentElement) {
      host.insertAdjacentElement('afterend', snippets);
    }

    // Inside the generated tools, put Host Event G-code before Import Melody.
    reorderSnippetContent();
  }

  function firmwareControlFromEvent(ev) {
    const candidate = ev.target.closest('a[href],button');
    if (!candidate) return null;
    const label = normalizedText(candidate);
    const isFirmwareButton =
      candidate.id === 'dlFw' ||
      label === 'FIRMWARE' ||
      label === 'FIRMWARE DOWNLOAD' ||
      label.startsWith('DOWNLOAD FIRMWARE');
    if (!isFirmwareButton) return null;

    if (candidate.matches('a[href]') && candidate.getAttribute('href') !== '#') {
      return { control: candidate, href: candidate.href };
    }

    const href = candidate.dataset.href || candidate.dataset.url ||
      candidate.getAttribute('formaction') || candidate.closest('a[href]')?.href;
    return href ? { control: candidate, href: new URL(href, document.baseURI).href } : null;
  }

  function contentDispositionFilename(response) {
    const disposition = response.headers.get('Content-Disposition') || '';
    const utf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
    const plain = disposition.match(/filename="?([^";]+)"?/i);
    let name = utf8?.[1] || plain?.[1] || '';
    try { name = decodeURIComponent(name); } catch (_) {}

    // Strip HotFetched's project/build prefix while preserving the real flash
    // artifact extension for boards that output .hex or .uf2.
    const stable = name.match(/(?:^|[-_])(firmware\.(?:bin|hex|uf2))$/i);
    if (stable) return stable[1].toLowerCase();

    const klipper = name.match(/(?:^|[-_])(klipper\.(?:bin|uf2|elf))$/i);
    if (klipper) return klipper[1].toLowerCase();

    return 'firmware.bin';
  }

  document.addEventListener('click', async ev => {
    const item = firmwareControlFromEvent(ev);
    if (!item) return;

    ev.preventDefault();
    ev.stopImmediatePropagation();

    const { control, href } = item;
    control.classList.add('hf-firmware-download-busy');
    const oldDisabled = control.disabled;
    if ('disabled' in control) control.disabled = true;

    try {
      const response = await fetch(href, { credentials: 'same-origin' });
      if (!response.ok) throw new Error(`Firmware download failed (${response.status})`);

      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = objectUrl;
      link.download = contentDispositionFilename(response);
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    } catch (error) {
      console.error(error);
      alert(error.message || 'Firmware download failed');
    } finally {
      control.classList.remove('hf-firmware-download-busy');
      if ('disabled' in control) control.disabled = oldDisabled;
    }
  }, true);

  function refresh() {
    injectLayoutStyles();
    normalizeTargetLabel();
    renderPowerOnCard();
    applyAudioLayout();
    reorderMainSections();
  }

  document.addEventListener('change', ev => {
    const id = ev.target?.id || '';
    if (
      id === 'cf_startup_tune' ||
      id === 'cf_startup_tune_custom' ||
      id.startsWith('cf_ev_')
    ) refresh();
  });

  const box = byId('sndSnippets');
  if (box) {
    new MutationObserver(() => requestAnimationFrame(refresh))
      .observe(box, { childList: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh, { once: true });
  } else {
    refresh();
  }

  requestAnimationFrame(refresh);
  setTimeout(refresh, 250);
})();
