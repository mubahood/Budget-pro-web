<!-- Global Search Command Palette -->
<style>
#global-search-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 9999;
    backdrop-filter: blur(5px);
}

#global-search-modal {
    position: fixed;
    top: 15%;
    left: 50%;
    transform: translateX(-50%);
    width: 600px;
    max-width: 90%;
    background: white;
    border-radius: 8px;
    box-shadow: 0 10px 50px rgba(0, 0, 0, 0.3);
    overflow: hidden;
}

#global-search-input {
    width: 100%;
    padding: 20px 25px;
    border: none;
    font-size: 18px;
    outline: none;
    border-bottom: 2px solid #e0e0e0;
}

#global-search-results {
    max-height: 400px;
    overflow-y: auto;
}

.search-result-item {
    padding: 15px 25px;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background 0.2s;
}

.search-result-item:hover {
    background: #f5f5f5;
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-result-type {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    margin-right: 10px;
}

.type-product {
    background: #4CAF50;
    color: white;
}

.type-category {
    background: #2196F3;
    color: white;
}

.type-sale {
    background: #FF9800;
    color: white;
}

.search-result-title {
    font-weight: bold;
    color: #333;
}

.search-result-subtitle {
    color: #666;
    font-size: 13px;
    margin-top: 5px;
}

.search-empty {
    padding: 40px;
    text-align: center;
    color: #999;
}

.search-shortcut {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #2196F3;
    color: white;
    padding: 10px 15px;
    border-radius: 5px;
    cursor: pointer;
    box-shadow: 0 4px 15px rgba(33, 150, 243, 0.4);
    transition: all 0.3s;
}

.search-shortcut:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(33, 150, 243, 0.6);
}

.search-loading {
    padding: 20px;
    text-align: center;
    color: #666;
}
</style>

<div id="global-search-overlay">
    <div id="global-search-modal">
        <input 
            type="text" 
            id="global-search-input" 
            placeholder="🔍 Search products, categories, sales... (Cmd+K or Ctrl+K)"
            autocomplete="off"
        >
        <div id="global-search-results"></div>
    </div>
</div>

<div class="search-shortcut" onclick="openGlobalSearch()">
    <i class="fa fa-search"></i> <kbd>⌘K</kbd>
</div>

<script>
// Global Search Functionality
let searchTimeout = null;

// Open search with keyboard shortcut
document.addEventListener('keydown', function(e) {
    // Cmd+K (Mac) or Ctrl+K (Windows/Linux)
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        openGlobalSearch();
    }
    
    // ESC to close
    if (e.key === 'Escape') {
        closeGlobalSearch();
    }
});

function openGlobalSearch() {
    document.getElementById('global-search-overlay').style.display = 'block';
    document.getElementById('global-search-input').focus();
    document.getElementById('global-search-results').innerHTML = '<div class="search-empty"><i class="fa fa-search fa-3x" style="color: #ddd;"></i><br><br>Start typing to search...</div>';
}

function closeGlobalSearch() {
    document.getElementById('global-search-overlay').style.display = 'none';
    document.getElementById('global-search-input').value = '';
    document.getElementById('global-search-results').innerHTML = '';
}

// Close when clicking outside
document.getElementById('global-search-overlay').addEventListener('click', function(e) {
    if (e.target === this) {
        closeGlobalSearch();
    }
});

// Search as user types
document.getElementById('global-search-input').addEventListener('input', function(e) {
    const query = e.target.value.trim();
    
    clearTimeout(searchTimeout);
    
    if (query.length < 2) {
        document.getElementById('global-search-results').innerHTML = '<div class="search-empty">Type at least 2 characters...</div>';
        return;
    }
    
    // Show loading
    document.getElementById('global-search-results').innerHTML = '<div class="search-loading"><i class="fa fa-spinner fa-spin"></i> Searching...</div>';
    
    // Debounce search
    searchTimeout = setTimeout(function() {
        performGlobalSearch(query);
    }, 300);
});

function performGlobalSearch(query) {
    fetch('<?php echo url('/api/global-search'); ?>?q=' + encodeURIComponent(query), {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        displaySearchResults(data);
    })
    .catch(error => {
        console.error('Search error:', error);
        document.getElementById('global-search-results').innerHTML = '<div class="search-empty text-danger"><i class="fa fa-exclamation-triangle"></i> Search error. Please try again.</div>';
    });
}

function displaySearchResults(data) {
    const resultsDiv = document.getElementById('global-search-results');
    
    if (!data.products.length && !data.categories.length && !data.sales.length) {
        resultsDiv.innerHTML = '<div class="search-empty"><i class="fa fa-search fa-2x" style="color: #ddd;"></i><br><br>No results found</div>';
        return;
    }
    
    let html = '';
    
    // Products
    if (data.products.length > 0) {
        data.products.forEach(product => {
            html += `
                <div class="search-result-item" onclick="window.location.href='<?php echo admin_url('stock-items'); ?>/${product.id}/edit'">
                    <span class="search-result-type type-product">PRODUCT</span>
                    <div class="search-result-title">${product.name}</div>
                    <div class="search-result-subtitle">
                        SKU: ${product.sku || 'N/A'} • Stock: ${product.current_quantity} units • Price: UGX ${formatNumber(product.selling_price)}
                    </div>
                </div>
            `;
        });
    }
    
    // Categories
    if (data.categories.length > 0) {
        data.categories.forEach(category => {
            html += `
                <div class="search-result-item" onclick="window.location.href='<?php echo admin_url('stock-sub-categories'); ?>/${category.id}/edit'">
                    <span class="search-result-type type-category">CATEGORY</span>
                    <div class="search-result-title">${category.name}</div>
                    <div class="search-result-subtitle">${category.products_count || 0} products</div>
                </div>
            `;
        });
    }
    
    // Sales (Stock Records)
    if (data.sales.length > 0) {
        data.sales.forEach(sale => {
            html += `
                <div class="search-result-item" onclick="window.location.href='<?php echo admin_url('stock-records'); ?>/${sale.id}/edit'">
                    <span class="search-result-type type-sale">SALE</span>
                    <div class="search-result-title">${sale.product_name}</div>
                    <div class="search-result-subtitle">
                        Date: ${sale.date} • Quantity: ${sale.quantity} • Total: UGX ${formatNumber(sale.total)}
                    </div>
                </div>
            `;
        });
    }
    
    resultsDiv.innerHTML = html;
}

function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}
</script>
