<header class="app-topbar">
  <div style="display:flex;align-items:center;gap:10px;min-width:0">
    <button class="app-mobile-toggle" onclick="toggleMobileSidebar()" aria-label="Toggle Navigation">☰</button>
    <h1 class="app-page-title" id="pageTitle">Dashboard</h1>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex:1;justify-content:flex-end;min-width:0">
    <div class="app-topbar-search-wrap" style="position:relative;width:100%;max-width:380px">
      <div style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;display:flex;align-items:center;pointer-events:none">
        <svg style="width:16px;height:16px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      </div>
      <input type="text" id="globalSearchInput" class="app-input" placeholder="Instant Search..." oninput="handleGlobalSearch(this.value)" onfocus="handleGlobalSearch(this.value)" autocomplete="off" style="padding-left:34px;padding-right:10px;height:36px;font-size:13px;border-radius:10px;background:#f8fafc;border:1px solid #cbd5e1">
      <div id="globalSearchResults" style="display:none;position:absolute;top:42px;left:0;right:0;background:#FFF;border:1px solid var(--app-border-color);border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.1);max-height:360px;overflow-y:auto;z-index:100;padding:8px"></div>
    </div>

    <a href="https://niloy.io/sponsor" target="_blank" rel="noopener noreferrer" class="app-btn app-topbar-sponsor-btn" style="background:#fdf2f8;color:#db2777;border:1px solid #fbcfe8;font-size:12px;padding:6px 12px;border-radius:9999px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-weight:600;flex-shrink:0" title="Sponsor SMSLink Project">
      <svg style="width:14px;height:14px;fill:#db2777" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
      <span>Sponsor</span>
    </a>

    <a onclick="showSection('profile')" class="app-topbar-user-btn" style="display:flex;align-items:center;gap:8px;text-decoration:none;cursor:pointer;padding:4px 10px 4px 6px;border-radius:9999px;background:#f1f5f9;border:1px solid var(--app-border-color);transition:all 0.15s;flex-shrink:0" title="Account Profile">
      <div class="app-user-avatar" style="width:28px;height:28px;font-size:12px;background:var(--primary);color:#FFF;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <?php if (!empty($adminAvatar)): ?>
        <img src="<?php echo htmlspecialchars($adminAvatar); ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
        <?php echo strtoupper(substr($adminUser ?? 'A', 0, 1)); ?>
        <?php endif; ?>
      </div>
      <span class="app-topbar-user-name" style="font-size:13px;font-weight:600;color:#334155"><?php echo htmlspecialchars($adminUser ?? 'Admin'); ?></span>
    </a>
  </div>
</header>
