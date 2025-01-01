<?php

class active_sessions extends rcube_plugin
{
    //public $task = 'mail|settings';
    private $rc;

    public function init()
    {
        $this->rc = rcmail::get_instance();
        $this->load_config();
        $this->add_texts('localization/');

        $this->check_db_schema();
        
        // =====================================
        // Hook into session_auth
        // =====================================
        if ($this->rc->task === 'mail' || $this->rc->task === 'settings') {
            $this->store_user_agent_once();
        }

        // Register your “Active Sessions” in settings
        if ($this->rc->task == 'settings') {
            $this->add_hook('settings_actions', [$this, 'settings_actions']);
            $this->register_action('plugin.active_sessions', [$this, 'show_sessions']);
            $this->register_action('plugin.terminate_session', [$this, 'terminate_session']);
            $this->register_action('plugin.terminate_all_sessions', [$this, 'terminate_all_sessions']);
            $this->register_action('plugin.terminate_dovecot_session', [$this, 'terminate_dovecot_session']);
        }

    }

    /**
        * This function is triggered when a user logs in successfully
    */
    private function store_user_agent_once()
    {
        // Roundcube is using PHP sessions. session_id() gives the same ID as `sess_id` in the DB.
        $sess_id = session_id();
        if (!$sess_id) {
            return; // no active session ID
        }
    
        $db = $this->rc->get_dbh();
    
        // Select the fields we might update
        $row = $db->query(
            "SELECT ip, user_agent, location FROM session WHERE sess_id = ?",
            $sess_id
        )->fetch(PDO::FETCH_ASSOC);
    
        if ($row) {
            $ua_string = $row['user_agent'];
            $loc_field = $row['location'];
    
            // Only set user_agent if it's missing
            if (empty($ua_string)) {
                // 1) Try server's HTTP_USER_AGENT
                $ua_string = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
                // 2) If still empty or obviously truncated, try a client-posted user agent
                if (!$ua_string || strlen($ua_string) < 10) {
                    // Grab the UA from an AJAX POST (if you send it as 'client_ua')
                    $client_ua = rcube_utils::get_input_value('client_ua', rcube_utils::INPUT_POST);
    
                    if (!empty($client_ua)) {
                        $ua_string = $client_ua;
                    }
                }
    
                // 3) If still empty, set to something generic
                if (!$ua_string) {
                    $ua_string = 'Unknown';
                }
            }
    
            // Only call get_geolocation() if location is empty
            if (empty($loc_field)) {
                $loc_field = $this->get_geolocation($row['ip']);
            }
    
            // If either field was empty, do a single UPDATE to fill them
            if (empty($row['user_agent']) || empty($row['location'])) {
                $db->query(
                    "UPDATE session
                     SET user_agent = ?, location = ?
                     WHERE sess_id = ?",
                    $ua_string,
                    $loc_field,
                    $sess_id
                );
                error_log("Updated user_agent/location for session {$sess_id}: [UA={$ua_string}, location={$loc_field}]");
            }
        }
    }
    
