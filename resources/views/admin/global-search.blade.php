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
            placeholder="Search receipts, customers, products… (Ctrl+K, Alt+K or /)"
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

// Enter opens the first result
document.getElementById('global-search-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        const first = document.querySelector('#global-search-results a.search-result-item');
        if (first) {
            e.preventDefault();
            window.location.href = first.getAttribute('href');
        }
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

function escapeHtml(v) {
    return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (c) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
}

function searchRow(url, type, cssType, title, subtitle) {
    return '<a class="search-result-item" style="display:block;color:inherit;text-decoration:none" href="' + escapeHtml(url) + '">'
        + '<span class="search-result-type ' + cssType + '">' + type + '</span>'
        + '<div class="search-result-title">' + escapeHtml(title) + '</div>'
        + '<div class="search-result-subtitle">' + escapeHtml(subtitle) + '</div></a>';
}

function displaySearchResults(data) {
    const resultsDiv = document.getElementById('global-search-results');
    const money = '{{ \App\Support\Money::symbol() }} ';
    const groups = ['sales', 'products', 'customers', 'suppliers', 'categories'];
    if (!groups.some(function (g) { return data[g] && data[g].length; })) {
        resultsDiv.innerHTML = '<div class="search-empty"><i class="fa fa-search fa-2x" style="color: #ddd;"></i><br><br>No results found</div>';
        return;
    }

    let html = '';
    (data.sales || []).forEach(function (sale) {
        html += searchRow(sale.url, 'SALE', 'type-sale', 'Receipt ' + (sale.receipt_number || '#' + sale.id) + ' — ' + (sale.customer_name || 'Walk-in'),
            sale.date + ' • ' + money + formatNumber(sale.total) + (sale.status && sale.status !== 'Completed' ? ' • ' + sale.status : ''));
    });
    (data.products || []).forEach(function (product) {
        html += searchRow(product.url, 'PRODUCT', 'type-product', product.name,
            'SKU: ' + (product.sku || 'N/A') + ' • In stock: ' + formatNumber(product.current_quantity) + ' • Price: ' + money + formatNumber(product.selling_price));
    });
    (data.customers || []).forEach(function (c) {
        html += searchRow(c.url, 'CUSTOMER', 'type-category', c.name, (c.phone || '') + (c.balance > 0 ? ' • Owes ' + money + formatNumber(c.balance) : ''));
    });
    (data.suppliers || []).forEach(function (s) {
        html += searchRow(s.url, 'SUPPLIER', 'type-category', s.name, (s.phone || '') + (s.balance > 0 ? ' • We owe ' + money + formatNumber(s.balance) : ''));
    });
    (data.categories || []).forEach(function (category) {
        html += searchRow(category.url, 'CATEGORY', 'type-category', category.name, (category.products_count || 0) + ' products');
    });

    resultsDiv.innerHTML = html;
}

function formatNumber(num) {
    const n = Number(num || 0);
    return n.toLocaleString('en-US', {maximumFractionDigits: 2});
}
</script>
