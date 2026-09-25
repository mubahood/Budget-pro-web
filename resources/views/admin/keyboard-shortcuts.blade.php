{{-- Safe keyboard shortcuts: Alt + letter to move around, "/" to search, "?" for help.
     Nothing here saves, deletes, prints or reloads — browser keys (Ctrl+P, Ctrl+R, Ctrl+S…) keep working. --}}
<div class="modal fade" id="shortcuts-modal" tabindex="-1" role="dialog" aria-labelledby="shortcuts-title">
    <div class="modal-dialog" role="document" style="max-width: 480px;">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="shortcuts-title"><i class="fa fa-keyboard-o"></i> Keyboard shortcuts</h4>
            </div>
            <div class="modal-body">
                <table class="table table-condensed" style="margin-bottom:8px">
                    <tr><td style="width:140px"><kbd>Alt</kbd> + <kbd>S</kbd></td><td>New sale</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>P</kbd></td><td>Products</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>C</kbd></td><td>Customers</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>E</kbd></td><td>Record an expense</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>H</kbd></td><td>Dashboard</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>K</kbd> or <kbd>/</kbd></td><td>Search receipts, customers and products</td></tr>
                    <tr><td><kbd>?</kbd></td><td>Show this list</td></tr>
                    <tr><td><kbd>Esc</kbd></td><td>Close</td></tr>
                </table>
                <p class="text-muted" style="margin:0"><small>Shortcuts do nothing while you are typing in a box. Printing (<kbd>Ctrl</kbd>+<kbd>P</kbd>) and reloading work as normal.</small></p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    if (window.__bpShortcuts) { return; } // pjax re-renders the layout; bind once
    window.__bpShortcuts = true;

    var go = {
        KeyS: '{{ admin_url('sale-records/create') }}',
        KeyP: '{{ admin_url('stock-items') }}',
        KeyC: '{{ admin_url('customers') }}',
        KeyE: '{{ admin_url('financial-records/create') }}',
        KeyH: '{{ admin_url('/') }}'
    };

    function typing(el) {
        if (!el) { return false; }
        var tag = (el.tagName || '').toUpperCase();
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
    }

    function openSearch() {
        if (typeof window.openGlobalSearch === 'function') {
            window.openGlobalSearch();
            return;
        }
        var input = document.querySelector('.grid-quick-search input, input[name="_search_"]');
        if (input) { input.focus(); input.select(); }
    }

    document.addEventListener('keydown', function (e) {
        if (e.defaultPrevented || typing(document.activeElement) || e.ctrlKey || e.metaKey) {
            return;
        }
        if (e.altKey && !e.shiftKey) {
            if (go[e.code]) {
                e.preventDefault();
                window.location.href = go[e.code];
            } else if (e.code === 'KeyK') {
                e.preventDefault();
                openSearch();
            }
            return;
        }
        if (e.altKey) { return; }
        if (e.key === '?') {
            e.preventDefault();
            $('#shortcuts-modal').modal('show');
        } else if (e.key === '/' && !e.shiftKey) {
            e.preventDefault();
            openSearch();
        }
    });

    $(function () {
        var footer = $('.main-footer');
        if (footer.length && !footer.find('.bp-shortcuts-hint').length) {
            footer.append('<a href="javascript:void(0)" class="bp-shortcuts-hint" style="margin-left:15px;color:#999" onclick="$(\'#shortcuts-modal\').modal(\'show\')"><i class="fa fa-keyboard-o"></i> Press <kbd>?</kbd> for shortcuts</a>');
        }
    });
})();
</script>

<style>
    kbd {
        display: inline-block;
        padding: 2px 5px;
        font-size: 11px;
        line-height: 12px;
        color: #555;
        vertical-align: middle;
        background-color: #fcfcfc;
        border: solid 1px #ccc;
        border-bottom-color: #bbb;
        border-radius: 3px;
        box-shadow: inset 0 -1px 0 #bbb;
        font-family: monospace;
    }
</style>
