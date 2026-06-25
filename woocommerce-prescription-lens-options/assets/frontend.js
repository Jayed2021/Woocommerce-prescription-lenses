(function () {
  function money(config, amount) {
    return `${config.currency || ''}${Number(amount || 0).toLocaleString(undefined, {
      minimumFractionDigits: Number(amount || 0) % 1 ? 2 : 0,
      maximumFractionDigits: 2,
    })}`;
  }

  function optionCard(title, description, selected, attrs, price) {
    const selectedClass = selected ? ' is-selected' : '';
    const priceHtml = price ? `<strong>${price}</strong>` : '';
    return `<button type="button" class="wclo-card-option${selectedClass}" ${attrs}>
      <span><b>${title}</b>${description ? `<small>${description}</small>` : ''}</span>
      ${priceHtml}<i aria-hidden="true"></i>
    </button>`;
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
      .map(([key, label]) => `<label>${label}<input type="text" data-wclo-manual="${key}" value="${values[key] || ''}"></label>`)
      .join('')}</div>`;
  }

  function init(root) {
    const config = JSON.parse(root.dataset.wcloConfig || '{}');
    const modal = root.querySelector('[data-wclo-modal]');
    const openButton = root.querySelector('[data-wclo-open]');
    const payloadInput = root.querySelector('[data-wclo-payload]');
    const summary = root.querySelector('[data-wclo-summary]');
    const form = root.closest('form.cart');
    const t = config.text || {};
    const state = {
      step: 0,
      usage: '',
      prescriptionMethod: '',
      packageId: 0,
      addOns: [],
      manual: {},
      customerNote: '',
    };

    if (form) {
      form.setAttribute('enctype', 'multipart/form-data');
    }

    const steps = ['usage', 'prescription', 'lens', 'addons', 'review'];

    function selectedPackage() {
      return (config.packages || []).find((item) => Number(item.id) === Number(state.packageId));
    }

    function selectedAddOns() {
      return (config.addOns || []).filter((item) => state.addOns.includes(item.key));
    }

    function priceDelta() {
      const pack = selectedPackage();
      return (pack ? Number(pack.price || 0) : 0) + selectedAddOns().reduce((sum, item) => sum + Number(item.price || 0), 0);
    }

    function buildPayload() {
      const pack = selectedPackage();
      return {
        usage: state.usage,
        prescriptionMethod: state.prescriptionMethod,
        packageId: pack ? pack.id : 0,
        packageName: pack ? pack.name : '',
        lensIndex: pack ? pack.index : '',
        addOns: selectedAddOns(),
        manual: state.manual,
        customerNote: state.customerNote,
        priceDelta: priceDelta(),
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
      const step = steps[state.step];
      if (step === 'usage') return !!state.usage;
      if (step === 'prescription') return state.usage !== 'prescription' || !!state.prescriptionMethod;
      if (step === 'lens') return state.usage === 'frame_only' || !!state.packageId;
      return true;
    }

    function nextStep() {
      if (steps[state.step] === 'usage' && state.usage !== 'prescription') {
        state.step = state.usage === 'frame_only' ? 4 : 2;
      } else if (steps[state.step] === 'lens' && !(config.addOns || []).length) {
        state.step = 4;
      } else {
        state.step = Math.min(4, state.step + 1);
      }
      render();
    }

    function prevStep() {
      if (steps[state.step] === 'lens' && state.usage !== 'prescription') {
        state.step = 0;
      } else if (steps[state.step] === 'review' && state.usage === 'frame_only') {
        state.step = 0;
      } else if (steps[state.step] === 'review' && !(config.addOns || []).length) {
        state.step = 2;
      } else {
        state.step = Math.max(0, state.step - 1);
      }
      render();
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
      const upload = config.settings.allowUploads === 'yes'
        ? `${optionCard(t.text_upload || 'Upload Prescription', 'Upload a prescription photo or PDF.', state.prescriptionMethod === 'upload', 'data-wclo-method="upload"')}
          <div class="wclo-upload"${state.prescriptionMethod === 'upload' ? '' : ' hidden'}><span>Upload the file on the final review step so it is attached to your order.</span></div>`
        : '';
      const manual = config.settings.allowManual === 'yes'
        ? `${optionCard(t.text_manual || 'Type Prescription', 'Enter right and left eye details manually.', state.prescriptionMethod === 'manual', 'data-wclo-method="manual"')}
          <div class="wclo-manual"${state.prescriptionMethod === 'manual' ? '' : ' hidden'}>${manualFields(state)}</div>`
        : '';
      const whatsapp = config.settings.allowWhatsapp === 'yes'
        ? optionCard(t.text_whatsapp || 'Send Later on WhatsApp', 'Place the order now and send your prescription later.', state.prescriptionMethod === 'whatsapp', 'data-wclo-method="whatsapp"')
        : '';
      return `${upload}${manual}${whatsapp}`;
    }

    function renderLens() {
      return (config.packages || [])
        .filter((pack) => state.usage !== 'non_prescription' || !pack.requiresPrescription)
        .map((pack) => {
          const details = [
            pack.description || '',
            pack.index ? `Index ${pack.index}` : '',
            pack.included && pack.included.length ? `Includes ${pack.included.join(', ')}` : '',
          ].filter(Boolean).join(' · ');
          const badge = pack.recommended ? '<em>Recommended</em>' : '';
          return optionCard(`${pack.name} ${badge}`, details, Number(state.packageId) === Number(pack.id), `data-wclo-package="${pack.id}"`, money(config, pack.price));
        })
        .join('');
    }

    function renderAddOns() {
      return (config.addOns || [])
        .map((addOn) => optionCard(addOn.name, addOn.description || '', state.addOns.includes(addOn.key), `data-wclo-addon="${addOn.key}"`, money(config, addOn.price)))
        .join('') || '<p class="wclo-muted">No optional add-ons are active.</p>';
    }

    function renderReview() {
      const pack = selectedPackage();
      const uploadField = state.prescriptionMethod === 'upload'
        ? '<div class="wclo-upload"><label>Prescription file<input type="file" name="wclo_prescription_file" accept=".jpg,.jpeg,.png,.pdf"></label></div>'
        : '';
      const rows = [
        ['Usage', state.usage.replace('_', ' ')],
        ['Prescription', state.prescriptionMethod ? state.prescriptionMethod.replace('_', ' ') : 'Not needed'],
        ['Lens', pack ? pack.name : 'No lens package'],
        ['Add-ons', selectedAddOns().map((item) => item.name).join(', ') || 'None'],
        ['Lens price', money(config, priceDelta())],
      ];
      return `<div class="wclo-review">${rows.map(([label, value]) => `<p><span>${label}</span><strong>${value}</strong></p>`).join('')}
        ${uploadField}
        <label class="wclo-note">Order note for lens team<textarea data-wclo-note rows="3">${state.customerNote || ''}</textarea></label>
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
        <section class="wclo-dialog" role="dialog" aria-modal="true" aria-label="${stepTitle()}">
          <header>
            <button type="button" class="wclo-icon-button" data-wclo-close aria-label="Close">&times;</button>
            <div class="wclo-progress">${steps.map((step, index) => `<span class="${index <= state.step ? 'is-active' : ''}"></span>`).join('')}</div>
            <h2>${stepTitle()}</h2>
          </header>
          <div class="wclo-body">${renderBody()}</div>
          <footer>
            <button type="button" class="button wclo-secondary" data-wclo-back ${state.step === 0 ? 'hidden' : ''}>${t.text_back || 'Previous Step'}</button>
            <button type="button" class="button wclo-primary" data-wclo-next ${canContinue() ? '' : 'disabled'}>
              ${state.step === 4 ? `${t.text_add_to_cart || 'Add to Cart'} - ${money(config, Number(config.basePrice || 0) + priceDelta())}` : t.text_continue || 'Continue'}
            </button>
          </footer>
        </section>`;
    }

    function close() {
      modal.hidden = true;
      modal.innerHTML = '';
    }

    function saveSummary() {
      const payload = buildPayload();
      payloadInput.value = JSON.stringify(payload);
      const pack = selectedPackage();
      summary.hidden = false;
      summary.innerHTML = `<strong>Lens selected:</strong> ${pack ? pack.name : payload.usage.replace('_', ' ')} <span>${money(config, priceDelta())}</span>`;
    }

    openButton.addEventListener('click', function () {
      state.step = 0;
      render();
    });

    modal.addEventListener('click', function (event) {
      const target = event.target;
      const usage = target.closest('[data-wclo-usage]');
      const method = target.closest('[data-wclo-method]');
      const pack = target.closest('[data-wclo-package]');
      const addon = target.closest('[data-wclo-addon]');

      if (target.closest('[data-wclo-close]')) close();
      if (target.closest('[data-wclo-back]')) prevStep();
      if (usage) state.usage = usage.dataset.wcloUsage;
      if (method) state.prescriptionMethod = method.dataset.wcloMethod;
      if (pack) state.packageId = Number(pack.dataset.wcloPackage);
      if (addon) {
        const key = addon.dataset.wcloAddon;
        state.addOns = state.addOns.includes(key) ? state.addOns.filter((item) => item !== key) : state.addOns.concat(key);
      }
      if (usage || method || pack || addon) render();
      if (target.closest('[data-wclo-next]') && canContinue()) {
        if (state.step === 4) {
          const note = modal.querySelector('[data-wclo-note]');
          if (note) state.customerNote = note.value;
          saveSummary();
          close();
          if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
        } else {
          nextStep();
        }
      }
    });

    modal.addEventListener('input', function (event) {
      const manual = event.target.closest('[data-wclo-manual]');
      if (manual) state.manual[manual.dataset.wcloManual] = manual.value;
      const note = event.target.closest('[data-wclo-note]');
      if (note) state.customerNote = note.value;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-wclo-root]').forEach(init);
  });
})();
