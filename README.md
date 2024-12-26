# Roundcube Active Sessions Plugin

**Author**: Dr. B. M. Riazul Islam  
**Version**: 1.0.0  
**Compatible With**: Roundcube 1.5+ (tested), likely works with other versions if configured properly

## Description

This plugin provides a **“Active Sessions”** interface in Roundcube’s Settings panel, allowing users to:

- **View** all currently active sessions (including IP, location, user agent, browser, OS, language, task, theme, and more).  
- **Terminate** (logout) individual sessions or all sessions at once.  
- **Capture** and display additional details, such as **geolocation** (city/region/country) and **user agent** info (parsed via ua-parser).  
- **Automatically update** the Roundcube `session` table schema to include `user_agent` and `location` columns if they are missing.

This is especially useful for users who want to monitor where and how their accounts are accessed, and to remotely log out sessions they no longer trust.

## Features

1. **Active Sessions Page**: In the Settings → *Active Sessions*, you’ll see a table showing:
   - **IP address**  
   - **Location** (GeoIP lookup)  
   - **Language**, **Task**, **Theme**, **Dark Mode**  
   - **Last Activity** (session `changed` timestamp)  
   - **Browser** and **Operating System** (parsed from the user agent)  
2. **Terminate Session**: Log out any single session you see.  
3. **Force Logout All Devices**: Immediately terminate *all* active sessions.  
4. **Automatic DB Schema Update**: If `user_agent` or `location` columns don’t exist in the `session` table, the plugin automatically adds them.

## Installation

1. **Download or clone** this plugin into your Roundcube `plugins/active_sessions` directory:
   ```bash
   cd /path/to/roundcubemail/plugins
   git clone https://github.com/riazsomc/active_sessions.git
   ```
   (Or manually place the files in a folder named `active_sessions`.)

2. **Enable the plugin** in your Roundcube config:

   ```php
   // In config/config.inc.php or defaults.inc.php
   $config['plugins'] = [
       // ...other plugins...
       'active_sessions',
   ];
   ```

3. **(Optional)** If you’re using a geolocation service, ensure `allow_url_fopen` or cURL is enabled on your server so the plugin can fetch IP location data via `ip-api.com`.

4. **Clear caches** if necessary (e.g., if your Roundcube environment uses caching).

When Roundcube first loads with this plugin enabled, it will check your `session` table. If the columns `user_agent` and `location` are missing, it will run an `ALTER TABLE` statement to add them automatically.

> **Note**: Make sure your Roundcube DB user has the **ALTER** privilege.

## Usage

1. **Navigate to Roundcube → Settings**.  
2. **Find the “Active Sessions”** entry in the settings menu.  
3. You’ll see a table listing all active sessions for your account:
   - **IP** and **Location**  
   - **Browser/OS** (if user agent is available)  
   - **Dark Mode** / **Theme**  
   - **Last Activity** timestamp  
4. **Logout** any single session by clicking **“Logout”** next to it.  
5. **Force Logout All Devices** by clicking the **“Force Logout All Devices”** button at the bottom.

## iOS Safari / Truncated User Agent

Due to privacy restrictions, iOS Safari sometimes sends a **shortened** user agent. This plugin attempts to parse as much info as possible. If the server-side user agent is missing or truncated, there is a **JavaScript fallback** that posts the full `navigator.userAgent` to the server. This can require **JavaScript** to run on each mail/settings page after login, so if you still see “Unknown” for iOS Safari, confirm:

- You’re **not** in Private Browsing, which may block localStorage or other storage mechanisms.  
- The plugin’s **JavaScript** is included in the mail or settings task, so it runs *after* you’ve logged in.

## Advanced Configuration

- **Geolocation**: The plugin uses `ip-api.com` for geolocation by default. You can replace that in the method `get_geolocation($ip)` with a different service if you prefer.  
- **session storage**: The plugin requires `$config['session_storage'] = 'db'` in your Roundcube config to store data in the `session` table. If you use `php` or `memcache` session storage, this plugin won’t see session data.  
- **Hook updates**: By default, the plugin updates `user_agent` and `location` once per session, after the user is fully logged in (i.e., in `_task=mail` or `_task=settings`). This avoids partial session rows.

## Troubleshooting

1. **User Agent / Location fields never fill**:
   - Check that **DB columns** exist (the plugin attempts to create them automatically).  
   - Ensure the **Roundcube DB user** has permission to run `ALTER TABLE session ...`.  
   - Confirm `$config['session_storage'] = 'db'`.  
   - iOS Safari may need client-side code to capture the full user agent (look for JS errors or private browsing restrictions).

2. **Database errors**:
   - If you see “Failed to alter `session` table,” you likely need to grant `ALTER` privileges to your Roundcube DB user or manually add the columns.

3. **Plugin not appearing under Settings**:
   - Make sure the folder name is exactly `active_sessions`.  
   - Confirm you have `$config['plugins'][] = 'active_sessions';` in your Roundcube config.

4. **“Unknown” for Browser/OS**:
   - This typically means the user agent is either very minimal or not present at all. If iOS Safari is the culprit, the JavaScript fallback might be blocked. Check console logs or try a different device for testing.

## License

This plugin is licensed under the [GNU General Public License version 3 (GPLv3)](https://www.gnu.org/licenses/gpl-3.0.html) or any later version. See the LICENSE file for details.

## Contributing

Pull requests and issues are welcome!  

1. Fork the repository  
2. Create a feature branch (`git checkout -b my-feature`)  
3. Commit your changes  
4. Push the branch and open a Pull Request

## Credits

- Inspired by Roundcube’s default session handling system  
- Geolocation via [ip-api.com](http://ip-api.com/)  
- User agent parsing via [ua-parser-js](https://github.com/faisalman/ua-parser-js)

---

### Thank You!

Enjoy controlling and monitoring your Roundcube sessions more securely. For questions or feedback, open an issue on the plugin’s repository or contact the author directly.