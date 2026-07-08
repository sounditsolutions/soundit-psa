@props([
    'name',
    'id' => null,
    'value' => '',
    'rows' => 4,
    'placeholder' => '',
    'required' => false,
    'toolbar' => 'standard',
    'lazy' => false,
    'uploadUrl' => null,
    'noteId' => null,
])

@php $editorId = $id ?? $name; @endphp

<textarea
    name="{{ $name }}"
    id="{{ $editorId }}"
    class="form-control {{ $lazy ? 'markdown-editor-lazy' : 'markdown-editor-target' }} {{ $errors->has($name) ? 'is-invalid' : '' }}"
    rows="{{ $rows }}"
    placeholder="{{ $placeholder }}"
    {{ $required ? 'required' : '' }}
    data-toolbar="{{ $toolbar }}"
    @if($uploadUrl) data-upload-url="{{ $uploadUrl }}" @endif
    @if($noteId) data-note-id="{{ $noteId }}" @endif
>{{ old($name, $value) }}</textarea>

@error($name)
    <div class="invalid-feedback">{{ $message }}</div>
@enderror

@once
@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<style>
.EasyMDEContainer .CodeMirror {
    border: 1px solid #d1d5db;
    border-radius: 0 0 8px 8px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 0.875rem;
    color: var(--text-body, #374151);
}
.EasyMDEContainer .CodeMirror-focused {
    border-color: var(--primary-light, #234179);
    box-shadow: 0 0 0 3px rgba(26, 54, 93, 0.12);
}
.EasyMDEContainer .editor-toolbar {
    border: 1px solid #d1d5db;
    border-bottom: none;
    border-radius: 8px 8px 0 0;
    background: #f8f9fa;
}
.EasyMDEContainer .editor-toolbar button {
    font-size: 1rem;
    line-height: 1;
    width: auto;
    height: auto;
    padding: 4px 6px;
}
.EasyMDEContainer .editor-toolbar button.active,
.EasyMDEContainer .editor-toolbar button:hover {
    background: rgba(26, 54, 93, 0.1);
    border-color: var(--primary, #1a365d);
}
.EasyMDEContainer .editor-preview {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 0.875rem;
    color: var(--text-body, #374151);
    padding: 0.75rem;
}
.EasyMDEContainer .editor-preview pre {
    background: #f8f9fa;
    padding: 0.5rem;
    border-radius: 4px;
}
.EasyMDEContainer .editor-preview blockquote {
    border-left: 3px solid #d1d5db;
    padding-left: 0.75rem;
    color: var(--text-muted, #6b7280);
}
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Custom toolbar buttons using Bootstrap Icons (already loaded in layout)
    var btn = function(name, action, icon, title) {
        return { name: name, action: action, className: 'bi bi-' + icon, title: title };
    };
    var buttons = {
        'bold':           btn('bold', EasyMDE.toggleBold, 'type-bold', 'Bold'),
        'italic':         btn('italic', EasyMDE.toggleItalic, 'type-italic', 'Italic'),
        'heading':        btn('heading', EasyMDE.toggleHeadingSmaller, 'type-h2', 'Heading'),
        'unordered-list': btn('unordered-list', EasyMDE.toggleUnorderedList, 'list-ul', 'Unordered List'),
        'ordered-list':   btn('ordered-list', EasyMDE.toggleOrderedList, 'list-ol', 'Ordered List'),
        'link':           btn('link', EasyMDE.drawLink, 'link-45deg', 'Link'),
        'code':           btn('code', EasyMDE.toggleCodeBlock, 'code-slash', 'Code'),
        'quote':          btn('quote', EasyMDE.toggleBlockquote, 'blockquote-left', 'Quote'),
        'preview':        { name: 'preview', action: EasyMDE.togglePreview, className: 'bi bi-eye no-disable', noDisable: true, title: 'Preview' },
        'side-by-side':   { name: 'side-by-side', action: EasyMDE.toggleSideBySide, className: 'bi bi-layout-split no-disable', noDisable: true, noMobile: true, title: 'Side by Side' },
    };

    function buildToolbar(names) {
        return names.map(function(n) { return n === '|' ? '|' : buttons[n]; });
    }

    var toolbars = {
        minimal: buildToolbar(['bold', 'italic', '|', 'unordered-list', '|', 'preview']),
        standard: buildToolbar(['bold', 'italic', 'heading', '|', 'unordered-list', 'ordered-list', '|', 'link', 'code', 'quote', '|', 'preview']),
        email: buildToolbar(['bold', 'italic', 'heading', '|', 'unordered-list', 'ordered-list', '|', 'link', 'code', 'quote', '|', 'preview'])
    };

    function initEasyMDE(el) {
        if (el.dataset.easymdeInit) return;
        el.dataset.easymdeInit = 'true';

        var toolbarType = el.dataset.toolbar || 'standard';
        var rows = parseInt(el.rows) || 4;

        var editor = new EasyMDE({
            element: el,
            toolbar: toolbars[toolbarType] || toolbars.standard,
            spellChecker: false,
            status: false,
            placeholder: el.placeholder || '',
            minHeight: (rows * 24) + 'px',
            autoDownloadFontAwesome: false,
            forceSync: true,
            renderingConfig: {
                codeSyntaxHighlighting: false
            }
        });

        // Expose instance for external access (e.g., AI draft reply)
        el.easyMDE = editor;

        // Image paste/drop upload
        var uploadUrl = el.dataset.uploadUrl;
        if (uploadUrl && editor.codemirror) {
            var cm = editor.codemirror;

            function uploadImage(file) {
                var formData = new FormData();
                formData.append('file', file);
                if (el.dataset.noteId) formData.append('note_id', el.dataset.noteId);

                // Insert placeholder
                var placeholder = '![Uploading ' + file.name + '...]()';
                cm.replaceSelection(placeholder);

                fetch(uploadUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var content = cm.getValue();
                    cm.setValue(content.replace(placeholder, data.markdown));
                })
                .catch(function(err) {
                    var content = cm.getValue();
                    cm.setValue(content.replace(placeholder, ''));
                    console.error('Image upload failed:', err);
                });
            }

            cm.on('paste', function(cm, event) {
                var items = (event.clipboardData || event.originalEvent.clipboardData).items;
                for (var i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf('image') === 0) {
                        event.preventDefault();
                        uploadImage(items[i].getAsFile());
                        return;
                    }
                }
            });

            cm.on('drop', function(cm, event) {
                var files = event.dataTransfer.files;
                for (var i = 0; i < files.length; i++) {
                    if (files[i].type.indexOf('image') === 0) {
                        event.preventDefault();
                        uploadImage(files[i]);
                        return;
                    }
                }
            });
        }

        // Ensure sync before form submit
        var form = el.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                el.value = editor.value();
            });
        }
    }

    // Initialize editors visible on page load
    document.querySelectorAll('.markdown-editor-target').forEach(initEasyMDE);

    // Initialize lazy editors when their Bootstrap modal opens
    document.addEventListener('shown.bs.modal', function(event) {
        event.target.querySelectorAll('.markdown-editor-lazy').forEach(function(el) {
            initEasyMDE(el);
            // CodeMirror needs a refresh after becoming visible
            if (el.easyMDE) el.easyMDE.codemirror.refresh();
        });
    });
});
</script>
@endpush
@endonce
