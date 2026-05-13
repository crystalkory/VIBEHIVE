<?php
require_once "back.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Search</title>
<style>
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }
  
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background-color: #1e1e2f;
    color: #fff;
    min-height: 100vh;
  }
  
  .search-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    background-color: #1e1e2f;
    border-bottom: 1px solid #262626;
    padding: 12px 16px;
    z-index: 1000;
  }
  
  .search-input-container {
    position: relative;
    width: 100%;
    margin-top: 50px;
  }
  
  #search-input {
    width: 100%;
    padding: 12px 16px 12px 44px;
    font-size: 16px;
    border-radius: 8px;
    border: 2px soild #7b68ee;
    background-color: #262626;
    color: #fff;
    outline: none;
  }
  
  #search-input::placeholder {
    color: #a8a8a8;
  }
  
  .search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #a8a8a8;
    font-size: 16px;
  }
  
  .cancel-btn {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #fff;
    font-size: 16px;
    cursor: pointer;
    display: none;
  }
  
  #suggestions {
    position: fixed;
    top: 60px;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #1e1e2f;
    z-index: 999;
    overflow-y: auto;
    display: none;
    margin-top: 50px;
  }
  
  .suggestion-section {
    margin-bottom: 20px;
  }
  
  .section-header {
    font-size: 16px;
    font-weight: 600;
    padding: 16px 20px 8px;
    color: #a8a8a8;
  }
  
  .suggestion-item {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    cursor: pointer;
    transition: background-color 0.2s;
  }
  
  .suggestion-item:hover {
    background-color: #121212;
  }
  
  .profile-pic {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    margin-right: 12px;
    object-fit: cover;
    border: 1px solid #363636;
  }
  
  .group-pic {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    margin-right: 12px;
    object-fit: cover;
    border: 1px solid #363636;
  }
  
  .suggestion-info {
    flex: 1;
  }
  
  .suggestion-username {
    font-weight: 600;
    font-size: 14px;
    color: #fff;
  }
  
  .suggestion-name {
    font-size: 14px;
    color: #a8a8a8;
    margin-top: 2px;
  }
  
  .recent-searches {
    margin-top: 20px;
  }
  
  .recent-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px 8px;
  }
  
  .recent-title {
    font-size: 16px;
    font-weight: 600;
    color: #fff;
  }
  
  .clear-all {
    background: none;
    border: none;
    color: #0095f6;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
  }
  
  .recent-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    cursor: pointer;
  }
  
  .recent-item-content {
    display: flex;
    align-items: center;
    flex: 1;
  }
  
  .recent-icon {
    width: 24px;
    height: 24px;
    margin-right: 12px;
    color: #fff;
  }
  
  .recent-text {
    font-size: 14px;
    color: #fff;
  }
  
  .remove-recent {
    background: none;
    border: none;
    color: #a8a8a8;
    font-size: 14px;
    cursor: pointer;
    padding: 4px;
  }
  
  .no-results {
    text-align: center;
    padding: 40px 20px;
    color: #a8a8a8;
  }
  
  .search-tabs {
    position: fixed;
    top: 60px;
    left: 0;
    right: 0;
    background-color: #000;
    border-bottom: 1px solid #262626;
    z-index: 998;
    display: none;
  }
  
  .tabs-container {
    display: flex;
    justify-content: space-around;
  }
  
  .tab {
    flex: 1;
    text-align: center;
    padding: 16px 0;
    font-size: 14px;
    font-weight: 600;
    color: #a8a8a8;
    cursor: pointer;
    border-bottom: 1px solid transparent;
  }
  
  .tab.active {
    color: #fff;
    border-bottom-color: #fff;
  }
  
  .search-content {
    margin-top: 120px;
    padding: 20px;
    display: none;
  }
  
  @media (max-width: 768px) {
    .search-header {
      padding: 8px 12px;
    }
    
    #search-input {
      padding: 10px 12px 10px 40px;
      font-size: 16px;
    }
    
    .search-icon {
      left: 12px;
    }
    
    .cancel-btn {
      right: 12px;
    }
  }
</style>
</head>
<body>

