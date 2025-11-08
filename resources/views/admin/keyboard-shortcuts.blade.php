<div id="keyboard-shortcuts-help" style="display: none;">
    <div class="modal fade" id="shortcuts-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document" style="width: 600px;">
            <div class="modal-content">
                <div class="modal-header" style="background: #3c8dbc; color: white;">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true" style="color: white;">&times;</span>
                    </button>
                    <h4 class="modal-title">
                        <i class="fa fa-keyboard-o"></i> Keyboard Shortcuts
                    </h4>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 20px;">
                        <strong>Speed up your workflow with these keyboard shortcuts:</strong>
                    </p>

                    <div class="row">
                        <div class="col-md-6">
                            <h5 style="border-bottom: 2px solid #3c8dbc; padding-bottom: 5px; margin-bottom: 15px;">
                                <i class="fa fa-search"></i> Search & Navigation
                            </h5>
                            <table class="table table-condensed table-bordered">
                                <tr>
                                    <td style="width: 120px;"><kbd>Cmd/Ctrl</kbd> + <kbd>K</kbd></td>
                                    <td>Global Search</td>
                                </tr>
                                <tr>
                                    <td><kbd>Cmd/Ctrl</kbd> + <kbd>/</kbd></td>
                                    <td>Focus Quick Search</td>
                                </tr>
                                <tr>
                                    <td><kbd>Cmd/Ctrl</kbd> + <kbd>H</kbd></td>
                                    <td>Go to Dashboard</td>
                                </tr>
                                <tr>
                                    <td><kbd>Cmd/Ctrl</kbd> + <kbd>P</kbd></td>
                                    <td>Go to Products</td>
                                </tr>
                                <tr>
                                    <td><kbd>ESC</kbd></td>
                                    <td>Close Modals</td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-6">
                            <h5 style="border-bottom: 2px solid #00a65a; padding-bottom: 5px; margin-bottom: 15px;">
                                <i class="fa fa-plus-circle"></i> Quick Actions
                            </h5>
                            <table class="table table-condensed table-bordered">
                                <tr>
                                    <td style="width: 120px;"><kbd>Cmd/Ctrl</kbd> + <kbd>N</kbd></td>
                                    <td>Quick Add Product</td>
                                </tr>
                                <tr>
                                    <td><kbd>Cmd/Ctrl</kbd> + <kbd>S</kbd></td>
                                    <td>Save Form</td>
                                </tr>
                                <tr>
                                    <td><kbd>Cmd/Ctrl</kbd> + <kbd>E</kbd></td>
                                    <td>Export Data</td>
                                </tr>
                                <tr>
                                    <td><kbd>Cmd/Ctrl</kbd> + <kbd>R</kbd></td>
                                    <td>Refresh Grid</td>
                                </tr>
                                <tr>
                                    <td><kbd>?</kbd></td>
                                    <td>Show This Help</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row" style="margin-top: 20px;">
                        <div class="col-md-12">
                            <h5 style="border-bottom: 2px solid #f39c12; padding-bottom: 5px; margin-bottom: 15px;">
                                <i class="fa fa-list"></i> Grid Navigation
                            </h5>
                            <table class="table table-condensed table-bordered">
                                <tr>
                                    <td style="width: 120px;"><kbd>↑</kbd> / <kbd>↓</kbd></td>
                                    <td>Navigate rows (when grid is focused)</td>
                                </tr>
                                <tr>
                                    <td><kbd>Cmd/Ctrl</kbd> + <kbd>A</kbd></td>
                                    <td>Select all items</td>
                                </tr>
                                <tr>
                                    <td><kbd>Cmd/Ctrl</kbd> + <kbd>D</kbd></td>
                                    <td>Deselect all items</td>
                                </tr>
                                <tr>
                                    <td><kbd>Delete</kbd></td>
                                    <td>Delete selected items (with confirmation)</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="alert alert-info" style="margin-top: 20px; margin-bottom: 0;">
                        <i class="fa fa-info-circle"></i> 
                        <strong>Tip:</strong> Press <kbd>?</kbd> anytime to show this shortcuts help panel.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">
                        <i class="fa fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    // Keyboard shortcuts handler
    document.addEventListener('keydown', function(e) {
        // Check if user is typing in an input/textarea
        const activeElement = document.activeElement;
        const isTyping = activeElement.tagName === 'INPUT' || 
                        activeElement.tagName === 'TEXTAREA' || 
                        activeElement.isContentEditable;

        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const modifier = isMac ? e.metaKey : e.ctrlKey;

        // ? - Show keyboard shortcuts help
        if (e.key === '?' && !isTyping) {
            e.preventDefault();
            $('#shortcuts-modal').modal('show');
            return;
        }

        // Cmd/Ctrl + K - Already handled by global search
        // Cmd/Ctrl + / - Focus quick search
        if (modifier && e.key === '/') {
            e.preventDefault();
            const searchInput = document.querySelector('.grid-quick-search input');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
            return;
        }

        // Cmd/Ctrl + H - Go to Dashboard
        if (modifier && e.key === 'h') {
            e.preventDefault();
            window.location.href = '{{ admin_url('/') }}';
            return;
        }

        // Cmd/Ctrl + P - Go to Products
        if (modifier && e.key === 'p') {
            e.preventDefault();
            window.location.href = '{{ admin_url('stock-items') }}';
            return;
        }

        // Cmd/Ctrl + N - Quick Add Product (if on stock-items page)
        if (modifier && e.key === 'n' && !isTyping) {
            e.preventDefault();
            const quickAddBtn = document.getElementById('quick-add-product-btn');
            if (quickAddBtn) {
                quickAddBtn.click();
            }
            return;
        }

        // Cmd/Ctrl + S - Save form (if form exists)
        if (modifier && e.key === 's') {
            e.preventDefault();
            const submitBtn = document.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.click();
            }
            return;
        }

        // Cmd/Ctrl + E - Export (if export button exists)
        if (modifier && e.key === 'e' && !isTyping) {
            e.preventDefault();
            const exportBtn = document.querySelector('.grid-export');
            if (exportBtn) {
                exportBtn.click();
            }
            return;
        }

        // Cmd/Ctrl + R - Refresh grid
        if (modifier && e.key === 'r') {
            e.preventDefault();
            const refreshBtn = document.querySelector('.grid-refresh');
            if (refreshBtn) {
                refreshBtn.click();
            } else {
                $.pjax.reload({container:'#pjax-container'});
            }
            return;
        }

        // Cmd/Ctrl + A - Select all (in grid)
        if (modifier && e.key === 'a' && !isTyping) {
            const gridCheckAll = document.querySelector('.grid-select-all');
            if (gridCheckAll && window.getSelection().toString() === '') {
                e.preventDefault();
                gridCheckAll.click();
            }
            return;
        }

        // Cmd/Ctrl + D - Deselect all
        if (modifier && e.key === 'd' && !isTyping) {
            e.preventDefault();
            const checkboxes = document.querySelectorAll('.grid-row-checkbox:checked');
            checkboxes.forEach(cb => cb.checked = false);
            return;
        }

        // Delete key - Delete selected items (with confirmation)
        if (e.key === 'Delete' && !isTyping) {
            const selectedCheckboxes = document.querySelectorAll('.grid-row-checkbox:checked');
            if (selectedCheckboxes.length > 0) {
                e.preventDefault();
                const batchDeleteBtn = document.querySelector('[data-action*="delete"]');
                if (batchDeleteBtn) {
                    batchDeleteBtn.click();
                }
            }
            return;
        }
    });

    // Add keyboard shortcuts indicator to footer
    $(document).ready(function() {
        if ($('.main-footer').length) {
            $('.main-footer').append(
                '<span style="margin-left: 15px; color: #999;">' +
                '<i class="fa fa-keyboard-o"></i> ' +
                'Press <kbd>?</kbd> for keyboard shortcuts' +
                '</span>'
            );
        }
    });
})();
</script>

<style>
    kbd {
        display: inline-block;
        padding: 3px 5px;
        font-size: 11px;
        line-height: 10px;
        color: #555;
        vertical-align: middle;
        background-color: #fcfcfc;
        border: solid 1px #ccc;
        border-bottom-color: #bbb;
        border-radius: 3px;
        box-shadow: inset 0 -1px 0 #bbb;
        font-family: monospace;
    }
    
    #shortcuts-modal .table-condensed td {
        padding: 8px;
        font-size: 13px;
    }
    
    #shortcuts-modal .table-bordered {
        border: 1px solid #ddd;
    }
</style>
