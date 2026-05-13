<style>
    
/* Tab styling */
.tabs {
    display: flex;
    margin-bottom: 20px;
    border-bottom: 2px solid #7b68ee;
}

.tab {
    padding: 10px 20px;
    cursor: pointer;
    background: none;
    border: none;
    color: white;
    font-size: 16px;
}

.tab.active {
    background-color: #7b68ee;
    border-radius: 6px 6px 0 0;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

</style>
<!-- Tabs -->
<div class="tabs">
    <button class="tab <?= $activeTab === 'groups' ? 'active' : '' ?>" data-tab="groups">Groups</button>
    <button class="tab <?= $activeTab === 'posts' ? 'active' : '' ?>" data-tab="posts">Posts</button>
</div>
<div id="groups-tab" class="tab-content <?= $activeTab === 'groups' ? 'active' : '' ?>">

hddhdhdhdhdhd
</div>

<div id="posts-tab" class="tab-content <?= $activeTab === 'posts' ? 'active' : '' ?>">

</div>

<script>
   const tabs = document.querySelectorAll('.tab');
const tabContents = document.querySelectorAll('.tab-content');

let searchTimeout;

// Real-time search filtering
searchInput.addEventListener('input', function() {
    const searchValue = this.value.trim();
    
    // Clear previous timeout
    clearTimeout(searchTimeout);
    
    // Set new timeout to avoid too many requests
    searchTimeout = setTimeout(() => {
        if (searchValue.length >= 1 || searchValue.length === 0) {
            // Update URL with search parameter and reload
            const params = new URLSearchParams(window.location.search);
            if (searchValue) {
                params.set('q', searchValue);
            } else {
                params.delete('q');
            }
            // Keep the current tab active
            params.set('tab', document.querySelector('.tab.active').getAttribute('data-tab'));
            window.location.href = `join_group.php?${params.toString()}`;
        }
    }, 500); // 500ms delay
});

// Tab switching - MODIFIED to preserve search query
tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        const tabName = tab.getAttribute('data-tab');
        
        // Update active tab
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        
        // Update active content
        tabContents.forEach(content => content.classList.remove('active'));
        document.getElementById(`${tabName}-tab`).classList.add('active');
        
        // Show/hide post type filter
        postTypeFilter.style.display = tabName === 'posts' ? 'block' : 'none';
        
        // Update URL without reload - PRESERVE SEARCH QUERY
        const params = new URLSearchParams(window.location.search);
        params.set('tab', tabName);
        
        // If switching to posts tab and we have a search query, keep it
        if (tabName === 'posts' && params.get('q')) {
            // Search query is already preserved in URL
        }
        
        // Update URL without reloading page
        window.history.replaceState({}, '', `join_group.php?${params.toString()}`);
    });
});

// Filter change handlers
categoryFilter.addEventListener('change', updateFilters);
postTypeFilter.addEventListener('change', updateFilters);

function updateFilters() {
    const params = new URLSearchParams(window.location.search);
    params.set('category', categoryFilter.value);
    
    if (document.querySelector('.tab.active').getAttribute('data-tab') === 'posts') {
        params.set('post_type', postTypeFilter.value);
    }
    
    params.set('tab', document.querySelector('.tab.active').getAttribute('data-tab'));
    
    window.location.href = `join_group.php?${params.toString()}`;
}

// Group card click handlers
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.group-card').forEach(card => {
        card.addEventListener('click', (e) => {
            if(e.target.tagName.toLowerCase() === 'button') return;
            const joinButton = card.querySelector('button.join-btn');
            const groupId = card.dataset.groupId;
            if (!joinButton || joinButton.disabled) return;
            const btnText = joinButton.textContent.toLowerCase();
            if(['join', 'pending', 'visit'].includes(btnText)) {
                window.location.href = `group.php?id=${groupId}`;
            }
        });
    });

    // Join group button functionality
    document.querySelectorAll('.join-btn').forEach(button => {
        button.addEventListener('click', async (e) => {
            e.stopPropagation();
            const groupId = button.closest('.group-card').dataset.groupId;
            const currentText = button.textContent.toLowerCase();
            if(currentText === 'join') {
                try {
                    const formData = new FormData();
                    formData.append('action', 'join_group');
                    formData.append('group_id', groupId);
                    const response = await fetch('join_group.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if(data.success) {
                        if(data.pending) {
                            button.textContent = 'Pending';
                            button.classList.add('pending');
                            button.disabled = true;
                        } else {
                            button.textContent = 'Visit';
                            button.classList.add('visited');
                        }
                    } else {
                        alert(data.message || 'Failed to join group.');
                    }
                } catch (err) {
                    alert('Error joining group.');
                }
            } else if(currentText === 'visit') {
                window.location.href = `group.php?id=${groupId}`;
            }
        });
    });

    // Like button functionality for posts
    document.querySelectorAll('.like-btn').forEach(button => {
        button.addEventListener('click', async function() {
            const btn = this;
            const postId = btn.getAttribute('data-post-id');
            if (!postId) return;

            const isLiked = btn.classList.contains('liked');
            const action = isLiked ? 'unlike' : 'like';

            const formData = new FormData();
            formData.append('action', action);
            formData.append('post_id', postId);

            try {
                const response = await fetch('join_group.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    // Update like count
                    const countSpan = btn.querySelector('.count');
                    if (countSpan) {
                        countSpan.textContent = `(${data.likes_count})`;
                    }
                    
                    // Toggle liked class
                    btn.classList.toggle('liked');
                } else {
                    alert('Failed to update like status.');
                }
            } catch (err) {
                alert('Error updating like status.');
            }
        });
    });
});

// Share group post functionality
function shareGroupPost(postId, groupId) {
    const postUrl = `${window.location.origin}/group.php?id=${groupId}&post=${postId}`;
    
    if (navigator.share) {
        // Use Web Share API if available
        navigator.share({
            title: 'Check out this group post',
            url: postUrl
        }).catch(err => {
            console.log('Error sharing:', err);
            copyToClipboard(postUrl);
        });
    } else {
        // Fallback to clipboard
        copyToClipboard(postUrl);
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Post link copied to clipboard!');
    }).catch(err => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('Post link copied to clipboard!');
    });
}
</script>