<div class="search-header">
  <div class="search-input-container">
    <span class="search-icon">🔍</span>
    <input type="text" id="search-input" autocomplete="off" placeholder="Search" />
    <button class="cancel-btn">Cancel</button>
  </div>
</div>

<div id="suggestions">
  <div class="suggestions-content">
    <!-- Suggestions will be populated here -->
  </div>
</div>

<div class="search-tabs">
  <div class="tabs-container">
    <div class="tab active" data-tab="top">Top</div>
    <div class="tab" data-tab="accounts">Accounts</div>
    <div class="tab" data-tab="tags">Tags</div>
    <div class="tab" data-tab="places">Places</div>
  </div>
</div>

<div class="search-content">
  <!-- Search results will be displayed here -->
</div>

<script>
const searchInput = document.getElementById('search-input');
const suggestions = document.getElementById('suggestions');
const cancelBtn = document.querySelector('.cancel-btn');
const searchTabs = document.querySelector('.search-tabs');
const searchContent = document.querySelector('.search-content');
const tabs = document.querySelectorAll('.tab');

let currentQuery = '';
let activeTab = 'top';

// Focus search input on page load
searchInput.focus();

// Show/hide cancel button based on input
searchInput.addEventListener('input', function() {
  cancelBtn.style.display = this.value.trim() ? 'block' : 'none';
});

// Cancel button functionality
cancelBtn.addEventListener('click', function() {
  searchInput.value = '';
  searchInput.focus();
  cancelBtn.style.display = 'none';
  hideSuggestions();
  hideSearchResults();
});

// Tab switching
tabs.forEach(tab => {
  tab.addEventListener('click', function() {
    tabs.forEach(t => t.classList.remove('active'));
    this.classList.add('active');
    activeTab = this.dataset.tab;
    
    if (currentQuery.trim()) {
      performSearch(currentQuery, activeTab);
    }
  });
});

// Search input handling
searchInput.addEventListener('input', function() {
  const query = this.value.trim();
  currentQuery = query;
  
  if (query.length === 0) {
    hideSuggestions();
    hideSearchResults();
    showRecentSearches();
    return;
  }
  
  if (query.length < 2) {
    hideSuggestions();
    return;
  }
  
  fetchSuggestions(query);
});

// Enter key to search
searchInput.addEventListener('keypress', function(e) {
  if (e.key === 'Enter') {
    const query = this.value.trim();
    if (query) {
      performSearch(query, activeTab);
      addToSearchHistory(query);
    }
  }
});

function showRecentSearches() {
  const history = JSON.parse(localStorage.getItem('searchHistory') || '[]');
  
  let html = `
    <div class="recent-searches">
      <div class="recent-header">
        <div class="recent-title">Recent</div>
        <button class="clear-all" onclick="clearSearchHistory()">Clear all</button>
      </div>
  `;
  
  if (history.length === 0) {
    html += `<div class="no-results">No recent searches.</div>`;
  } else {
    history.forEach(term => {
      html += `
        <div class="recent-item" onclick="selectRecentSearch('${term.replace(/'/g, "\\'")}')">
          <div class="recent-item-content">
            <span class="recent-icon">🕒</span>
            <span class="recent-text">${term}</span>
          </div>
          <button class="remove-recent" onclick="event.stopPropagation(); removeFromSearchHistory('${term.replace(/'/g, "\\'")}')">×</button>
        </div>
      `;
    });
  }
  
  html += `</div>`;
  
  document.querySelector('.suggestions-content').innerHTML = html;
  suggestions.style.display = 'block';
}

