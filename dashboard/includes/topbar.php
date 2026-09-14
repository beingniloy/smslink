<header class="app-topbar" id="appTopbar">
  <div class="app-topbar-left" id="topbarLeft">
    <button class="app-mobile-toggle" onclick="toggleMobileSidebar()" aria-label="Toggle Navigation">☰</button>
    <h1 class="app-page-title" id="pageTitle"><?php echo htmlspecialchars($initialSecTitle ?? 'Dashboard'); ?></h1>
  </div>
  
  <div class="app-topbar-right" id="topbarRight">
    <!-- Mobile Search Open Button -->
    <button type="button" class="app-topbar-search-toggle" id="mobileSearchOpenBtn" onclick="toggleMobileSearch(true)" aria-label="Open Search" title="Search">
      <svg style="width:16px;height:16px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    </button>

    <!-- Global Search Input Wrapper -->
    <div class="app-topbar-search-wrap" id="topbarSearchWrap">
      <button type="button" class="app-topbar-search-back" id="mobileSearchCloseBtn" onclick="toggleMobileSearch(false)" aria-label="Close Search" title="Close Search">
        <svg style="width:18px;height:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M15 19l-7-7 7-7"/></svg>
      </button>
      <div class="app-topbar-search-icon">
        <svg style="width:16px;height:16px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      </div>
      <input type="text" id="globalSearchInput" class="app-input" placeholder="Instant Search..." oninput="handleGlobalSearch(this.value)" onfocus="handleGlobalSearch(this.value)" autocomplete="off">
      <div id="globalSearchResults" class="app-topbar-search-results" style="display:none"></div>
    </div>

    <!-- Actions Group -->
    <div class="app-topbar-actions" id="topbarActions">
      <a href="<?php echo htmlspecialchars(defined('APP_APK_URL') ? APP_APK_URL : ($appApkUrl ?? 'https://github.com/beingniloy/smslink/releases/download/v1.0.0/SMSLink-v1.0.0.apk')); ?>" target="_blank" rel="noopener noreferrer" class="app-btn app-topbar-apk-btn" title="Download Android Gateway App (.APK)" download>
        <svg style="width:14px;height:14px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        <span>Download App</span>
      </a>

      <a href="https://niloy.io/sponsor" target="_blank" rel="noopener noreferrer" class="app-btn app-topbar-sponsor-btn" title="Sponsor SMSLink Project">
        <svg style="width:14px;height:14px;fill:#db2777" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
        <span>Sponsor</span>
      </a>

      <a onclick="showSection('profile')" class="app-topbar-user-btn" title="Account Profile">
        <div class="app-user-avatar" id="topbarAvatarContainer">
          <?php if (!empty($adminAvatar)): ?>
          <img id="topbarAvatarImg" src="<?php echo htmlspecialchars($adminAvatar); ?>" alt="Avatar">
          <?php else: ?>
          <span id="topbarAvatarInitials"><?php echo strtoupper(substr($adminUser ?? 'A', 0, 1)); ?></span>
          <?php endif; ?>
        </div>
        <span class="app-topbar-user-name" id="topbarUserName"><?php echo htmlspecialchars($adminUser ?? 'Admin'); ?></span>
      </a>
    </div>
  </div>
</header>
