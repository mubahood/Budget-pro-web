/**
 * Budget Pro Web - Custom Admin Enhancements
 * 
 * This file contains custom JavaScript enhancements for Laravel Admin
 * including loading states, better UX, mobile optimization, and performance improvements.
 * 
 * @version 1.0.0
 * @date 2025-11-07
 */

(function() {
    'use strict';

    /**
     * ============================================
     * UTILITY FUNCTIONS
     * ============================================
     */

    // Debounce function for search inputs
    const debounce = (func, wait) => {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    };

    // Throttle function for scroll events
    const throttle = (func, limit) => {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    };

    // Show loading indicator
    const showLoading = (element, message = 'Loading...') => {
        const loadingHTML = `
            <div class="loading-overlay">
                <div class="loading-spinner">
                    <i class="fa fa-spinner fa-spin fa-3x"></i>
                    <p>${message}</p>
                </div>
            </div>
        `;
        
        if (element) {
            element.style.position = 'relative';
            element.insertAdjacentHTML('beforeend', loadingHTML);
        } else {
            document.body.insertAdjacentHTML('beforeend', loadingHTML);
        }
    };

    // Hide loading indicator
    const hideLoading = (element) => {
        const overlay = element ? 
            element.querySelector('.loading-overlay') : 
            document.querySelector('.loading-overlay');
        
        if (overlay) {
            overlay.remove();
        }
    };

    // Show success notification
    const showSuccess = (message, duration = 3000) => {
        toastr.success(message, 'Success', {
            timeOut: duration,
            progressBar: true,
            positionClass: 'toast-top-right'
        });
    };

    // Show error notification
    const showError = (message, duration = 5000) => {
        toastr.error(message, 'Error', {
            timeOut: duration,
            progressBar: true,
            positionClass: 'toast-top-right'
        });
    };

    // Show warning notification
    const showWarning = (message, duration = 4000) => {
        toastr.warning(message, 'Warning', {
            timeOut: duration,
            progressBar: true,
            positionClass: 'toast-top-right'
        });
    };

    /**
     * ============================================
     * FORM ENHANCEMENTS
     * ============================================
     */

    // Auto-save form data to localStorage
    const enableAutoSave = () => {
        const forms = document.querySelectorAll('form[data-autosave="true"]');
        
        forms.forEach(form => {
            const formId = form.id || 'form-' + Date.now();
            form.id = formId;
            
            // Load saved data
            const savedData = localStorage.getItem(`autosave-${formId}`);
            if (savedData) {
                try {
                    const data = JSON.parse(savedData);
                    Object.keys(data).forEach(key => {
                        const input = form.querySelector(`[name="${key}"]`);
                        if (input && !input.value) {
                            input.value = data[key];
                        }
                    });
                    showWarning('Form data restored from previous session');
                } catch (e) {
                    console.error('Failed to restore form data:', e);
                }
            }
            
            // Save data on input
            form.addEventListener('input', debounce(() => {
                const formData = new FormData(form);
                const data = {};
                formData.forEach((value, key) => {
                    data[key] = value;
                });
                localStorage.setItem(`autosave-${formId}`, JSON.stringify(data));
            }, 1000));
            
            // Clear saved data on successful submit
            form.addEventListener('submit', () => {
                localStorage.removeItem(`autosave-${formId}`);
            });
        });
    };

    // Add form validation feedback
    const enhanceFormValidation = () => {
        const forms = document.querySelectorAll('form');
        
        forms.forEach(form => {
            const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
            
            inputs.forEach(input => {
                input.addEventListener('blur', function() {
                    const formGroup = this.closest('.form-group');
                    if (!formGroup) return;
                    
                    if (this.validity.valid) {
                        formGroup.classList.remove('has-error');
                        formGroup.classList.add('has-success');
                    } else {
                        formGroup.classList.remove('has-success');
                        formGroup.classList.add('has-error');
                    }
                });
            });
            
            // Show loading on submit
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
                    showLoading(null, 'Saving data...');
                }
            });
        });
    };

    // Add character counter to textareas
    const addCharacterCounter = () => {
        const textareas = document.querySelectorAll('textarea[maxlength]');
        
        textareas.forEach(textarea => {
            const maxLength = textarea.getAttribute('maxlength');
            const counter = document.createElement('div');
            counter.className = 'character-counter text-muted small';
            counter.style.textAlign = 'right';
            counter.style.marginTop = '5px';
            
            const updateCounter = () => {
                const remaining = maxLength - textarea.value.length;
                counter.textContent = `${textarea.value.length} / ${maxLength} characters`;
                counter.style.color = remaining < 50 ? '#d9534f' : '#999';
            };
            
            textarea.parentNode.insertBefore(counter, textarea.nextSibling);
            textarea.addEventListener('input', updateCounter);
            updateCounter();
        });
    };

    /**
     * ============================================
     * SEARCH OPTIMIZATION
     * ============================================
     */

    // Optimize quick search with debounce
    const optimizeQuickSearch = () => {
        const searchInputs = document.querySelectorAll('.grid-quick-search input');
        
        searchInputs.forEach(input => {
            const originalInput = input.oninput;
            
            input.oninput = debounce(function(e) {
                showLoading(document.querySelector('.box-body'), 'Searching...');
                
                if (originalInput) {
                    originalInput.call(this, e);
                }
                
                setTimeout(() => hideLoading(document.querySelector('.box-body')), 500);
            }, 500);
        });
    };

    // Add clear search button
    const addClearSearchButton = () => {
        const searchInputs = document.querySelectorAll('.grid-quick-search input');
        
        searchInputs.forEach(input => {
            if (input.nextElementSibling?.classList.contains('clear-search')) {
                return; // Already added
            }
            
            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.className = 'btn btn-default btn-sm clear-search';
            clearBtn.innerHTML = '<i class="fa fa-times"></i>';
            clearBtn.style.marginLeft = '5px';
            clearBtn.style.display = input.value ? 'inline-block' : 'none';
            
            clearBtn.addEventListener('click', () => {
                input.value = '';
                input.dispatchEvent(new Event('input'));
                clearBtn.style.display = 'none';
            });
            
            input.addEventListener('input', () => {
                clearBtn.style.display = input.value ? 'inline-block' : 'none';
            });
            
            input.parentNode.insertBefore(clearBtn, input.nextSibling);
        });
    };

    /**
     * ============================================
     * TABLE ENHANCEMENTS
     * ============================================
     */

    // Make tables responsive
    const makeTablesResponsive = () => {
        const tables = document.querySelectorAll('.table:not(.responsive-wrapper .table)');
        
        tables.forEach(table => {
            if (!table.parentElement.classList.contains('table-responsive')) {
                const wrapper = document.createElement('div');
                wrapper.className = 'table-responsive';
                table.parentNode.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            }
        });
    };

    // Add row hover effect
    const enhanceTableRows = () => {
        const tables = document.querySelectorAll('.table');
        
        tables.forEach(table => {
            table.classList.add('table-hover');
        });
    };

    // Add loading state to pagination links
    const enhancePagination = () => {
        const paginationLinks = document.querySelectorAll('.pagination a');
        
        paginationLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                showLoading(document.querySelector('.box-body'), 'Loading page...');
            });
        });
    };

    /**
     * ============================================
     * MOBILE OPTIMIZATIONS
     * ============================================
     */

    // Optimize sidebar for mobile
    const optimizeMobileSidebar = () => {
        const sidebar = document.querySelector('.main-sidebar');
        const body = document.body;
        
        if (window.innerWidth < 768) {
            // Auto-collapse sidebar on mobile
            if (!body.classList.contains('sidebar-collapse')) {
                body.classList.add('sidebar-collapse');
            }
            
            // Close sidebar when clicking outside
            document.addEventListener('click', function(e) {
                if (!sidebar?.contains(e.target) && 
                    !e.target.closest('.sidebar-toggle')) {
                    body.classList.add('sidebar-collapse');
                }
            });
        }
    };

    // Add touch-friendly buttons
    const enhanceMobileButtons = () => {
        if (window.innerWidth < 768) {
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(btn => {
                if (!btn.classList.contains('btn-lg')) {
                    btn.style.minHeight = '44px';
                    btn.style.minWidth = '44px';
                }
            });
        }
    };

    // Optimize select2 for mobile
    const optimizeSelect2Mobile = () => {
        if (typeof $.fn.select2 !== 'undefined') {
            $('.select2').each(function() {
                const $select = $(this);
                if (!$select.data('select2')) {
                    return;
                }
                
                // Add touch-friendly config for mobile
                if (window.innerWidth < 768) {
                    $select.select2('destroy');
                    $select.select2({
                        width: '100%',
                        dropdownAutoWidth: true,
                        minimumResultsForSearch: 5
                    });
                }
            });
        }
    };

    /**
     * ============================================
     * PERFORMANCE OPTIMIZATIONS
     * ============================================
     */

    // Lazy load images
    const lazyLoadImages = () => {
        const images = document.querySelectorAll('img[data-src]');
        
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.classList.add('loaded');
                    observer.unobserve(img);
                }
            });
        });
        
        images.forEach(img => imageObserver.observe(img));
    };

    // Optimize long lists with virtual scrolling
    const optimizeLongLists = () => {
        const lists = document.querySelectorAll('.grid-table tbody');
        
        lists.forEach(list => {
            const rows = list.querySelectorAll('tr');
            if (rows.length > 100) {
                // Add pagination warning
                const warning = document.createElement('div');
                warning.className = 'alert alert-info';
                warning.innerHTML = `
                    <i class="fa fa-info-circle"></i>
                    <strong>Performance Tip:</strong> 
                    This page has ${rows.length} rows. Consider using filters or pagination for better performance.
                `;
                list.parentElement.insertBefore(warning, list);
            }
        });
    };

    // Cache AJAX requests
    const ajaxCache = new Map();
    const cacheAjaxRequests = () => {
        if (typeof $ !== 'undefined' && $.ajax) {
            const originalAjax = $.ajax;
            
            $.ajax = function(options) {
                if (options.cache !== false && options.type === 'GET') {
                    const cacheKey = options.url + JSON.stringify(options.data || {});
                    
                    if (ajaxCache.has(cacheKey)) {
                        const deferred = $.Deferred();
                        deferred.resolve(ajaxCache.get(cacheKey));
                        return deferred.promise();
                    }
                    
                    return originalAjax.call($, options).done(function(data) {
                        ajaxCache.set(cacheKey, data);
                        // Clear cache after 5 minutes
                        setTimeout(() => ajaxCache.delete(cacheKey), 300000);
                    });
                }
                
                return originalAjax.call($, options);
            };
        }
    };

    /**
     * ============================================
     * UI IMPROVEMENTS
     * ============================================
     */

    // Add smooth scrolling
    const enableSmoothScrolling = () => {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    };

    // Add back to top button
    const addBackToTop = () => {
        const button = document.createElement('button');
        button.className = 'btn btn-primary back-to-top';
        button.innerHTML = '<i class="fa fa-arrow-up"></i>';
        button.style.cssText = `
            position: fixed;
            bottom: 30px;
            right: 30px;
            display: none;
            z-index: 1000;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        `;
        
        button.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
        
        document.body.appendChild(button);
        
        // Show/hide based on scroll position
        window.addEventListener('scroll', throttle(() => {
            button.style.display = window.pageYOffset > 300 ? 'block' : 'none';
        }, 100));
    };

    // Enhance box collapse/expand
    const enhanceBoxes = () => {
        const boxes = document.querySelectorAll('.box');
        
        boxes.forEach(box => {
            const collapseBtn = box.querySelector('[data-widget="collapse"]');
            if (collapseBtn) {
                collapseBtn.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    if (icon) {
                        icon.classList.toggle('fa-minus');
                        icon.classList.toggle('fa-plus');
                    }
                });
            }
        });
    };

    // Add keyboard shortcuts
    const addKeyboardShortcuts = () => {
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K: Focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('.grid-quick-search input');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            
            // Ctrl/Cmd + N: New record
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                const createBtn = document.querySelector('.btn-success[href*="create"]');
                if (createBtn) {
                    window.location.href = createBtn.href;
                }
            }
            
            // Escape: Clear search
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('.grid-quick-search input');
                if (searchInput && searchInput === document.activeElement) {
                    searchInput.value = '';
                    searchInput.dispatchEvent(new Event('input'));
                    searchInput.blur();
                }
            }
        });
    };

    /**
     * ============================================
     * INITIALIZATION
     * ============================================
     */

    // Initialize all enhancements
    const init = () => {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
            return;
        }
        
        console.log('Initializing Budget Pro Web enhancements...');
        
        try {
            // Form enhancements
            enableAutoSave();
            enhanceFormValidation();
            addCharacterCounter();
            
            // Search optimizations
            optimizeQuickSearch();
            addClearSearchButton();
            
            // Table enhancements
            makeTablesResponsive();
            enhanceTableRows();
            enhancePagination();
            
            // Mobile optimizations
            optimizeMobileSidebar();
            enhanceMobileButtons();
            optimizeSelect2Mobile();
            
            // Performance optimizations
            lazyLoadImages();
            optimizeLongLists();
            cacheAjaxRequests();
            
            // UI improvements
            enableSmoothScrolling();
            addBackToTop();
            enhanceBoxes();
            addKeyboardShortcuts();
            
            console.log('Budget Pro Web enhancements initialized successfully!');
        } catch (error) {
            console.error('Error initializing enhancements:', error);
        }
    };

    // Start initialization
    init();

    // Re-initialize on pjax complete (Laravel Admin uses pjax for navigation)
    if (typeof $ !== 'undefined') {
        $(document).on('pjax:complete', function() {
            console.log('Re-initializing after pjax navigation...');
            setTimeout(init, 100);
        });
    }

    // Export utilities for global use
    window.BudgetProEnhancements = {
        showLoading,
        hideLoading,
        showSuccess,
        showError,
        showWarning,
        debounce,
        throttle
    };

})();
