<script>
    // ========== AJAX NAVIGATION - FIRE AND FORGET PAGE RELOADS ==========
function navigateToURL(url, pushState = true) {
    // Show loading indicator
    showLoadingIndicator();
    
    // Use fetch to get the new content
    fetch(url)
        .then(response => response.text())
        .then(html => {
            // Parse the HTML response
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Extract the main content
            const newContent = doc.querySelector('.tab-content.active') || 
                             doc.querySelector('#users-list') || 
                             doc.querySelector('#posts-list');
            
            if (newContent) {
                // Replace content without full page reload
                if (newContent.classList.contains('tab-content')) {
                    document.querySelector('.tab-content.active').innerHTML = newContent.innerHTML;
                } else {
                    document.getElementById(newContent.id).innerHTML = newContent.innerHTML;
                }
                
                // Update page title
                document.title = doc.title;
                
                // Update URL without reload
                if (pushState) {
                    window.history.pushState({}, '', url);
                }
                
                // Re-attach event listeners
                attachActionButtonListeners();
                initVideoControls();
                
                // Re-initialize infinite scroll if on posts tab
                if (document.querySelector('.tab.active').getAttribute('data-tab') === 'posts') {
                    initInfiniteScroll();
                }
            }
        })
        .catch(error => {
            console.error('Navigation error:', error);
            // Fallback to traditional navigation
            window.location.href = url;
        })
        .finally(() => {
            hideLoadingIndicator();
        });
}

// Loading indicator functions
function showLoadingIndicator() {
    let loader = document.getElementById('ajax-loader');
    if (!loader) {
        loader = document.createElement('div');
        loader.id = 'ajax-loader';
        loader.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #7b68ee, #6a5acd);
            z-index: 9999;
            animation: loading 1s infinite;
        `;
        document.body.appendChild(loader);
    }
}

function hideLoadingIndicator() {
    const loader = document.getElementById('ajax-loader');
    if (loader) {
        loader.remove();
    }
}

// Handle browser back/forward buttons
window.addEventListener('popstate', function(event) {
    navigateToURL(window.location.href, false);
});

// Intercept all internal links
document.addEventListener('click', function(e) {
    // Check if it's an internal link
    const link = e.target.closest('a[href*="people.php"]');
    if (link && !link.hasAttribute('data-ajax-disabled')) {
        e.preventDefault();
        navigateToURL(link.href);
    }
});

// Update your existing filter functions to use AJAX
function updateFilters() {
    const params = new URLSearchParams(window.location.search);
    params.set('category', categoryFilter.value);
    
    if (document.querySelector('.tab.active').getAttribute('data-tab') === 'posts') {
        params.set('post_type', postTypeFilter.value);
    }
    
    params.set('tab', document.querySelector('.tab.active').getAttribute('data-tab'));
    
    // Use AJAX navigation instead of full reload
    navigateToURL(`people.php?${params.toString()}`);
}

// Update search functions to use AJAX
function performSearch(searchValue, isPostSearch = false) {
    const params = new URLSearchParams(window.location.search);
    
    if (isPostSearch) {
        if (searchValue) {
            params.set('post_search', searchValue);
        } else {
            params.delete('post_search');
        }
        params.set('tab', 'posts');
    } else {
        if (searchValue) {
            params.set('q', searchValue);
        } else {
            params.delete('q');
        }
        params.delete('post_search');
    }
    
    navigateToURL(`people.php?${params.toString()}`);
}

// Replace your existing search event listeners
searchInput.addEventListener('input', function() {
    const searchValue = this.value.trim();
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        performSearch(searchValue, false);
    }, 500);
});

postsSearchInput.addEventListener('input', function() {
    const searchValue = this.value.trim();
    clearTimeout(postsSearchTimeout);
    postsSearchTimeout = setTimeout(() => {
        performSearch(searchValue, true);
    }, 500);
});
</script>