<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Task Board</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css">
  <!-- Flatpickr date picker -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <!-- SortableJS: dependency-free drag-and-drop (cards + list reordering) -->
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"
          integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM"
          crossorigin="anonymous"></script>
</head>
<body>

  <!-- ============================================================ -->
  <!-- Auth view: shown when not logged in (login / register / reset) -->
  <!-- ============================================================ -->
  <div id="auth-view" class="auth-view" hidden>
    <div class="auth-card">
      <div class="auth-brand">
        <div class="auth-logo" aria-hidden="true">📋</div>
        <h1>Task Board</h1>
      </div>

      <!-- Login / Register -->
      <div id="auth-main">
        <div class="auth-tabs" role="tablist">
          <button type="button" class="auth-tab active" data-tab="login" role="tab">Log in</button>
          <button type="button" class="auth-tab" data-tab="register" role="tab">Sign up</button>
        </div>

        <form id="login-form" class="auth-form" autocomplete="on">
          <input type="email" id="login-email" placeholder="Email" autocomplete="email" required>
          <div class="password-field">
            <input type="password" id="login-password" placeholder="Password" autocomplete="current-password" required>
            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                <svg class="icon-eye" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="icon-eye-off" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" hidden fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
          </div>
          <button type="submit" class="btn btn-primary auth-submit">Log in</button>
          <button type="button" id="go-forgot" class="auth-link">Forgot password?</button>
        </form>

        <form id="register-form" class="auth-form" autocomplete="on" hidden>
          <input type="text" id="reg-name" placeholder="Your name" autocomplete="name" maxlength="60" required>
          <input type="email" id="reg-email" placeholder="Email" autocomplete="email" required>
          <div class="password-field">
            <input type="password" id="reg-password" placeholder="Password (min 8 characters)" autocomplete="new-password" minlength="8" required>
            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                <svg class="icon-eye" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="icon-eye-off" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" hidden fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
          </div>
          <button type="submit" class="btn btn-primary auth-submit">Create account</button>
          <p class="modal-hint">We’ll email you a verification link to activate your account.</p>
        </form>
      </div>

      <!-- Check email / resend verification -->
      <div id="auth-verify-pending" class="auth-pane" hidden>
        <h2 class="auth-subheading">Check your email</h2>
        <p class="modal-hint">We sent a verification link to <strong id="verify-pending-email"></strong>. Open it to activate your account.</p>
        <button type="button" id="resend-verification" class="btn btn-primary auth-submit">Resend verification email</button>
        <div id="verify-pending-result" class="auth-result" hidden></div>
        <button type="button" class="auth-link" data-back="main">← Back to log in</button>
      </div>

      <!-- Forgot password -->
      <div id="auth-forgot" class="auth-pane" hidden>
        <h2 class="auth-subheading">Reset your password</h2>
        <p class="modal-hint">Enter your email. If an account exists, we’ll send a reset link.</p>
        <input type="email" id="forgot-email" placeholder="Email" autocomplete="email" required>
        <button type="button" id="forgot-submit" class="btn btn-primary auth-submit">Send reset link</button>
        <div id="forgot-result" class="auth-result" hidden></div>
        <button type="button" class="auth-link" data-back="main">← Back to log in</button>
      </div>

      <!-- Reset password (reached via ?reset=TOKEN link) -->
      <div id="auth-reset" class="auth-pane" hidden>
        <h2 class="auth-subheading">Set a new password</h2>
        <div class="password-field">
          <input type="password" id="reset-password" placeholder="New password (min 8 characters)" autocomplete="new-password" minlength="8" required>
          <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                <svg class="icon-eye" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="icon-eye-off" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" hidden fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
        </div>
        <button type="button" id="reset-submit" class="btn btn-primary auth-submit">Update password</button>
        <button type="button" class="auth-link" data-back="main">← Back to log in</button>
      </div>

      <p id="auth-error" class="auth-error" role="alert" hidden></p>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- App view: the board (shown when logged in)                    -->
  <!-- ============================================================ -->
  <div id="app-view" hidden>
    <header class="app-header">
      <div class="project-switcher">
        <select id="project-select" aria-label="Select project"></select>
        <button id="btn-new-project" class="btn btn-ghost btn-icon" type="button" title="New project">＋</button>
        <button id="btn-del-project" class="btn btn-ghost btn-icon" type="button" title="Delete project">🗑</button>
      </div>
      <h1 id="board-title" contenteditable="true" spellcheck="false">Task Board</h1>
      <div class="header-actions">
        <button id="btn-add-list" class="btn btn-primary">+ Add list</button>
        <button id="btn-members" class="btn btn-ghost">👥 Members</button>
        <span class="user-chip" id="user-chip" title="Click to open profile" hidden>
          <span class="avatar" id="user-chip-avatar">
            <span id="user-chip-initials"></span>
            <img id="user-chip-img" alt="" hidden>
          </span>
          <span class="user-chip-name" id="user-chip-name"></span>
        </span>
      </div>
    </header>

    <!-- Board: lists render here as horizontal columns -->
    <main id="board" class="board"></main>

    <!-- Card editor modal -->
    <div id="modal" class="modal-overlay" hidden>
      <div class="modal modal-card" role="dialog" aria-modal="true" aria-label="Edit card">
        <button id="modal-close" class="modal-close" aria-label="Close">&times;</button>
        <div class="modal-header-area">
          <h2 id="modal-title" class="modal-title-text" contenteditable="true" spellcheck="false" placeholder="Card title"></h2>
        </div>

        <div class="modal-grid-layout">
          <!-- Main Left Column -->
          <div class="modal-col-main">
            <div class="modal-section">
              <span class="modal-label">🏷 Labels</span>
              <div id="modal-labels" class="modal-labels"></div>
            </div>

            <div class="modal-section">
              <span class="modal-label">👤 Assignees</span>
              <div id="modal-assignees" class="modal-assignees"></div>
            </div>

            <div class="modal-section">
              <label class="modal-label" for="modal-due">🕒 Due date</label>
              <div class="due-input-wrap">
                <input id="modal-due" type="text" placeholder="Select due date…" autocomplete="off">
                <button type="button" id="btn-clear-due" class="btn btn-ghost btn-mini clear-due-btn" title="Clear due date" hidden>Clear</button>
              </div>
            </div>

            <div class="modal-section" id="modal-desc-section">
              <label class="modal-label" id="modal-desc-label">≡ Description</label>

              <div id="desc-view" class="desc-view" role="button" tabindex="0" aria-labelledby="modal-desc-label">
                <div id="desc-view-body" class="desc-content" hidden></div>
                <p id="desc-view-placeholder" class="desc-placeholder">Add a more detailed description…</p>
              </div>

              <div id="desc-editor" class="desc-editor" hidden>
                <div class="desc-toolbar" role="toolbar" aria-label="Description formatting">
                  <div class="desc-toolbar-group">
                    <label class="desc-style-wrap" title="Text style">
                      <span class="desc-style-mark" aria-hidden="true">Tt</span>
                      <select id="desc-style" class="desc-style-select" aria-label="Text style">
                        <option value="p">Normal text</option>
                        <option value="h3">Heading</option>
                      </select>
                    </label>
                  </div>
                  <span class="desc-toolbar-sep" aria-hidden="true"></span>
                  <div class="desc-toolbar-group">
                    <button type="button" class="desc-tool" data-cmd="bold" title="Bold" aria-label="Bold"><b>B</b></button>
                    <button type="button" class="desc-tool" data-cmd="italic" title="Italic" aria-label="Italic"><i>I</i></button>
                    <button type="button" class="desc-tool" data-cmd="underline" title="Underline" aria-label="Underline"><u>U</u></button>
                  </div>
                  <span class="desc-toolbar-sep" aria-hidden="true"></span>
                  <div class="desc-toolbar-group">
                    <button type="button" class="desc-tool" data-cmd="insertUnorderedList" title="Bullet list" aria-label="Bullet list">
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="2.5" cy="4" r="1.2"/><circle cx="2.5" cy="8" r="1.2"/><circle cx="2.5" cy="12" r="1.2"/><rect x="5.5" y="3.2" width="9" height="1.6" rx=".4"/><rect x="5.5" y="7.2" width="9" height="1.6" rx=".4"/><rect x="5.5" y="11.2" width="9" height="1.6" rx=".4"/></svg>
                    </button>
                    <button type="button" class="desc-tool" data-cmd="insertOrderedList" title="Numbered list" aria-label="Numbered list">
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><text x="0" y="5.2" font-size="5.5" font-weight="700" font-family="system-ui,sans-serif">1.</text><text x="0" y="9.2" font-size="5.5" font-weight="700" font-family="system-ui,sans-serif">2.</text><text x="0" y="13.2" font-size="5.5" font-weight="700" font-family="system-ui,sans-serif">3.</text><rect x="5.5" y="3.2" width="9" height="1.6" rx=".4"/><rect x="5.5" y="7.2" width="9" height="1.6" rx=".4"/><rect x="5.5" y="11.2" width="9" height="1.6" rx=".4"/></svg>
                    </button>
                  </div>
                  <span class="desc-toolbar-sep" aria-hidden="true"></span>
                  <div class="desc-toolbar-group">
                    <button type="button" class="desc-tool" data-cmd="createLink" title="Add link" aria-label="Add link">
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6.5 9.5a3.2 3.2 0 0 0 4.5.2l1.8-1.8a3.2 3.2 0 0 0-4.5-4.5L7.5 4.2"/><path d="M9.5 6.5a3.2 3.2 0 0 0-4.5-.2L3.2 8.1a3.2 3.2 0 0 0 4.5 4.5L8.5 11.8"/></svg>
                    </button>
                    <button type="button" class="desc-tool" id="desc-attach-btn" title="Attach image" aria-label="Attach image">
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2.5" y="3.5" width="11" height="9" rx="1.5"/><circle cx="5.8" cy="6.5" r="1.1" fill="currentColor" stroke="none"/><path d="M2.8 11.2 6.2 8.2l2.2 2.1 2-1.8 2.8 2.7"/></svg>
                    </button>
                  </div>
                </div>
                <div id="modal-desc" class="desc-editable" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="modal-desc-label" data-placeholder="Add a more detailed description…"></div>
                <div class="desc-editor-actions">
                  <button type="button" id="desc-save" class="btn btn-primary btn-mini">Save</button>
                  <button type="button" id="desc-cancel" class="btn btn-soft btn-mini">Cancel</button>
                </div>
              </div>

              <p class="modal-hint">Tip: paste an image here (Ctrl/⌘+V) to attach it automatically.</p>
            </div>

            <div class="modal-section" id="modal-checklist-section">
              <div class="modal-label-row">
                <span class="modal-label">☑ Checklist</span>
                <span id="modal-checklist-progress-text" class="modal-hint-inline">0%</span>
              </div>
              <div class="checklist-progress-bar">
                <div id="modal-checklist-progress-fill" class="checklist-progress-fill" style="width: 0%;"></div>
              </div>
              <div id="modal-checklist-items" class="checklist-items"></div>
              <div class="checklist-add-row">
                <input id="modal-checklist-input" class="member-input" type="text" placeholder="Add a checklist item…" maxlength="200" autocomplete="off">
                <button type="button" id="modal-checklist-add-btn" class="btn btn-soft btn-mini">Add</button>
              </div>
            </div>

            <div class="modal-section">
              <div class="modal-label-row">
                <span class="modal-label">📎 Attachments</span>
                <button id="modal-add-image" class="btn btn-soft btn-mini add-image-btn" type="button">Add</button>
              </div>
              <div id="modal-images" class="modal-images"></div>
              <input type="file" id="image-input" accept="image/*" multiple hidden>
              <p class="modal-hint">Images are resized and stored as files in uploads/.</p>
            </div>
          </div>

          <!-- Side Right Column -->
          <div class="modal-col-side">
            <div class="modal-section" id="modal-comments-section">
              <span class="modal-label">💬 Comments and activity</span>

              <div class="comment-input-box desc-editor comment-editor is-idle" id="comment-editor">
                <div id="comment-toolbar" class="desc-toolbar" role="toolbar" aria-label="Comment formatting" hidden>
                  <div class="desc-toolbar-group">
                    <label class="desc-style-wrap" title="Text style">
                      <span class="desc-style-mark" aria-hidden="true">Tt</span>
                      <select id="comment-style" class="desc-style-select" aria-label="Text style">
                        <option value="p">Normal text</option>
                        <option value="h3">Heading</option>
                      </select>
                    </label>
                  </div>
                  <span class="desc-toolbar-sep" aria-hidden="true"></span>
                  <div class="desc-toolbar-group">
                    <button type="button" class="desc-tool comment-tool" data-cmd="bold" title="Bold" aria-label="Bold"><b>B</b></button>
                    <button type="button" class="desc-tool comment-tool" data-cmd="italic" title="Italic" aria-label="Italic"><i>I</i></button>
                    <button type="button" class="desc-tool comment-tool" data-cmd="underline" title="Underline" aria-label="Underline"><u>U</u></button>
                  </div>
                  <span class="desc-toolbar-sep" aria-hidden="true"></span>
                  <div class="desc-toolbar-group">
                    <button type="button" class="desc-tool comment-tool" data-cmd="insertUnorderedList" title="Bullet list" aria-label="Bullet list">
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="2.5" cy="4" r="1.2"/><circle cx="2.5" cy="8" r="1.2"/><circle cx="2.5" cy="12" r="1.2"/><rect x="5.5" y="3.2" width="9" height="1.6" rx=".4"/><rect x="5.5" y="7.2" width="9" height="1.6" rx=".4"/><rect x="5.5" y="11.2" width="9" height="1.6" rx=".4"/></svg>
                    </button>
                    <button type="button" class="desc-tool comment-tool" data-cmd="insertOrderedList" title="Numbered list" aria-label="Numbered list">
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><text x="0" y="5.2" font-size="5.5" font-weight="700" font-family="system-ui,sans-serif">1.</text><text x="0" y="9.2" font-size="5.5" font-weight="700" font-family="system-ui,sans-serif">2.</text><text x="0" y="13.2" font-size="5.5" font-weight="700" font-family="system-ui,sans-serif">3.</text><rect x="5.5" y="3.2" width="9" height="1.6" rx=".4"/><rect x="5.5" y="7.2" width="9" height="1.6" rx=".4"/><rect x="5.5" y="11.2" width="9" height="1.6" rx=".4"/></svg>
                    </button>
                  </div>
                  <span class="desc-toolbar-sep" aria-hidden="true"></span>
                  <div class="desc-toolbar-group">
                    <button type="button" class="desc-tool comment-tool" data-cmd="createLink" title="Add link" aria-label="Add link">
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6.5 9.5a3.2 3.2 0 0 0 4.5.2l1.8-1.8a3.2 3.2 0 0 0-4.5-4.5L7.5 4.2"/><path d="M9.5 6.5a3.2 3.2 0 0 0-4.5-.2L3.2 8.1a3.2 3.2 0 0 0 4.5 4.5L8.5 11.8"/></svg>
                    </button>
                  </div>
                </div>
                <div id="modal-comment-text" class="desc-editable comment-editable" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Write a comment… (Enter to post)"></div>
              </div>

              <div id="modal-comments-list" class="comments-list"></div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button id="modal-delete" class="btn btn-danger">Delete card</button>
          <button id="modal-save" class="btn btn-primary">Save</button>
        </div>
      </div>
    </div>

    <!-- Member roster manager -->
    <div id="members-modal" class="modal-overlay" hidden>
      <div class="modal modal-sm" role="dialog" aria-modal="true" aria-label="Manage members">
        <button id="members-close" class="modal-close" aria-label="Close">&times;</button>
        <h2 class="modal-heading">Members</h2>
        <p class="modal-hint">Invite teammates to collaborate, then assign them to cards.</p>

        <div class="invite-box">
          <span class="modal-label">Invite a teammate</span>
          <div class="members-add">
            <input id="invite-email" class="member-input" type="email" placeholder="their@email.com">
            <button id="invite-send" class="btn btn-primary btn-mini">Invite</button>
          </div>
          <p class="modal-hint">They need an account first. Invited members can edit this board.</p>
        </div>

        <div class="invite-box">
          <span class="modal-label">Add a placeholder (no account)</span>
          <div class="members-add">
            <input id="member-name" class="member-input" type="text" placeholder="Name (e.g. Ada Lovelace)" maxlength="40">
            <button id="member-add" class="btn btn-primary btn-mini">Add</button>
          </div>
        </div>

        <div id="members-list" class="members-list"></div>
      </div>
    </div>

    <!-- New project modal (custom prompt replacement) -->
    <div id="new-project-modal" class="modal-overlay" hidden>
      <div class="modal modal-sm" role="dialog" aria-modal="true" aria-label="Create project">
        <button id="new-project-close" class="modal-close" aria-label="Close">&times;</button>
        <h2 class="modal-heading">New project</h2>
        <p class="modal-hint">Give your project a name — you can rename it later from the board title.</p>
        <form id="new-project-form" class="new-project-form">
          <input id="new-project-name" class="member-input" type="text" placeholder="Project name" maxlength="80" autocomplete="off" required>
          <div class="modal-footer">
            <button type="button" id="new-project-cancel" class="btn btn-soft">Cancel</button>
            <button type="submit" class="btn btn-primary">Create project</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Profile modal -->
    <div id="profile-modal" class="modal-overlay" hidden>
      <div class="modal modal-sm" role="dialog" aria-modal="true" aria-label="Edit Profile">
        <button id="profile-close" class="modal-close" aria-label="Close">&times;</button>
        <h2 class="modal-heading">My Profile</h2>

        <form id="profile-form">
          <div class="profile-avatar-section">
            <div class="avatar avatar-lg" id="profile-avatar-preview">
              <span id="profile-avatar-fallback"></span>
              <img id="profile-avatar-img" alt="Profile photo" hidden>
            </div>
            <div class="profile-avatar-actions">
              <button type="button" id="btn-upload-photo" class="btn btn-soft btn-mini">Upload Photo</button>
              <button type="button" id="btn-remove-photo" class="btn btn-soft btn-mini btn-remove-photo" hidden>Remove Photo</button>
              <input type="file" id="profile-photo-input" accept="image/*" hidden>
            </div>
          </div>

          <div class="modal-section">
            <label class="modal-label" for="profile-name">Display Name</label>
            <input id="profile-name" class="member-input" type="text" maxlength="60" placeholder="Your name" required>
          </div>

          <div class="modal-section">
            <label class="modal-label" for="profile-email">Email</label>
            <input id="profile-email" class="member-input input-disabled" type="email" disabled readonly>
          </div>

          <hr class="modal-divider">

          <div class="modal-section">
            <span class="modal-label">Change Password <span class="modal-hint-inline">(optional)</span></span>
            <div class="password-field margin-v">
              <input id="profile-cur-pass" class="member-input" type="password" placeholder="Current password" autocomplete="current-password">
              <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                <svg class="icon-eye" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="icon-eye-off" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" hidden fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <div class="password-field margin-v">
              <input id="profile-new-pass" class="member-input" type="password" placeholder="New password (min 8 chars)" autocomplete="new-password" minlength="8">
              <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                <svg class="icon-eye" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="icon-eye-off" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" hidden fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>
          <div class="modal-section">
            <span class="modal-label">Background Theme</span>
            <div class="bg-presets" id="bg-presets">
              <button type="button" class="bg-swatch" data-bg="linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #311042 100%)" style="background: linear-gradient(135deg, #0f172a, #311042);" title="Midnight Slate"></button>
              <button type="button" class="bg-swatch" data-bg="linear-gradient(135deg, #0f172a 0%, #0369a1 50%, #0284c7 100%)" style="background: linear-gradient(135deg, #0f172a, #0284c7);" title="Ocean Blue"></button>
              <button type="button" class="bg-swatch" data-bg="linear-gradient(135deg, #064e3b 0%, #047857 50%, #0f172a 100%)" style="background: linear-gradient(135deg, #064e3b, #047857);" title="Forest Moss"></button>
              <button type="button" class="bg-swatch" data-bg="linear-gradient(135deg, #4c1d95 0%, #831843 50%, #9f1239 100%)" style="background: linear-gradient(135deg, #4c1d95, #9f1239);" title="Sunset Violet"></button>
              <button type="button" class="bg-swatch" data-bg="#0f172a" style="background: #0f172a;" title="Dark Onyx"></button>
              <label class="bg-color-label" title="Custom color">
                <input type="color" id="bg-custom-picker" value="#0f172a">
                <span>Custom</span>
              </label>
            </div>
          </div>

          <p id="profile-error" class="auth-error" role="alert" hidden></p>
          <p id="profile-success" class="auth-success" role="status" hidden></p>

          <div class="modal-footer">
            <button type="button" id="profile-cancel" class="btn btn-soft">Cancel</button>
            <button type="submit" id="profile-save" class="btn btn-primary">Save Profile</button>
          </div>
        </form>

        <hr class="modal-divider">

        <div class="profile-actions-section">
          <span class="modal-label">Board Data & Account</span>
          <div class="profile-action-buttons">
            <button id="btn-export" class="btn btn-soft btn-sm" type="button">📥 Export Board</button>
            <button id="btn-import" class="btn btn-soft btn-sm" type="button">📤 Import Board</button>
            <input type="file" id="import-file" accept="application/json,.json" hidden>
          </div>
          <button id="btn-logout" class="btn btn-danger btn-block" type="button">Log out</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Image Lightbox Modal -->
  <div id="image-lightbox-modal" class="modal-overlay lightbox-overlay" hidden>
    <div class="lightbox-container">
      <button type="button" id="lightbox-close" class="lightbox-close" aria-label="Close image">&times;</button>
      <img id="lightbox-img" class="lightbox-img" src="" alt="">
      <div class="lightbox-caption">
        <span id="lightbox-title"></span>
        <a id="lightbox-download" href="#" target="_blank" class="btn btn-soft btn-mini" download>Download</a>
      </div>
    </div>
  </div>

  <div id="toast" class="toast" hidden></div>
  <div id="app-version" class="app-version" title="Task Board Version">v1.10.10</div>

  <script src="assets/app.js"></script>
</body>
</html>
