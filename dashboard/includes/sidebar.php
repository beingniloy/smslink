<div class="app-sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

<aside class="app-sidebar" id="appSidebar">
  <div class="app-sidebar-header">
    <a href="status" onclick="showSection('status'); return false;" class="app-brand" style="display:flex;align-items:center;gap:8px;text-decoration:none">
      <img src="https://cdn.niloy.io/projects/smslink/logo.png" alt="SMSLink" style="height:28px;max-width:130px;object-fit:contain;display:block" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
      <span class="brand-text-fallback" style="display:none;align-items:baseline;gap:2px;font-size:18px;font-weight:700;color:#0f172a;letter-spacing:-0.03em">SMS<span style="color:var(--primary);font-weight:400">Link</span></span>
    </a>
    <div style="display:flex;align-items:center;gap:6px">
      <span class="app-brand-ver" id="sidebarBrandVersion"><?php echo defined('APP_VERSION') ? APP_VERSION : 'v1.0.0'; ?></span>
      <span id="sidebarUpdateAvailableBadge" class="app-badge app-badge-amber" style="display:none;font-size:9px;padding:2px 6px;cursor:pointer;animation:pulse 2s infinite" onclick="showSection('updates')" title="New Update Available!">UPDATE</span>
    </div>
  </div>

  <nav class="app-sidebar-nav">
    <div>
      <div class="app-nav-group-title">Core Navigation</div>
      <div class="app-nav-items">
        <a class="app-nav-item <?php echo (($currentSec ?? 'status') === 'status') ? 'active' : ''; ?>" data-sec="status" onclick="showSection('status', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
          <span>Dashboard</span>
        </a>
        <a class="app-nav-item <?php echo (($currentSec ?? '') === 'send') ? 'active' : ''; ?>" data-sec="send" onclick="showSection('send', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
          <span>Send SMS</span>
        </a>
        <a class="app-nav-item <?php echo (($currentSec ?? '') === 'sent') ? 'active' : ''; ?>" data-sec="sent" onclick="showSection('sent', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
          <span>Sent History</span>
        </a>
      </div>
    </div>

    <div>
      <div class="app-nav-group-title">Gateway &amp; Integration</div>
      <div class="app-nav-items">
        <a class="app-nav-item <?php echo (($currentSec ?? '') === 'devices') ? 'active' : ''; ?>" data-sec="devices" onclick="showSection('devices', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
          <span>Devices &amp; QR Pair</span>
          <?php if (!empty($allDevicesOffline)): ?>
            <span class="app-badge app-badge-rose" style="margin-left:auto;font-size:9px;padding:2px 6px">Offline</span>
          <?php endif; ?>
        </a>
        <a class="app-nav-item <?php echo (($currentSec ?? '') === 'apikeys') ? 'active' : ''; ?>" data-sec="apikeys" onclick="showSection('apikeys', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 0121 9z"/></svg>
          <span>API Keys</span>
        </a>
        <a class="app-nav-item <?php echo (($currentSec ?? '') === 'docs') ? 'active' : ''; ?>" data-sec="docs" onclick="showSection('docs', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          <span>API Documentation</span>
        </a>
      </div>
    </div>

    <div>
      <div class="app-nav-group-title">System &amp; Settings</div>
      <div class="app-nav-items">
        <?php if (($currentUserRole ?? 'admin') === 'admin'): ?>
        <a class="app-nav-item <?php echo (($currentSec ?? '') === 'team') ? 'active' : ''; ?>" data-sec="team" onclick="showSection('team', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          <span>Team Members</span>
        </a>
        <?php endif; ?>
        <a class="app-nav-item <?php echo (($currentSec ?? '') === 'updates') ? 'active' : ''; ?>" data-sec="updates" onclick="showSection('updates', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
          <span>System Updates</span>
          <span id="sidebarUpdateBadge" class="app-badge app-badge-amber" style="display:none;margin-left:auto;font-size:9px;padding:2px 6px">Update</span>
        </a>
        <?php if (($currentUserRole ?? 'admin') === 'admin'): ?>
        <a class="app-nav-item <?php echo (($currentSec ?? '') === 'settings') ? 'active' : ''; ?>" data-sec="settings" onclick="showSection('settings', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          <span>System Settings</span>
        </a>
        <?php endif; ?>
        <a class="app-nav-item <?php echo (($currentSec ?? '') === 'about') ? 'active' : ''; ?>" data-sec="about" onclick="showSection('about', this)">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span>About SMSLink</span>
        </a>
      </div>
    </div>
  </nav>

  <div class="app-sidebar-footer">
    <a onclick="showSection('profile')" class="app-user-profile-btn" title="View Account Profile">
      <div class="app-user-avatar">
        <?php if (!empty($adminAvatar)): ?>
        <img src="<?php echo htmlspecialchars($adminAvatar); ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
        <?php echo strtoupper(substr($adminUser ?? 'A', 0, 1)); ?>
        <?php endif; ?>
      </div>
      <div>
        <div style="font-size:13px;font-weight:600;color:#0f172a"><?php echo htmlspecialchars($adminUser ?? 'Admin'); ?></div>
        <div style="font-size:11px;color:#64748b">Profile Settings</div>
      </div>
    </a>
    <a href="?logout=1" style="color:#64748b;padding:6px;border-radius:6px;display:flex;align-items:center;justify-content:center;transition:all 0.15s" onmouseover="this.style.color='#ef4444';this.style.background='#fef2f2'" onmouseout="this.style.color='#64748b';this.style.background='transparent'" title="Sign Out">
      <svg style="width:18px;height:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
    </a>
  </div>
</aside>
