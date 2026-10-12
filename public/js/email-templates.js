// ── Email Templates catalog — admin CRUD ──────────────────────────────────────
// Jodit instances for create/edit modals
var _tplJoditCreate = null;
var _tplJoditEdit   = null;

// Track which text field last had focus (subject or body) for variable insertion
window._tplActiveField = null;

function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

document.addEventListener('DOMContentLoaded', function () {

    // ── Edit button handler ───────────────────────────────────────────────────
    document.querySelectorAll('.edit-tpl-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id       = this.dataset.id;
            const name     = this.dataset.name;
            const subject  = this.dataset.subject;
            const body     = this.dataset.body;
            const tenantId = this.dataset.tenant;

            document.getElementById('editName').value    = name;
            document.getElementById('editSubject').value = subject;
            document.getElementById('editForm').action   =
                '/email-templates/' + id + '?_method=PUT';

            // Populate tenant selector (superadmin only — element may not exist)
            const tenantSel = document.getElementById('editTenantId');
            if (tenantSel && tenantId) tenantSel.value = tenantId;

            openModal('editModal');

            // Init Jodit after modal is visible
            setTimeout(function () {
                if (_tplJoditEdit) {
                    try { _tplJoditEdit.destruct(); } catch (e) {}
                    _tplJoditEdit = null;
                }
                // Set textarea value BEFORE Jodit.make() so Jodit reads it on init
                const ta = document.getElementById('editBody');
                if (ta) ta.value = body;
                _tplJoditEdit = Jodit.make('#editBody', _joditTplConfig());
                // Track focus on Jodit area for variable insertion
                _tplJoditEdit.events.on('focus', function () {
                    window._tplActiveField = 'jodit-edit';
                });
            }, 150);
        });
    });

    // ── Create modal — init Jodit on open ────────────────────────────────────
    document.querySelector('[onclick="openModal(\'createModal\')"]')
        ?.addEventListener('click', function () {
            setTimeout(function () {
                if (_tplJoditCreate) {
                    try { _tplJoditCreate.destruct(); } catch (e) {}
                }
                _tplJoditCreate = Jodit.make('#createBody', _joditTplConfig());
                _tplJoditCreate.value = '';
                _tplJoditCreate.events.on('focus', function () {
                    window._tplActiveField = 'jodit-create';
                });
            }, 120);
        });

    // ── Variable chip insertion ───────────────────────────────────────────────
    document.querySelectorAll('.var-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            const variable = this.dataset.var;
            const modal    = this.dataset.modal; // 'create' or 'edit'

            const activeField = window._tplActiveField;

            // Determine target: jodit or subject input
            if (activeField === 'jodit-create' && modal === 'create' && _tplJoditCreate) {
                _tplJoditCreate.selection.insertHTML(variable);
                return;
            }
            if (activeField === 'jodit-edit' && modal === 'edit' && _tplJoditEdit) {
                _tplJoditEdit.selection.insertHTML(variable);
                return;
            }

            // Fallback: insert into the subject input of the corresponding modal
            const subjectId = modal === 'create' ? 'createSubject' : 'editSubject';
            const input     = document.getElementById(subjectId);
            if (input) {
                const start = input.selectionStart ?? input.value.length;
                const end   = input.selectionEnd   ?? input.value.length;
                input.value = input.value.slice(0, start) + variable + input.value.slice(end);
                input.focus();
                input.selectionStart = input.selectionEnd = start + variable.length;
            }
        });
    });

    // Track subject focus for variable target
    ['createSubject', 'editSubject'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('focus', function () {
            window._tplActiveField = id;
        });
    });

    // ── Delete confirm via SwalConfirm ────────────────────────────────────────
    document.querySelectorAll('.delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const name = this.dataset.name || 'this template';
            SwalConfirm(
                'Delete Template',
                'Delete "' + name + '"? This action cannot be undone.',
                function () { form.submit(); }
            );
        });
    });

    // Destroy Jodit when modals close to avoid memory leaks
    document.getElementById('createModal')?.addEventListener('click', function (e) {
        if (e.target === this) _destroyCreateJodit();
    });
    document.getElementById('editModal')?.addEventListener('click', function (e) {
        if (e.target === this) _destroyEditJodit();
    });
    document.querySelector('#createModal .modal-close')?.addEventListener('click', _destroyCreateJodit);
    document.querySelector('#editModal .modal-close')?.addEventListener('click', _destroyEditJodit);
});

function _destroyCreateJodit() {
    if (_tplJoditCreate) { try { _tplJoditCreate.destruct(); } catch (e) {} _tplJoditCreate = null; }
}
function _destroyEditJodit() {
    if (_tplJoditEdit) { try { _tplJoditEdit.destruct(); } catch (e) {} _tplJoditEdit = null; }
}

function _joditTplConfig() {
    return {
        height: 280,
        toolbarButtonSize: 'small',
        language: 'en',
        toolbarAdaptive: false,
        buttons: 'bold,italic,underline,strikethrough,|,ul,ol,|,font,fontsize,brush,|,image,table,link,|,align,|,undo,redo,|,eraser',
        uploader: { insertImageAsBase64URI: true },
        placeholder: 'Write template body here... use the variable chips above to insert placeholders.',
        statusbar: false,
        showCharsCounter: false,
        showWordsCounter: false,
        showXPathInStatusbar: false,
        addNewLine: false,
        maxHeight: 450,
        allowResizeY: true,
        allowResizeX: false,
    };
}