function fetchSuggestions(query) {
  fetch(`search_suggestions.php?q=${encodeURIComponent(query)}`)
    .then(res => res.json())
    .then(data => {
      let html = '';
      
      if (data.profiles && data.profiles.length > 0) {
        html += '<div class="suggestion-section">';
        html += '<div class="section-header">Accounts</div>';
        data.profiles.slice(0, 5).forEach(profile => {
          html += `
            <div class="suggestion-item" onclick="selectSuggestion('${profile.username.replace(/'/g, "\\'")}', 'profile', ${profile.id})">
              <img src="${profile.profile_pic_url || 'default_profile.png'}" class="profile-pic" alt="${profile.username}">
              <div class="suggestion-info">
                <div class="suggestion-username">${profile.username}</div>
                <div class="suggestion-name">${profile.full_name || profile.username}</div>
              </div>
            </div>
          `;
        });
        html += '</div>';
      }
      
      if (data.groups && data.groups.length > 0) {
        html += '<div class="suggestion-section">';
        html += '<div class="section-header">Groups</div>';
        data.groups.slice(0, 3).forEach(group => {
          html += `
            <div class="suggestion-item" onclick="selectSuggestion('${group.name.replace(/'/g, "\\'")}', 'group', ${group.id})">
              <img src="${group.profile_pic_url || 'default_group.png'}" class="group-pic" alt="${group.name}">
              <div class="suggestion-info">
                <div class="suggestion-username">${group.name}</div>
                <div class="suggestion-name">Group • ${group.member_count || 0} members</div>
              </div>
            </div>
          `;
        });
        html += '</div>';
      }
      
      if (data.tags && data.tags.length > 0) {
        html += '<div class="suggestion-section">';
        html += '<div class="section-header">Tags</div>';
        data.tags.slice(0, 5).forEach(tag => {
          html += `
            <div class="suggestion-item" onclick="selectSuggestion('${tag.replace(/'/g, "\\'")}', 'tag')">
              <span class="recent-icon">#</span>
              <div class="suggestion-info">
                <div class="suggestion-username">${tag}</div>
              </div>
            </div>
          `;
        });
        html += '</div>';
      }
      
      if (!html) {
        html = '<div class="no-results">No results found.</div>';
      }
      
      document.querySelector('.suggestions-content').innerHTML = html;
      suggestions.style.display = 'block';
    })
    .catch(error => {
      console.error('Error fetching suggestions:', error);
      document.querySelector('.suggestions-content').innerHTML = '<div class="no-results">Error loading suggestions</div>';
      suggestions.style.display = 'block';
    });
}

function selectSuggestion(value, type, id = null) {
  searchInput.value = value;
  addToSearchHistory(value);
  
  if (type === 'profile') {
    window.location.href = `profile.php?id=${id}`;
  } else if (type === 'group') {
    window.location.href = `group.php?id=${id}`;
  } else {
    performSearch(value, activeTab);
  }
}

function selectRecentSearch(term) {
  searchInput.value = term;
  performSearch(term, activeTab);
}

function performSearch(query, tab = 'top') {
  currentQuery = query;
  addToSearchHistory(query);
  hideSuggestions();
  showSearchResults();
  
  // For now, redirect to search results page
  // In a real Instagram-like implementation, you'd fetch results here
  window.location.href = `search_result.php?q=${encodeURIComponent(query)}&tab=${tab}`;
}

function hideSuggestions() {
  suggestions.style.display = 'none';
}

function showSearchResults() {
  searchTabs.style.display = 'block';
  searchContent.style.display = 'block';
}

function hideSearchResults() {
  searchTabs.style.display = 'none';
  searchContent.style.display = 'none';
}

function addToSearchHistory(term) {
  if (!term.trim()) return;
  
  let history = JSON.parse(localStorage.getItem('searchHistory') || '[]');
  history = history.filter(item => item.toLowerCase() !== term.toLowerCase());
  history.unshift(term);
  
  if (history.length > 10) {
    history = history.slice(0, 10);
  }
  
  localStorage.setItem('searchHistory', JSON.stringify(history));
}

function removeFromSearchHistory(term) {
  let history = JSON.parse(localStorage.getItem('searchHistory') || '[]');
  history = history.filter(item => item.toLowerCase() !== term.toLowerCase());
  localStorage.setItem('searchHistory', JSON.stringify(history));
  showRecentSearches();
}

function clearSearchHistory() {
  localStorage.removeItem('searchHistory');
  showRecentSearches();
}

// Close suggestions when clicking outside
document.addEventListener('click', function(e) {
  if (!searchInput.contains(e.target) && !suggestions.contains(e.target)) {
    hideSuggestions();
  }
});

// Initialize recent searches on page load
document.addEventListener('DOMContentLoaded', function() {
  showRecentSearches();
});
</script>

</body>
</html>