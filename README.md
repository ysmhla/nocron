# noCron - PHP Cron Alternative in PHP & Self-Resurrecting Task Scheduler

noCron is a **lightweight PHP task scheduler** designed as an alternative to traditional cron jobs. It allows you to run PHP scripts or external URLs at regular intervals **without system cron access**. Ideal for shared hosting, small projects, or quick automation.

---

## ⚡ Why noCron?

Traditional cron jobs are powerful but come with limitations:

- Requires server-level access (SSH or control panel).  
- Difficult to modify dynamically.  
- No built-in web monitoring.  
- Limited on shared hosting environments.  

**noCron solves these problems:**

- **Web-based setup & manager UI**  
- **Self-resurrecting worker** ensures continuous execution  
- **Dynamic task configuration** without editing server files  
- **Runs anywhere PHP is available**  

| Feature | Cron Jobs | noCron |
|---------|-----------|--------|
| Setup | Server crontab | Web installer |
| Flexibility | Fixed schedules | Adjustable interval/window via UI |
| Access | SSH or control panel | Browser-based |
| Monitoring | Logs or email | Manager UI shows status & allows updates |
| Portability | Server-specific | Any PHP-enabled server |

---

## ✅ Features

- Execute **PHP code** or **URLs** periodically  
- **Self-resurrecting worker** loop  
- Configurable **interval** & **window**  
- Web-based **Manager UI** to update or kill tasks  
- Lightweight & portable  
- Automatically generates unique filenames  

---

## 🛠 Installation

1. Upload `noCron-install.php` to a web-accessible folder.  
2. Open the installer in a browser.  
3. Fill in the fields:
   - **Task Type:** `PHP` or `URL`  
   - **Task Code/URL:** The PHP code or URL to run  
   - **Interval (sec):** How often the task should execute  
   - **Window (sec):** How long the worker runs before respawning  
   - **Optional Suffix:** Unique identifier for files (auto-generated if empty)  
4. Click **Install**.  
5. The installer creates:
   - `tmp/noCron-<suffix>.php` → Worker  
   - `tmp/noCronControl-<suffix>.php` → Manager  
   - `tmp/noCron.config.json` → Configuration  

---

## 🎯 Usage

- Open the **worker URL** once in your browser to start the task.  
- Access the **manager URL** to modify the task, interval, or stop execution.  
- Worker will automatically respawn after the configured window.  

---

## 🔒 Security

- All scripts are protected with a **secret token**.  
- Keep the token private to prevent unauthorized access.  

---

## 💡 Example Tasks

**PHP Task:**
```php
echo "Task executed at " . date('Y-m-d H:i:s');
