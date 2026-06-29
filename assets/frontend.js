(function () {
  function money(config, amount) {
    return `${config.currency || ''}${Number(amount || 0).toLocaleString(undefined, {
      minimumFractionDigits: Number(amount || 0) % 1 ? 2 : 0,
      maximumFractionDigits: 2,
    })}`;
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    }[char]));
  }

  function priceHtml(config, regular, sale) {
    const regularPrice = Number(regular || 0);
    const salePrice = Number(sale || 0);
    if (salePrice > 0 && salePrice < regularPrice) {
      return `<span class="wclo-price"><del>${money(config, regularPrice)}</del><strong>${money(config, salePrice)}</strong></span>`;
    }
    return `<span class="wclo-price"><strong>${money(config, regularPrice)}</strong></span>`;
  }

  function optionCard(title, description, selected, attrs, price, extra) {
    const selectedClass = selected ? ' is-selected' : '';
    const priceMarkup = price ? `<span class="wclo-card-price">${price}</span>` : '';
    return `<button type="button" class="wclo-card-option${selectedClass}" ${attrs}>
      <span><b>${title}</b>${description ? `<small>${description}</small>` : ''}${extra || ''}</span>
      ${priceMarkup}<i aria-hidden="true"></i>
    </button>`;
  }

  function colorToHex(name) {
    const colors = {
      black: '#111111',
      brown: '#7a4a24',
      gray: '#707981',
      grey: '#707981',
      green: '#2f6b4f',
      blue: '#2f5f99',
      amber: '#c8872f',
      yellow: '#d9bb31',
      rose: '#c66f83',
      purple: '#7256a8',
    };
    return colors[String(name || '').toLowerCase()] || '#d8dde0';
  }

  function lensTypeLabel(type) {
    return {
      clear: 'Clear lenses',
      blue_cut: 'Blue cut lenses',
      photochromic: 'Photochromic lenses',
      sunglass: 'Sunglass / color lenses',
    }[type] || 'Other lenses';
  }

  function lensTypeDescription(type) {
    return {
      clear: 'Everyday transparent lenses with coating options.',
      blue_cut: 'Computer and phone comfort lenses with blue light filtering choices.',
      photochromic: 'Lenses that stay clear indoors and darken outdoors.',
      sunglass: 'Tinted, colored, or outdoor sun lenses.',
    }[type] || 'Additional lens options.';
  }

  function manualFields(state) {
    const values = state.manual || {};
    const fields = [
      ['right_sph', 'Right SPH'],
      ['right_cyl', 'Right CYL'],
      ['right_axis', 'Right Axis'],
      ['left_sph', 'Left SPH'],
      ['left_cyl', 'Left CYL'],
      ['left_axis', 'Left Axis'],
      ['pd', 'PD'],
    ];
    return `<div class="wclo-manual-grid">${fields
      .map(([key, label]) => {
        const heading = key === 'pd'
          ? `<span class="wclo-field-heading">${label}<button type="button" class="wclo-help-button" data-wclo-help="PD is pupillary distance, the distance between the centers of your pupils. You can find it on many prescriptions or measure it with a ruler and mirror.">?</button></span>`
          : label;
        return `<label>${heading}<input type="text" data-wclo-manual="${key}" value="${escapeHtml(values[key] || '')}"></label>`;
      })
      .join('')}</div>`;
  }

  function init(root) {
    const config = JSON.parse(root.dataset.wcloConfig || '{}');
    const modal = root.querySelector('[data-wclo-modal]');
    const openButton = root.querySelector('[data-wclo-open]');
    const payloadInput = root.querySelector('[data-wclo-payload]');
    const fileInput = root.querySelector('[data-wclo-prescription-file]');
    const summary = root.querySelector('[data-wclo-summary]');
    const form = root.closest('form.cart');
    const t = config.text || {};
    const state = {
      step: 0,
      usage: '',
      prescriptionMethod: '',
      packageId: 0,
      optionId: '',
      lensColor: '',
      openLensType: '',
      addOns: [],
      manual: {},
      customerNote: '',
      customerWhatsapp: '',
      prescriptionFileName: '',
      isSubmitting: false,
      isScanning: false,
      scanMessage: '',
      scanText: '',
      submitTimer: 0,
    };

    if (form) {
      form.setAttribute('enctype', 'multipart/form-data');
    }

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        state.prescriptionFileName = fileInput.files && fileInput.files[0] ? fileInput.files[0].name : '';
        render();
      });
    }

    const steps = ['usage', 'lens', 'prescription', 'addons', 'review'];

    function selectedPackage() {
      return (config.packages || []).find((item) => Number(item.id) === Number(state.packageId));
    }

    function selectedOption() {
      const pack = selectedPackage();
      return pack && Array.isArray(pack.options) ? pack.options.find((item) => item.id === state.optionId) : null;
    }

    function activeColorOptions() {
      const option = selectedOption();
      const pack = selectedPackage();
      if (option && option.colorOptions && option.colorOptions.length) return option.colorOptions;
      return pack && pack.colorOptions && pack.colorOptions.length ? pack.colorOptions : [];
    }

    function selectedAddOns() {
      return (config.addOns || []).filter((item) => state.addOns.includes(item.key));
    }

    function selectedProductAddOns() {
      return selectedAddOns().filter((item) => item.isProduct);
    }

    function selectedLegacyAddOns() {
      return selectedAddOns().filter((item) => !item.isProduct);
    }

    function lensPrice() {
      const option = selectedOption();
      const pack = selectedPackage();
      return option ? Number(option.price || 0) : pack ? Number(pack.price || 0) : 0;
    }

    function legacyPriceDelta() {
      return lensPrice() + selectedLegacyAddOns().reduce((sum, item) => sum + Number(item.price || 0), 0);
    }

    function legacyAddOnsTotal() {
      return selectedLegacyAddOns().reduce((sum, item) => sum + Number(item.price || 0), 0);
    }

    function productAddOnsTotal() {
      return selectedProductAddOns().reduce((sum, item) => sum + Number(item.price || 0), 0);
    }

    function reviewTotal() {
      return Number(config.basePrice || 0) + legacyPriceDelta() + productAddOnsTotal();
    }

    function buildPayload() {
      const pack = selectedPackage();
      const option = selectedOption();
      const finalLensPrice = lensPrice();
      return {
        usage: state.usage,
        prescriptionMethod: state.prescriptionMethod,
        customerWhatsapp: state.customerWhatsapp,
        packageId: pack ? pack.id : 0,
        packageName: pack ? pack.name : '',
        optionId: option ? option.id : '',
        optionName: option ? option.name : '',
        lensIndex: pack ? pack.index : '',
        lensColor: state.lensColor,
        lensRegularPrice: option ? Number(option.regularPrice || 0) : pack ? Number(pack.price || 0) : 0,
        lensSalePrice: option ? Number(option.salePrice || 0) : 0,
        lensFinalPrice: finalLensPrice,
        addOns: selectedAddOns(),
        manual: state.manual,
        customerNote: state.customerNote,
        priceDelta: legacyPriceDelta(),
      };
    }

    function stepTitle() {
      return {
        usage: t.text_step_usage,
        prescription: t.text_step_prescription,
        lens: t.text_step_lens,
        addons: t.text_step_addons,
        review: t.text_step_review,
      }[steps[state.step]];
    }

    function canContinue() {
      if (state.isSubmitting) return false;
      const step = steps[state.step];
      if (step === 'usage') return !!state.usage;
      if (step === 'lens') {
        if (state.usage === 'frame_only') return true;
        const pack = selectedPackage();
        if (!pack) return false;
        if (pack.options && pack.options.length && !selectedOption()) return false;
        return !activeColorOptions().length || !!state.lensColor;
      }
      if (step === 'prescription') {
        if (state.usage !== 'prescription') return true;
        if (!state.prescriptionMethod) return false;
        if ((state.prescriptionMethod === 'upload' || state.prescriptionMethod === 'scan') && !state.prescriptionFileName) return false;
        if (state.prescriptionMethod === 'whatsapp') return !!state.customerWhatsapp.trim();
      }
      return true;
    }

    function nextStep() {
      if (steps[state.step] === 'usage' && state.usage === 'frame_only') {
        state.step = 4;
      } else if (steps[state.step] === 'lens' && state.usage !== 'prescription') {
        state.step = (config.addOns || []).length ? 3 : 4;
      } else if (steps[state.step] === 'prescription' && !(config.addOns || []).length) {
        state.step = 4;
      } else {
        state.step = Math.min(4, state.step + 1);
      }
      render();
    }

    function prevStep() {
      if (steps[state.step] === 'review' && state.usage === 'frame_only') {
        state.step = 0;
      } else if (steps[state.step] === 'review' && state.usage !== 'prescription' && !(config.addOns || []).length) {
        state.step = 1;
      } else if (steps[state.step] === 'addons' && state.usage !== 'prescription') {
        state.step = 1;
      } else if (steps[state.step] === 'lens') {
        state.step = 0;
      } else if (steps[state.step] === 'review' && !(config.addOns || []).length) {
        state.step = 2;
      } else {
        state.step = Math.max(0, state.step - 1);
      }
      render();
    }

    function renderUploadDrop() {
      const name = state.prescriptionFileName || 'No file selected yet';
      return `<div class="wclo-upload-drop" data-wclo-upload-drop>
        <strong>Drop prescription here or click to upload</strong>
        <span>Use a clear photo, camera capture, or PDF. The file will be attached to your order.</span>
        <button type="button" class="button" data-wclo-upload-browse>Choose file or camera</button>
        <small>${escapeHtml(name)}</small>
      </div>`;
    }

    function renderUsage() {
      const frameOnly = config.settings && config.settings.allowFrameOnly === 'yes'
        ? optionCard(t.text_frame_only || 'Frame Only', 'Buy only the frame without lens fitting.', state.usage === 'frame_only', 'data-wclo-usage="frame_only"')
        : '';
      return `${optionCard(t.text_prescription || 'Prescription Lens', 'For distance or near power lenses.', state.usage === 'prescription', 'data-wclo-usage="prescription"')}
      ${optionCard(t.text_non_prescription || 'Non-Prescription Lens', 'For fashion, blue cut or sunglass lenses without power.', state.usage === 'non_prescription', 'data-wclo-usage="non_prescription"')}
      ${frameOnly}`;
    }

    function renderPrescription() {
      const scan = config.settings.allowScan === 'yes'
        ? `${optionCard('Scan prescription upload', 'Upload the prescription, then review and correct the values manually if needed. OCR is best for clear English forms.', state.prescriptionMethod === 'scan', 'data-wclo-method="scan"')}
          <div class="wclo-upload wclo-scan-panel"${state.prescriptionMethod === 'scan' ? '' : ' hidden'}>
            ${renderUploadDrop()}
            <button type="button" class="button wclo-scan-button" data-wclo-scan ${state.isScanning ? 'disabled' : ''}>${state.isScanning ? 'Scanning...' : 'Scan and pre-fill'}</button>
            <span>${state.scanMessage || 'OCR will try to pre-fill the fields below. Please review every value before continuing.'}</span>
            ${Object.keys(state.manual || {}).length ? `<div class="wclo-scan-review"><strong>Review scanned values</strong>${manualFields(state)}</div>` : ''}
            ${state.scanText ? `<details class="wclo-ocr-text"><summary>View extracted text</summary><pre>${escapeHtml(state.scanText)}</pre></details>` : ''}
          </div>`
        : '';
      const upload = config.settings.allowUploads === 'yes'
        ? `${optionCard(t.text_upload || 'Upload Prescription', 'Upload a prescription photo or PDF.', state.prescriptionMethod === 'upload', 'data-wclo-method="upload"')}
          <div class="wclo-upload"${state.prescriptionMethod === 'upload' ? '' : ' hidden'}>${renderUploadDrop()}</div>`
        : '';
      const manual = config.settings.allowManual === 'yes'
        ? `${optionCard(t.text_manual || 'Type Prescription', 'Enter right and left eye details manually.', state.prescriptionMethod === 'manual', 'data-wclo-method="manual"')}
          <div class="wclo-manual"${state.prescriptionMethod === 'manual' ? '' : ' hidden'}>${manualFields(state)}</div>`
        : '';
      const whatsapp = config.settings.allowWhatsapp === 'yes'
        ? `${optionCard(t.text_whatsapp || 'Send Later on WhatsApp', 'Place the order now and send your prescription later.', state.prescriptionMethod === 'whatsapp', 'data-wclo-method="whatsapp"')}
          <div class="wclo-whatsapp-panel"${state.prescriptionMethod === 'whatsapp' ? '' : ' hidden'}>
            <label>Your WhatsApp number<input type="tel" data-wclo-whatsapp-number value="${escapeHtml(state.customerWhatsapp)}" placeholder="+880..."></label>
            ${config.settings.whatsappNumber ? `<small>Store WhatsApp: ${escapeHtml(config.settings.whatsappNumber)}</small>` : ''}
          </div>`
        : '';
      return `${scan}${upload}${manual}${whatsapp}`;
    }

    function renderLensOption(pack, option) {
      const features = (option.features || []).length ? `<ul class="wclo-feature-list">${option.features.map((feature) => `<li>${escapeHtml(feature)}</li>`).join('')}</ul>` : '';
      const details = option.description ? `<details class="wclo-option-details"><summary>Details</summary><p>${escapeHtml(option.description)}</p></details>` : '';
      return optionCard(
        escapeHtml(option.name),
        '',
        Number(state.packageId) === Number(pack.id) && state.optionId === option.id,
        `data-wclo-option="${option.id}" data-wclo-package="${pack.id}"`,
        priceHtml(config, option.regularPrice, option.salePrice),
        `${features}${details}`
      );
    }

    function renderLens() {
      const packages = (config.packages || []).filter((pack) => state.usage !== 'non_prescription' || !pack.requiresPrescription);
      const groups = packages.reduce((grouped, pack) => {
        const type = pack.type || 'other';
        grouped[type] = grouped[type] || [];
        grouped[type].push(pack);
        return grouped;
      }, {});
      const selected = selectedPackage();
      const firstType = Object.keys(groups)[0] || '';
      const openType = state.openLensType || (selected && selected.type) || firstType;
      const cards = Object.keys(groups).map((type) => {
        const isOpen = type === openType;
        const groupSelected = selected && selected.type === type;
        const groupPackages = groups[type];
        const directGroupPackage = groupPackages.length === 1 && !(Array.isArray(groupPackages[0].options) && groupPackages[0].options.length) ? groupPackages[0] : null;
        const options = groups[type].map((pack) => {
          const childOptions = Array.isArray(pack.options) ? pack.options : [];
          if (childOptions.length) {
            const isPackOpen = isOpen || Number(state.packageId) === Number(pack.id);
            return `<article class="wclo-package-block${Number(state.packageId) === Number(pack.id) ? ' is-selected' : ''}">
              <button type="button" class="wclo-package-toggle" data-wclo-package-open="${pack.id}">
                <span><b>${escapeHtml(pack.name)}</b><small>${escapeHtml(pack.description || '')}</small></span>
                <strong>${isPackOpen ? 'Hide options' : 'Select lens'}</strong>
              </button>
              <div class="wclo-package-options"${isPackOpen ? '' : ' hidden'}>
                ${childOptions.map((option) => renderLensOption(pack, option)).join('')}
              </div>
            </article>`;
          }
          const details = [
            pack.description || '',
            pack.index ? `Index ${pack.index}` : '',
            pack.included && pack.included.length ? `Includes ${pack.included.join(', ')}` : '',
          ].filter(Boolean).join(' - ');
          const badge = pack.recommended ? '<em>Recommended</em>' : '';
          return optionCard(`${escapeHtml(pack.name)} ${badge}`, escapeHtml(details), Number(state.packageId) === Number(pack.id), `data-wclo-package="${pack.id}"`, priceHtml(config, pack.price, 0));
        }).join('');
        const groupAction = groupSelected
          ? (selectedOption() ? selectedOption().name : selected.name)
          : directGroupPackage ? 'Select lens' : isOpen ? 'Hide options' : 'View options';
        return `<section class="wclo-lens-group${isOpen ? ' is-open' : ''}${groupSelected ? ' has-selection' : ''}">
          <button type="button" class="wclo-lens-group-toggle" data-wclo-lens-group="${type}"${directGroupPackage ? ` data-wclo-direct-package="${directGroupPackage.id}"` : ''}>
            <span><b>${lensTypeLabel(type)}</b><small>${lensTypeDescription(type)}</small></span>
            <strong>${escapeHtml(groupAction)}</strong>
          </button>
          <div class="wclo-lens-group-body"${isOpen ? '' : ' hidden'}>${options}</div>
        </section>`;
      }).join('');

      state.openLensType = openType;
      const colorOptions = activeColorOptions();
      const colors = colorOptions.length
        ? `<div class="wclo-color-options">
            <strong>Choose lens color</strong>
            <div class="wclo-color-list">${colorOptions.map((color) => `
              <button type="button" class="wclo-color-choice${state.lensColor === color ? ' is-selected' : ''}" data-wclo-color="${escapeHtml(color)}">
                <span class="wclo-color-swatch" style="background:${colorToHex(color)}"></span>${escapeHtml(color)}
              </button>`).join('')}</div>
          </div>`
        : '';

      return `${cards}${colors}`;
    }

    function renderAddOns() {
      return (config.addOns || [])
        .map((addOn) => {
          const label = addOn.isProduct ? `${escapeHtml(addOn.name)} <span class="wclo-pill">Product</span>` : escapeHtml(addOn.name);
          return optionCard(label, escapeHtml(addOn.description || ''), state.addOns.includes(addOn.key), `data-wclo-addon="${escapeHtml(addOn.key)}"`, priceHtml(config, addOn.price, 0));
        })
        .join('') || '<p class="wclo-muted">No optional add-ons are active.</p>';
    }

    function renderReview() {
      const pack = selectedPackage();
      const option = selectedOption();
      const uploadField = state.prescriptionMethod === 'upload' || state.prescriptionMethod === 'scan'
        ? `<div class="wclo-upload">${renderUploadDrop()}</div>`
        : '';
      const rows = [
        ['Usage', state.usage.replace('_', ' ')],
        ['Prescription', state.prescriptionMethod ? state.prescriptionMethod.replace('_', ' ') : 'Not needed'],
        ['WhatsApp', state.customerWhatsapp || 'Not provided'],
        ['Lens category', pack ? pack.name : 'No lens package'],
        ['Lens option', option ? option.name : 'Not needed'],
        ['Lens color', state.lensColor || 'Not selected'],
        ['Add-ons', selectedAddOns().map((item) => item.name).join(', ') || 'None'],
        ['Lens price', money(config, lensPrice())],
      ];
      if (selectedLegacyAddOns().length) rows.push(['Lens add-ons total', money(config, legacyAddOnsTotal())]);
      if (selectedProductAddOns().length) rows.push(['Product add-ons subtotal', money(config, productAddOnsTotal())]);
      return `<div class="wclo-review">${rows.map(([label, value]) => `<p><span>${label}</span><strong>${escapeHtml(value)}</strong></p>`).join('')}
        ${uploadField}
        <label class="wclo-note">Order note for lens team<textarea data-wclo-note rows="3">${escapeHtml(state.customerNote || '')}</textarea></label>
      </div>`;
    }

    function renderBody() {
      return {
        usage: renderUsage,
        prescription: renderPrescription,
        lens: renderLens,
        addons: renderAddOns,
        review: renderReview,
      }[steps[state.step]]();
    }

    function render() {
      modal.hidden = false;
      modal.innerHTML = `<div class="wclo-backdrop" data-wclo-close></div>
        <section class="wclo-dialog${state.isSubmitting ? ' is-submitting' : ''}" role="dialog" aria-modal="true" aria-label="${escapeHtml(stepTitle())}">
          <header>
            <button type="button" class="wclo-icon-button" data-wclo-close aria-label="Close">&times;</button>
            <div class="wclo-progress">${steps.map((step, index) => `<span class="${index <= state.step ? 'is-active' : ''}"></span>`).join('')}</div>
            <h2>${escapeHtml(stepTitle())}</h2>
          </header>
          <div class="wclo-body">${renderBody()}</div>
          <footer>
            <button type="button" class="button wclo-secondary" data-wclo-back ${state.step === 0 ? 'hidden' : ''}>${t.text_back || 'Previous Step'}</button>
            <button type="button" class="button wclo-primary" data-wclo-next ${canContinue() ? '' : 'disabled'}>
              ${state.isSubmitting ? t.text_submit_button || 'Adding to cart...' : state.step === 4 ? `${t.text_add_to_cart || 'Add to Cart'} - ${money(config, reviewTotal())}` : t.text_continue || 'Continue'}
            </button>
          </footer>
          <div class="wclo-submit-note" hidden><strong>${t.text_submit_title || 'Thanks, your lens selection is being added.'}</strong><span>${t.text_submit_message || 'Please wait while the cart updates.'}</span></div>
        </section>`;
    }

    function close() {
      if (state.submitTimer) {
        clearTimeout(state.submitTimer);
        state.submitTimer = 0;
      }
      modal.hidden = true;
      modal.innerHTML = '';
    }

    function closeAfterSubmit() {
      close();
      state.isSubmitting = false;
    }

    function saveSummary() {
      const payload = buildPayload();
      payloadInput.value = JSON.stringify(payload);
      const pack = selectedPackage();
      const option = selectedOption();
      summary.hidden = false;
      summary.innerHTML = `<strong>Lens selected:</strong> ${escapeHtml(option ? option.name : pack ? pack.name : payload.usage.replace('_', ' '))} <span>${money(config, legacyPriceDelta())}</span>`;
    }

    async function scanPrescription() {
      const file = fileInput && fileInput.files && fileInput.files[0];
      if (!file) {
        state.scanMessage = 'Please choose a prescription file first.';
        render();
        return;
      }

      state.isScanning = true;
      state.scanMessage = 'Scanning your prescription...';
      state.scanText = '';
      render();

      const data = new FormData();
      data.append('action', 'wclo_scan_prescription');
      data.append('nonce', config.ajax ? config.ajax.nonce : '');
      data.append('wclo_ocr_file', file);

      try {
        const response = await fetch(config.ajax ? config.ajax.url : '', {
          method: 'POST',
          body: data,
          credentials: 'same-origin',
        });
        const result = await response.json();
        if (!result || !result.success) {
          throw new Error((result && result.data && result.data.message) || 'OCR scan failed.');
        }
        state.manual = Object.assign({}, state.manual, result.data.fields || {});
        state.scanText = result.data.text || '';
        state.scanMessage = result.data.message || 'Scan complete. Please review every value before continuing.';
      } catch (error) {
        state.scanMessage = error.message || 'OCR scan failed. Please enter the prescription manually.';
      } finally {
        state.isScanning = false;
        render();
      }
    }

    function openFileBrowser() {
      if (fileInput) fileInput.click();
    }

    openButton.addEventListener('click', function () {
      state.step = 0;
      render();
    });

    modal.addEventListener('click', function (event) {
      const target = event.target;
      const usage = target.closest('[data-wclo-usage]');
      const method = target.closest('[data-wclo-method]');
      const lensGroup = target.closest('[data-wclo-lens-group]');
      const packageToggle = target.closest('[data-wclo-package-open]');
      const pack = target.closest('[data-wclo-package]');
      const option = target.closest('[data-wclo-option]');
      const color = target.closest('[data-wclo-color]');
      const addon = target.closest('[data-wclo-addon]');
      const scanButton = target.closest('[data-wclo-scan]');
      const uploadBrowse = target.closest('[data-wclo-upload-browse], [data-wclo-upload-drop]');

      if (state.isSubmitting) return;
      if (scanButton) {
        scanPrescription();
        return;
      }
      if (uploadBrowse) {
        openFileBrowser();
        return;
      }
      if (target.closest('[data-wclo-close]')) close();
      if (target.closest('[data-wclo-back]')) prevStep();
      if (usage) state.usage = usage.dataset.wcloUsage;
      if (method) state.prescriptionMethod = method.dataset.wcloMethod;
      if (lensGroup) {
        state.openLensType = lensGroup.dataset.wcloLensGroup;
        if (lensGroup.dataset.wcloDirectPackage) {
          state.packageId = Number(lensGroup.dataset.wcloDirectPackage);
          state.optionId = '';
          state.lensColor = '';
        }
      }
      if (packageToggle) {
        const toggled = (config.packages || []).find((item) => Number(item.id) === Number(packageToggle.dataset.wcloPackageOpen));
        state.openLensType = toggled ? toggled.type : state.openLensType;
      }
      if (pack && !option) {
        state.packageId = Number(pack.dataset.wcloPackage);
        state.optionId = '';
        const selected = selectedPackage();
        state.openLensType = selected ? selected.type : state.openLensType;
        state.lensColor = '';
      }
      if (option) {
        state.packageId = Number(option.dataset.wcloPackage);
        state.optionId = option.dataset.wcloOption;
        const selected = selectedPackage();
        state.openLensType = selected ? selected.type : state.openLensType;
        state.lensColor = '';
      }
      if (color) state.lensColor = color.dataset.wcloColor;
      if (addon) {
        const key = addon.dataset.wcloAddon;
        state.addOns = state.addOns.includes(key) ? state.addOns.filter((item) => item !== key) : state.addOns.concat(key);
      }
      if (usage || method || lensGroup || packageToggle || pack || option || color || addon) render();
      if (target.closest('[data-wclo-next]') && canContinue()) {
        if (state.step === 4) {
          const note = modal.querySelector('[data-wclo-note]');
          if (note) state.customerNote = note.value;
          saveSummary();
          state.isSubmitting = true;
          const dialog = modal.querySelector('.wclo-dialog');
          const submitNote = modal.querySelector('.wclo-submit-note');
          if (dialog) dialog.classList.add('is-submitting');
          if (submitNote) submitNote.hidden = false;
          modal.querySelectorAll('button').forEach((button) => {
            button.disabled = true;
          });
          state.submitTimer = window.setTimeout(closeAfterSubmit, Number(config.settings && config.settings.submitCloseDelay ? config.settings.submitCloseDelay : 2500));
          if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
        } else {
          nextStep();
        }
      }
    });

    modal.addEventListener('dragover', function (event) {
      if (!event.target.closest('[data-wclo-upload-drop]')) return;
      event.preventDefault();
      event.target.closest('[data-wclo-upload-drop]').classList.add('is-dragging');
    });

    modal.addEventListener('dragleave', function (event) {
      const drop = event.target.closest('[data-wclo-upload-drop]');
      if (drop) drop.classList.remove('is-dragging');
    });

    modal.addEventListener('drop', function (event) {
      const drop = event.target.closest('[data-wclo-upload-drop]');
      if (!drop || !fileInput) return;
      event.preventDefault();
      drop.classList.remove('is-dragging');
      if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length) {
        fileInput.files = event.dataTransfer.files;
        state.prescriptionFileName = event.dataTransfer.files[0].name;
        render();
      }
    });

    modal.addEventListener('input', function (event) {
      const manual = event.target.closest('[data-wclo-manual]');
      if (manual) state.manual[manual.dataset.wcloManual] = manual.value;
      const note = event.target.closest('[data-wclo-note]');
      if (note) state.customerNote = note.value;
      const whatsapp = event.target.closest('[data-wclo-whatsapp-number]');
      if (whatsapp) {
        state.customerWhatsapp = whatsapp.value;
        render();
      }
    });

    if (window.jQuery) {
      window.jQuery(document.body).on('added_to_cart wc_fragments_refreshed wc_fragments_loaded', closeAfterSubmit);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-wclo-root]').forEach(init);
  });
})();
