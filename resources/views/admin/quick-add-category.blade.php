{{-- Quick Category Add Modal --}}
<style>
    .quick-category-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        animation: fadeIn 0.2s;
    }
    
    .quick-category-content {
        background-color: #fff;
        margin: 5% auto;
        padding: 0;
        border-radius: 8px;
        width: 90%;
        max-width: 500px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        animation: slideDown 0.3s;
    }
    
    .quick-category-header {
        padding: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 8px 8px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .quick-category-header h3 {
        margin: 0;
        font-size: 20px;
        font-weight: 600;
    }
    
    .quick-category-close {
        color: white;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        line-height: 1;
        opacity: 0.8;
        transition: opacity 0.2s;
    }
    
    .quick-category-close:hover {
        opacity: 1;
    }
    
    .quick-category-body {
        padding: 25px;
    }
    
    .quick-category-form-group {
        margin-bottom: 20px;
    }
    
    .quick-category-form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #333;
        font-size: 14px;
    }
    
    .quick-category-form-group label .required {
        color: #e74c3c;
        margin-left: 3px;
    }
    
    .quick-category-form-group input,
    .quick-category-form-group select,
    .quick-category-form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        transition: border-color 0.2s;
    }
    
    .quick-category-form-group input:focus,
    .quick-category-form-group select:focus,
    .quick-category-form-group textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .quick-category-form-group textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    .quick-category-footer {
        padding: 15px 25px;
        background-color: #f8f9fa;
        border-radius: 0 0 8px 8px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .quick-category-btn {
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .quick-category-btn-cancel {
        background-color: #e0e0e0;
        color: #333;
    }
    
    .quick-category-btn-cancel:hover {
        background-color: #d0d0d0;
    }
    
    .quick-category-btn-submit {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    
    .quick-category-btn-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
    }
    
    .quick-category-btn-submit:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    
    .quick-category-hint {
        font-size: 12px;
        color: #6c757d;
        margin-top: 5px;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes slideDown {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
</style>

<div id="quickCategoryModal" class="quick-category-modal">
    <div class="quick-category-content">
        <div class="quick-category-header">
            <h3>🏷️ Quick Add Category</h3>
            <span class="quick-category-close" onclick="closeQuickCategoryModal()">&times;</span>
        </div>
        
        <form id="quickCategoryForm">
            <div class="quick-category-body">
                <div class="quick-category-form-group">
                    <label for="category_name">
                        Category Name<span class="required">*</span>
                    </label>
                    <input type="text" id="category_name" name="name" required 
                           placeholder="e.g., Electronics, Beverages, Office Supplies">
                    <div class="quick-category-hint">Enter a descriptive category name</div>
                </div>
                
                <div class="quick-category-form-group">
                    <label for="category_parent">Main category</label>
                    <select id="category_parent" name="parent_id">
                        <option value="">-- None (this is a main category) --</option>
                        <!-- Options will be loaded via AJAX -->
                    </select>
                    <div class="quick-category-hint">Products are filed under a sub-category: pick its main category here.</div>
                </div>

                <div class="quick-category-form-group" id="category_unit_group">
                    <label for="category_unit">Unit</label>
                    <input type="text" id="category_unit" name="measurement_unit" placeholder="e.g. pcs, kg, litres" value="pcs">
                    <div class="quick-category-hint">How products in this sub-category are counted.</div>
                </div>
                
                <div class="quick-category-form-group">
                    <label for="category_description">Description</label>
                    <textarea id="category_description" name="description" 
                              placeholder="Brief description of this category..."></textarea>
                </div>
                
                <div class="quick-category-form-group">
                    <label for="category_status">Status</label>
                    <select id="category_status" name="status">
                        <option value="Active" selected>✅ Active</option>
                        <option value="Inactive">❌ Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="quick-category-footer">
                <button type="button" class="quick-category-btn quick-category-btn-cancel" 
                        onclick="closeQuickCategoryModal()">Cancel</button>
                <button type="submit" class="quick-category-btn quick-category-btn-submit">
                    ✨ Create Category
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Quick Category Add Modal Functions
function openQuickCategoryModal() {
    document.getElementById('quickCategoryModal').style.display = 'block';
    document.getElementById('category_name').focus();
    loadParentCategories();
}

function closeQuickCategoryModal() {
    document.getElementById('quickCategoryModal').style.display = 'none';
    document.getElementById('quickCategoryForm').reset();
}

// Close modal when clicking outside (without replacing other pages' click handlers)
window.addEventListener('click', function(event) {
    const modal = document.getElementById('quickCategoryModal');
    if (event.target === modal) {
        closeQuickCategoryModal();
    }
});

// Load parent categories
function loadParentCategories() {
    fetch('{{ admin_url("ajax/categories") }}', {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('category_parent');
            select.innerHTML = '<option value="">-- None (this is a main category) --</option>';
            
            if (data.data && Array.isArray(data.data)) {
                data.data.forEach(category => {
                    const option = document.createElement('option');
                    option.value = category.id;
                    option.textContent = category.name;
                    select.appendChild(option);
                });
            }
        })
        .catch(error => {
            console.error('Error loading categories:', error);
        });
}

// Handle form submission
document.getElementById('quickCategoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const submitBtn = this.querySelector('.quick-category-btn-submit');
    submitBtn.disabled = true;
    submitBtn.textContent = '⏳ Creating...';
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData.entries());
    
    fetch('{{ admin_url("ajax/categories") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.status === 'success' && result.id) {
            toastr.success(data.parent_id ? 'Sub-category "' + result.name + '" added — search for it in the category box.' : 'Category "' + result.name + '" added. Now add a sub-category under it.');
            closeQuickCategoryModal();
            
            // Refresh category dropdowns on the page
            if (typeof refreshCategoryDropdowns === 'function') {
                refreshCategoryDropdowns();
            }
            
            // Refresh grid if present
            if (typeof $.pjax !== 'undefined') {
                $.pjax.reload('#pjax-container');
            }
        } else {
            throw new Error(result.message || 'Failed to create category');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        toastr.error('❌ ' + error.message);
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = '✨ Create Category';
    });
});

// Keyboard shortcut: Ctrl/Cmd + Shift + C for Quick Category Add
document.addEventListener('keydown', function(e) {
    // Ignore if user is typing in an input field
    if (e.target.matches('input, textarea, select')) return;
    
    // Ctrl/Cmd + Shift + C
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'C') {
        e.preventDefault();
        openQuickCategoryModal();
    }
});
</script>
