/* global jQuery, STE */
jQuery(function ($) {
  const $tbody = $('#ste-driver-table tbody');
  const $status = $('#ste-status');

  function setStatus(msg, type) {
    $status.removeClass('ste-ok ste-err ste-info');
    if (type === 'ok') $status.addClass('ste-ok');
    if (type === 'err') $status.addClass('ste-err');
    if (type === 'info') $status.addClass('ste-info');
    $status.text(msg || '');
  }

  function escapeHtml(str) {
    return $('<div/>').text(str ?? '').html();
  }

  function renderSelect(options, selected, cssClass) {
    const opts = (options || []).map(v => {
      const sel = (v === selected) ? ' selected' : '';
      return `<option value="${escapeHtml(v)}"${sel}>${escapeHtml(v)}</option>`;
    }).join('');
    return `<select class="ste-select ${cssClass}">${opts}</select>`;
  }

  function normalizeDateForInput(dateStr) {
    // Backend stores DATE as YYYY-MM-DD
    if (!dateStr) return '';
    return dateStr;
  }

  function rowHtml(row) {
    const driverId = row.driver_id ?? '';
    const name = row.name ?? '';
    const role = row.role ?? (STE.roles?.[0] || 'CONDUCTOR');
    const perm = row.permission ?? (STE.permissions?.[0] || 'NIVEL 1');
    const dateVal = normalizeDateForInput(row.license_valid_until);

    return `
      <tr data-original-id="${escapeHtml(String(driverId))}">
        <td><input class="ste-input ste-id" type="number" min="1" value="${escapeHtml(String(driverId))}"></td>
        <td><input class="ste-input ste-name" type="text" value="${escapeHtml(name)}"></td>
        <td>${renderSelect(STE.roles || [], role, 'ste-role')}</td>
        <td>${renderSelect(STE.permissions || [], perm, 'ste-permission')}</td>
        <td><input class="ste-input ste-date" type="date" value="${escapeHtml(dateVal)}"></td>
        <td class="ste-actions-cell">
          <button type="button" class="ste-btn ste-btn-success ste-save" disabled>Guardar</button>
        </td>
      </tr>
    `;
  }

  function markDirty($tr, dirty) {
    const $btn = $tr.find('.ste-save');
    if (dirty) {
      $tr.addClass('ste-dirty');
      $btn.prop('disabled', false).removeClass('ste-saved ste-error').addClass('ste-pending').text('Guardar');
    } else {
      $tr.removeClass('ste-dirty');
      $btn.prop('disabled', true).removeClass('ste-pending ste-error').text('Guardar');
    }
  }

  function loadRows() {
    setStatus('Loading...', 'info');
    $.post(STE.ajaxUrl, { action: 'ste_list', nonce: STE.nonce })
      .done(function (res) {
        if (!res || !res.success) {
          setStatus(res?.data?.message || 'Load failed', 'err');
          return;
        }

        // Use server-provided options as source of truth
        if (Array.isArray(res.data.roles)) STE.roles = res.data.roles;
        if (Array.isArray(res.data.permissions)) STE.permissions = res.data.permissions;

        const rows = res.data.rows || [];
        if (!rows.length) {
          $tbody.html('<tr><td colspan="6">No data</td></tr>');
        } else {
          $tbody.html(rows.map(rowHtml).join(''));
        }

        setStatus('', 'info');
      })
      .fail(function () {
        setStatus('Network error while loading', 'err');
      });
  }

  // Add new row (server suggests an ID)
  $('#ste-add-row').on('click', function () {
    setStatus('', 'info');
    $.post(STE.ajaxUrl, { action: 'ste_add_row', nonce: STE.nonce })
      .done(function (res) {
        if (!res || !res.success) {
          setStatus(res?.data?.message || 'Add row failed', 'err');
          return;
        }
        const row = res.data.row;
        const $tr = $(rowHtml(row));
        $tbody.prepend($tr);

        // New row should be immediately editable + dirty
        markDirty($tr, true);
        $tr.find('.ste-name').focus();
      })
      .fail(function () {
        setStatus('Network error while adding row', 'err');
      });
  });

  // Detect changes and enable "Guardar"
  $tbody.on('input change', '.ste-input, .ste-select', function () {
    const $tr = $(this).closest('tr');
    markDirty($tr, true);
  });

  // Save row
  $tbody.on('click', '.ste-save', function () {
    const $btn = $(this);
    const $tr = $btn.closest('tr');

    const originalId = parseInt($tr.attr('data-original-id'), 10) || 0;

    const driverId = parseInt($tr.find('.ste-id').val(), 10) || 0;
    const name = ($tr.find('.ste-name').val() || '').trim();
    const role = $tr.find('.ste-role').val();
    const permission = $tr.find('.ste-permission').val();
    const licenseValidUntil = $tr.find('.ste-date').val(); // YYYY-MM-DD or ""

    if (!driverId) {
      setStatus('ID is required and must be > 0', 'err');
      $btn.addClass('ste-error').removeClass('ste-saving ste-pending');
      return;
    }
    if (!name) {
      setStatus('Name is required', 'err');
      $btn.addClass('ste-error').removeClass('ste-saving ste-pending');
      return;
    }

    // Visual feedback: saving state
    $btn.prop('disabled', true)
      .addClass('ste-saving')
      .removeClass('ste-error ste-saved ste-pending')
      .text('Guardando...');

    $.post(STE.ajaxUrl, {
      action: 'ste_save_row',
      nonce: STE.nonce,
      original_driver_id: originalId,
      driver_id: driverId,
      name: name,
      role: role,
      permission: permission,
      license_valid_until: licenseValidUntil
    })
      .done(function (res) {
        if (!res || !res.success) {
          const msg = res?.data?.message || 'Save failed';
          setStatus(msg, 'err');
          $btn.prop('disabled', false)
            .addClass('ste-error')
            .removeClass('ste-saving')
            .text('Guardar');
          return;
        }

        setStatus('Guardado ✅', 'ok');

        // Update "original id" if user changed the ID
        $tr.attr('data-original-id', String(res.data.driver_id));

        // Saved feedback: button turns green briefly
        $btn.addClass('ste-saved')
          .removeClass('ste-saving')
          .text('Guardado');

        markDirty($tr, false);

        setTimeout(() => {
          $btn.text('Guardar').removeClass('ste-saved');
          setStatus('', 'info');
        }, 1500);
      })
      .fail(function () {
        setStatus('Network error while saving', 'err');
        $btn.prop('disabled', false)
          .addClass('ste-error')
          .removeClass('ste-saving')
          .text('Guardar');
      });
  });

  // Initial load
  loadRows();

  // Optional auto-refresh so changes by other users appear (without reloading page).
  // Avoid overwriting unsaved edits by only refreshing when nothing is "dirty".
  setInterval(function () {
    if ($tbody.find('tr.ste-dirty').length === 0) {
      loadRows();
    }
  }, 15000);
});
