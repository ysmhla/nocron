
# noCron - PHP Cron Alternative & Self-Resurrecting Task Scheduler

**noCron** is a lightweight, PHP-based task scheduler designed to replace traditional cron jobs—especially useful in environments where you don't have server-level cron access.

It allows you to run PHP code or call external URLs on a recurring schedule, complete with a self-resurrecting mechanism and a web interface for management and monitoring.

---

## 🚀 Features

- **Cron Alternative** – Run scheduled tasks without relying on system-level cron.
- **Self-Resurrecting** – Automatically respawns tasks using cURL to ensure continuous execution.
- **Web Interface** – Bootstrap-based UI to manage tasks, view logs, and see stats.
- **Flexible Tasks** – Execute inline PHP code or external URLs.
- **Multiple Instances** – Run several isolated schedulers with custom suffixes.
- **Secure** – Requires HTTPS and a unique secret key for authentication.
- **Logging & Stats** – Monitor run history, errors, and task outcomes.

---

## ⚙️ Requirements

- PHP 7.4 or higher
- `cURL` extension enabled
- HTTPS-enabled web server
- Write permissions in the project root directory

---

## 📦 Installation

### 1. Place the Installer

Copy `noCron.php` to your project’s root directory (e.g., `/var/www/html/`).  
Ensure the directory is writable.

### 2. Access the Installer

Open `noCron.php` in your browser:

```

[https://yourdomain.com/noCron.php](https://yourdomain.com/noCron.php)

```

Fill out the form:

- **Task Type** – Choose between `PHP Code` or `URL`.
- **Task Code/URL** – Enter the PHP code snippet or URL to execute.
- **Interval (sec)** – How often to run the task (1–3600 seconds).
- **Window (sec)** – How long the worker should run before respawning (must be greater than interval; max 86400 seconds).
- **Custom Suffix** – (Optional) A unique identifier (3–20 alphanumeric characters). Leave blank for a random one.

Click **Submit** to install noCron.

### 3. Post-Installation

The installer will create a folder:  
`noCron-{suffix}/` containing:

- `noCron-{suffix}.php` – Worker script
- `noCronControl-{suffix}.php` – Web-based manager
- `noCron-{suffix}.config.json` – Task configuration
- `stats-{suffix}.json` – Run statistics
- `nocron-{suffix}.log` – Task logs
- `stop-{suffix}.txt` – Temporary file for pausing the worker

You’ll also receive two links (worker + manager), each with a unique auth secret.

### 4. Start the Worker

Click the worker link:

```

noCron-{suffix}/noCron-{suffix}.php?auth=SECRET

```

Close the browser window after the initial start.  
The worker will keep running and auto-respawn via cURL.

---

## 🛠️ Usage

### Manager Interface

Access the manager at:

```

noCron-{suffix}/noCronControl-{suffix}.php?auth=SECRET

```

From there, you can:

- View live stats: total runs, successes, failures
- See last/next run times
- View recent logs (last 10 entries)
- Edit task code, type, interval, or window
- Pause/resume the worker
- Uninstall the instance

### Pausing/Resuming

- Click **Pause** – creates a `stop-{suffix}.txt` file that halts the worker gracefully.
- Click **Resume** – deletes the stop file and resumes the task.

### Uninstalling

Click **Uninstall** in the manager interface to remove all files related to the instance.

---

## 📁 File Structure

```

/project-root
├── index.php                  # Installer script
├── noCron-{suffix}/
│   ├── noCron-{suffix}.php           # Worker script
│   ├── noCronControl-{suffix}.php    # Manager UI script
│   ├── noCron-{suffix}.config.json   # Configuration file
│   ├── stats-{suffix}.json           # Run statistics
│   ├── nocron-{suffix}.log           # Log file
│   └── stop-{suffix}.txt             # Pause trigger (if exists)

````

---

## 🧪 Example

To log the current time every 10 seconds:

1. In the installer, choose **PHP Code** as the task type.
2. Enter the following code:

```php
file_put_contents('output.txt', date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND);
````

3. Set:

   * **Interval**: 10 seconds
   * **Window**: 60 seconds
4. Choose a suffix (e.g., `mytask`) or leave it blank.
5. Submit the form and start the worker via the provided link.

---

## 🔐 Security Notes

* **HTTPS Required** – noCron will not function over HTTP.
* **Authentication Secret** – Each instance is protected by a unique 64-character secret.
* **Input Validation** – Input types, intervals, and suffixes are all validated.
* **Safe File Writes** – Configuration and logs use file-locking to prevent corruption.

---

## ⚠️ Limitations

* **Max Interval**: 3600 seconds (1 hour)
* **Max Window**: 86400 seconds (1 day)
* **Suffix Length**: 3–20 alphanumeric characters
* **PHP Eval**: PHP task execution uses `eval()` — sanitize your code inputs.
* **No HTTP Support**: HTTPS is required for all operations.

---

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository.
2. Create a feature branch:
   `git checkout -b feature/YourFeature`
3. Commit your changes:
   `git commit -m 'Add YourFeature'`
4. Push to the branch:
   `git push origin feature/YourFeature`
5. Open a Pull Request.

---

## 📄 License

This project is licensed under the [MIT License](LICENSE).

---

## 🙏 Acknowledgments

* [Bootstrap 5.3.3](https://getbootstrap.com/) – for the responsive web interface
* [jQuery 3.7.1](https://jquery.com/) – for AJAX handling
