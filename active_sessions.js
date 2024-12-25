document.addEventListener('DOMContentLoaded', function () {
    // 1) Grab references to the table body and the "terminate all" button
    const tableBody = document.querySelector('#active-sessions tbody');
    const terminateAllBtn = document.getElementById('terminate-all-sessions');

    // 2) Basic sanity checks
    if (!tableBody || !terminateAllBtn) {
        console.warn('Active Sessions HTML not found.');
        return;
    }

    // 3) Check if we have any sessions passed from PHP
    if (!window.active_sessions || window.active_sessions.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="10">No active sessions available.</td></tr>';
        return;
    }

    // 4) Set up UA Parser
    const parser = new UAParser();

    // 5) Clear existing rows (if any), then populate
    tableBody.innerHTML = '';
    window.active_sessions.forEach((session) => {
        // session.user_agent is the database column value

        // -- Parse the user_agent string with UA Parser --
        parser.setUA(session.user_agent || '');
        const result = parser.getResult();

        // Build strings for Browser and OS, falling back to "Unknown"
        const browser = result.browser.name
            ? (result.browser.version
                ? `${result.browser.name} ${result.browser.version}`
                : result.browser.name
              )
            : 'Unknown';

        const os = result.os.name
            ? (result.os.version
                ? `${result.os.name} ${result.os.version}`
                : result.os.name
              )
            : 'Unknown';

        // 6) Build a table row with all the fields
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${session.ip}</td>
            <td>${session.location}</td>
            <td>${session.language}</td>
            <td>${session.task}</td>
            <td>${session.theme}</td>
            <td>${session.dark_mode}</td>
            <td>${session.changed}</td>
            <td>${browser}</td>    <!-- From user_agent parsing -->
            <td>${os}</td>         <!-- From user_agent parsing -->
            <td>
                <button class="terminate-session button" data-sess-id="${session.sess_id}">
                    Logout
                </button>
            </td>
        `;
        tableBody.appendChild(row);
    });

    // 7) Attach event handlers for single-session logout
    tableBody.querySelectorAll('.terminate-session').forEach((btn) => {
        btn.addEventListener('click', function () {
            const sessId = this.dataset.sessId;
            // Roundcube’s JS function to call your plugin action
            rcmail.http_post('plugin.terminate_session', { sess_id: sessId });
        });
    });

    // 8) Attach event handler for "Force Logout All Devices"
    terminateAllBtn.addEventListener('click', function () {
        rcmail.http_post('plugin.terminate_all_sessions', {});
    });
});

// 9) Listen for server-side command to refresh
if (window.rcmail) {
    rcmail.addEventListener('plugin.active_sessions_refresh', function () {
        window.location.reload();
    });

    // 10) Post the full client-side user agent (once) in case iOS Safari's server UA is truncated
    rcmail.addEventListener('init', function () {
        const fullUA = navigator.userAgent || '';
        // Only send once per browser, storing a flag in localStorage
        if (!localStorage.getItem('ua_submitted')) {
            rcmail.http_post('', { client_ua: fullUA }); 
            localStorage.setItem('ua_submitted', '1');
        }
    });
}