    /**
     * Add the plugin to the settings menu
     */
    function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.active_sessions',
            'class'  => 'active_sessions',
            'label'  => 'active_sessions',
            'title'  => 'Active Sessions',
            'domain' => 'active_sessions',
        ];
        return $args;
    }

    /**
     * Show active sessions
     */
    function show_sessions()
    {
        $sessions = $this->get_sessions();
        
        // Debug output
        error_log("Sessions sent to frontend: " . json_encode($sessions));
        
        $this->rc->output->add_script('window.active_sessions = ' . json_encode($sessions), 'foot');
        $this->register_handler('plugin.body', [$this, 'render_sessions']);
        $this->rc->output->set_pagetitle($this->gettext('active_sessions'));
        $this->include_stylesheet('styles.css');
        $this->include_script('ua-parser.pack.min.js', 'foot');
        $this->include_script('active_sessions.js', 'foot');
        $this->rc->output->send('plugin');
    }

    /**
     * Render the HTML for sessions
     */
    function render_sessions($attrib)
    {
        return '
            <div id="active-sessions">
                <h2>Active Sessions</h2>
                <table>
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Location</th>
                            <th>Task</th>
                            <th>Last Activity</th>
                            <th>User Agent</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <button id="terminate-all-sessions" class="button">
                    Force Logout All Devices
                </button>
            </div>
        ';
    }

    /**
     * Terminate a specific session
     */
    function terminate_session()
    {
        $sess_id = rcube_utils::get_input_value('sess_id', rcube_utils::INPUT_POST);
        $db = $this->rc->get_dbh();
        $db->query("DELETE FROM session WHERE sess_id = ?", $sess_id);

        error_log("Session terminated: {$sess_id} by user: " . $this->rc->user->get_username());

        // Trigger client refresh
        $this->rc->output->command('plugin.active_sessions_refresh');
    }

    /**
     * Terminate all sessions for the current user
     */
    function terminate_all_sessions()
    {
        $db = $this->rc->get_dbh();
        $username = $this->rc->user->get_username();

        if (!$username) {
            error_log("No logged-in username found for terminating all sessions.");
            return;
        }

        // 1. Terminate all Roundcube sessions
        $db->query("DELETE FROM session WHERE vars IS NOT NULL AND vars != ''");
        error_log("All Roundcube sessions terminated for user: {$username}");

        // 2. Fetch and terminate all Dovecot sessions
        $dovecot_sessions = $this->get_dovecot_sessions();
        if (empty($dovecot_sessions)) {
            error_log("No Dovecot sessions found for user: {$username}");
        }

        $dovecot_sessions_terminated = 0;
        foreach ($dovecot_sessions as $session) {
            $ip = $session['ip'];
            $command = "sudo doveadm kick {$username} {$ip}";
            exec($command . " 2>&1", $output, $status);
            if ($status === 0) {
                error_log("Dovecot session terminated for user: {$username}, IP: {$ip}");
                $dovecot_sessions_terminated++;
            } else {
                error_log("Failed to terminate Dovecot session for user: {$username}, IP: {$ip}. Output: " . implode("\n", $output));
            }
        }

        if ($dovecot_sessions_terminated > 0) {
            error_log("Total Dovecot sessions terminated for user: {$username}: {$dovecot_sessions_terminated}");
        }

        // Trigger client refresh
        $this->rc->output->command('plugin.active_sessions_refresh');
    }

    /**
     * Helper to get sessions
     */
    function get_sessions()
    {
        $db = $this->rc->get_dbh();
        $query = "SELECT sess_id, ip, changed, vars, user_agent
                FROM session
                WHERE user_agent IS NOT NULL AND user_agent != ''";
        $sessions = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sessions as &$session) {
            $vars = $this->parse_session_vars($session['vars']);
            $session['language'] = $vars['language'] ?? 'Unknown';
            $session['task'] = $vars['task'] ?? 'Unknown';
            $session['theme'] = $vars['skin_config']['jquery_ui_colors_theme'] ?? 'Default';
            $session['dark_mode'] = !empty($vars['skin_config']['dark_mode_support']) ? 'Enabled' : 'Disabled';
            $session['location'] = $this->get_geolocation($session['ip']);
        }

        // Merge Roundcube sessions with Dovecot sessions
        $dovecot_sessions = $this->get_dovecot_sessions();
        // print_r($username = $this->rc->user->get_username());
        return array_merge($sessions, $dovecot_sessions);
    }

    private function get_dovecot_sessions()
    {
        // Get the logged-in user's username
        $username = $this->rc->user->get_username();
        if (!$username) {
            error_log("No logged-in username found");
            return [];
        }

        $output = [];
        exec('sudo doveadm who 2>&1', $output, $status); // Capture stderr too

        error_log("Doveadm who command status: $status");
        error_log("Doveadm who command output: " . print_r($output, true));

        if ($status !== 0) {
            error_log("Dovecot who command failed with status: $status");
            return [];
        }

        $sessions = [];
        foreach ($output as $line) {
            // Match session lines and filter by username
            if (preg_match('/^(\S+)\s+\d+\s+imap\s+\(([^)]+)\)\s+\(([^)]+)\)$/', $line, $matches)) {
                if ($matches[1] === $username) { // Check if the username matches
                    $pids = explode(' ', $matches[2]); // Split PIDs
                    $ips = explode(' ', $matches[3]);  // Split IPs

                    // Create a session entry for each IP
                    foreach ($ips as $index => $ip) {
                        $sessions[] = [
                            'username' => $matches[1],
                            'ip' => $ip,
                            'location' => $this->get_geolocation($ip),
                            'task' => 'imap',
                            'changed' => 'N/A', // Replace with timestamp if available
                            'user_agent' => 'Client App', // Placeholder
                            'pid' => $pids[$index] ?? 'Unknown', // Match PID to IP if possible
                        ];
                    }
                }
            } else {
                error_log("Failed to parse line: $line");
            }
        }

        error_log("Parsed Dovecot sessions for user {$username}: " . print_r($sessions, true));
        return $sessions;
    }

    function terminate_dovecot_session()
    {
        $ip = rcube_utils::get_input_value('ip', rcube_utils::INPUT_POST);
        $username = rcube_utils::get_input_value('username', rcube_utils::INPUT_POST);

        if ($ip && $username) {
            // Sanitize inputs manually to prevent injection
            $sanitized_username = $username;
            $sanitized_ip = $ip;

            if ($sanitized_username && $sanitized_ip) {
                // Construct the command
                $command = "sudo doveadm kick {$sanitized_username} {$sanitized_ip}";
                exec($command . " 2>&1", $output, $status);

                if ($status === 0) {
                    error_log("Dovecot session terminated for user: {$sanitized_username}, IP: {$sanitized_ip}");
                    $this->rc->output->command('plugin.active_sessions_refresh');
                } else {
                    error_log("Failed to terminate Dovecot session for user: {$sanitized_username}, IP: {$sanitized_ip}. Output: " . implode("\n", $output));
                }
            } else {
                error_log("Sanitized inputs are empty or invalid.");
            }
        } else {
            error_log("Missing IP or username for terminate_dovecot_session");
        }
    }

    /**
     * Parse session vars (base64 & unserialize)
     */
    private function parse_session_vars($vars)
    {
        $decoded = base64_decode($vars);
        if ($decoded === false) {
            return [];
        }
        $parsed = @unserialize($decoded);
        return $parsed ?: [];
    }

    /**
     * Example geolocation
     */
    private function get_geolocation($ip)
    {
        // This is just an example. Check for errors, etc.
        $response = @file_get_contents("http://ip-api.com/json/{$ip}");
        $data = @json_decode($response, true);
        if ($data && $data['status'] === 'success') {
            return "{$data['city']}, {$data['regionName']}, {$data['country']}";
        }
        return 'Unknown';
    }

    private function check_db_schema()
    {
        $db = $this->rc->get_dbh();

        // Check if 'user_agent' column exists
        // Note: MySQL/MariaDB example. For other DBs, adapt accordingly.
        $sql = "SHOW COLUMNS FROM `session` LIKE 'user_agent'";
        $col_user = $db->query($sql)->fetch();

        // Check if 'location' column exists
        $sql = "SHOW COLUMNS FROM `session` LIKE 'location'";
        $col_loc = $db->query($sql)->fetch();

        // If either column is missing, run the ALTER TABLE
        if (!$col_user || !$col_loc) {
            $alter_sql = "ALTER TABLE `session` ";
            $clauses   = [];

            if (!$col_user) {
                $clauses[] = "ADD COLUMN `user_agent` TEXT";
            }
            if (!$col_loc) {
                $clauses[] = "ADD COLUMN `location` VARCHAR(255)";
            }

            // Build final ALTER statement
            $alter_sql .= implode(", ", $clauses);

            try {
                $db->query($alter_sql);
                rcube::write_log('installer', "Added missing columns to 'session' table: " . implode(', ', $clauses));
            }
            catch (Exception $e) {
                rcube::write_log('installer', "Failed to alter 'session' table: " . $e->getMessage());
            }
        }
    }

}
