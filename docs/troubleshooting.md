# Troubleshooting

Common problems and their solutions. **Add to this file as you discover new issues** — paste the error message and what fixed it so the whole team benefits.

---

## XAMPP / Apache

**Problem:** Apache won't start in XAMPP

**Solution:** Port 80 is already in use by another application (often Skype, IIS, or another web server).

Option 1 — Find and close the application using port 80:
- Open Command Prompt as administrator: `netstat -ano | findstr :80`
- This shows the process ID (PID) using port 80
- Open Task Manager, find that PID, and end the task

Option 2 — Change Apache to use a different port:
- Open `C:\xampp\apache\conf\httpd.conf`
- Change `Listen 80` to `Listen 8080`
- Change `ServerName localhost:80` to `ServerName localhost:8080`
- Access Moodle at `http://localhost:8080` instead of `http://localhost`

---

**Problem:** Changes to `httpd.conf` or `php.ini` are not taking effect

**Solution:** Fully stop and start Apache — not just restart. In XAMPP Control Panel, click **Stop**, wait for the row to turn grey, then click **Start**.

---

**Problem:** `http://localhost` still shows the XAMPP default page instead of Moodle

**Solution:** The DocumentRoot in `httpd.conf` was not changed correctly. Open the file and verify:
```
DocumentRoot "C:/Projects/umat-vle-enhanced/moodle"
```
Use forward slashes, not backslashes. Save and restart Apache.

---

## PostgreSQL

**Problem:** pgAdmin asks for a master password and you forgot it

**Solution:** In pgAdmin, go to **File** → **Reset Master Password**. This resets the pgAdmin master password only — it does not affect your actual database passwords.

---

**Problem:** Moodle installer shows "connection refused" when connecting to PostgreSQL

**Solution:** PostgreSQL service may not be running. Open Windows Services (Windows + R → `services.msc`). Find `postgresql-x64-15`, right-click → **Start**.

---

**Problem:** "FATAL: password authentication failed for user postgres" during Moodle installation

**Solution:** The password you typed in the Moodle installer does not match the `postgres` superuser password set during PostgreSQL installation. Open pgAdmin, right-click **postgres** under Login/Group Roles → **Properties** → **Definition** tab → reset the password to match what you are entering in the installer.

---

**Problem:** pgAdmin shows the `moodle` database but no tables inside it after trying to install Moodle

**Solution:** The Moodle installation was not completed. Moodle only creates its tables during the web installer. Visit `http://localhost` and complete the installer from the beginning.

---

## Python AI Service

**Problem:** `ModuleNotFoundError` when running `python main.py`

**Solution:** Your virtual environment is not activated. Run:
```bash
venv\Scripts\activate
```
Your terminal prompt should show `(venv)` at the start. Then try again.

---

**Problem:** `error: Microsoft Visual C++ 14.0 or greater is required` when running `pip install`

**Solution:** Some Python packages need C++ build tools. Download and install **Microsoft C++ Build Tools** from:
`https://visualstudio.microsoft.com/visual-cpp-build-tools/`

Select **"Desktop development with C++"** during installation. Then try `pip install` again.

---

**Problem:** Whisper model download fails or times out

**Solution:** The base model is ~140MB. Check your internet connection and try again:
```bash
python -c "import whisper; whisper.load_model('base')"
```
The model caches to `C:\Users\YourName\.cache\whisper\` after the first download.

---

**Problem:** `ffmpeg not found` or `ffmpeg is not recognized` error during audio processing

**Solution:** ffmpeg is not in your Windows PATH. Open a **new** terminal and test:
```bash
ffmpeg -version
```
If it says "not recognized", redo the ffmpeg PATH step from setup. Always open a brand new terminal after making PATH changes.

---

**Problem:** `pydantic_settings` import error when starting the AI service

**Solution:**
```bash
pip install pydantic-settings
```

---

**Problem:** ChromaDB version error or import failure

**Solution:** The ChromaDB API changed in versions after 0.5.0. Check your installed version:
```bash
pip show chromadb
```
If it is higher than 0.5.0, open `ai_service/core/vector_store.py` and update the import path according to the ChromaDB changelog for your version.

---

**Problem:** `declarative_base` deprecation warning from SQLAlchemy

**Solution:** This is a warning, not an error — the code still works. To silence it, update `ai_service/models/database.py`:
```python
# Change this:
from sqlalchemy.ext.declarative import declarative_base

# To this:
from sqlalchemy.orm import DeclarativeBase
class Base(DeclarativeBase):
    pass
```

---

**Problem:** Gemini API returns `RESOURCE_EXHAUSTED` or quota error

**Solution:** You have hit the Gemini free-tier rate limit. Either wait a minute and try again, or contact **Chrispen** ([@derychris](https://github.com/derychris)) to check the API quota on the project's Google AI Studio account. During development, test with short transcripts to conserve quota.

---

## Moodle Plugin

**Problem:** UMaT AI plugin not detected when visiting `http://localhost/admin`

**Solution:** Moodle 5.x scans `moodle/public/local/` for local plugins. Verify:
1. The folder is at `moodle/public/local/umat_ai/` (not `moodle/local/umat_ai/`)
2. It is not double-nested as `moodle/public/local/umat_ai/umat_ai/`
3. `version.php` exists in that folder
4. Enable Moodle developer debugging (Site Admin → Development → Debugging → DEVELOPER) and refresh — errors will show on screen

---

**Problem:** UMaT theme not appearing in Theme selector

**Solution:** Moodle 5.x scans `moodle/public/theme/` for themes. Verify the folder is at `moodle/public/theme/umat/` and contains `config.php` and `version.php`.

---

**Problem:** Plugin installs but scheduled tasks don't appear in Site Admin → Server → Scheduled tasks

**Solution:** There is likely a PHP syntax error in `moodle/public/local/umat_ai/db/tasks.php`. Enable Moodle debugging and visit `/admin` again — the error will be displayed. Check for missing semicolons, incorrect class names, or wrong namespace format.

---

**Problem:** AJAX call from chat panel returns 403 Forbidden

**Solution:** The Moodle capability is not assigned to the role. Go to: Site Admin → Users → Permissions → Define roles → click **Student** → find `local/umat_ai:chatwithai` → set to **Allow** → Save.

---

**Problem:** AJAX call returns "Web service is not available" error

**Solution:** Web services are not enabled globally. Go to: Site Admin → Advanced Features → check **Enable web services** → Save. Then verify the UMaT AI Service is enabled at Site Admin → Plugins → Web services → External services.

---

## Git

**Problem:** `git push` asks for username and password every time

**Solution:** Set up a GitHub Personal Access Token (PAT).

Go to GitHub → profile picture → **Settings** → **Developer settings** → **Personal access tokens** → **Tokens (classic)** → **Generate new token**. Check the `repo` scope. Copy the token — you only see it once.

Then enable credential storage so Git remembers it:
```bash
git config --global credential.helper store
```

The next time you push, enter your GitHub username and the token as the password. Git remembers from then on.

---

**Problem:** "Please tell me who you are" error on first commit

**Solution:**
```bash
git config --global user.name "Your Full Name"
git config --global user.email "your.github.email@example.com"
```

---

**Problem:** `git push` is rejected with "Updates were rejected because the remote contains work that you do not have locally"

**Solution:** A teammate pushed after you last pulled. Pull first, then push:
```bash
git pull origin main
git push origin main
```

If `git pull` shows conflicts, see the conflict resolution section in [CONTRIBUTING.md](../CONTRIBUTING.md).

---

**Problem:** I accidentally committed my `.env` file with real API keys

**Solution:** Act immediately:
1. Delete the `.env` file from the repository (add it to `.gitignore` if not already there)
2. Commit and push the removal
3. **Immediately** tell Chrispen ([@derychris](https://github.com/derychris)) — he manages all API keys and must regenerate the compromised ones
4. Update your local `.env` with the new keys from Chrispen

Even after removing from Git, the key remains in the commit history and must be treated as public until rotated.

---

## Moodle General

**Problem:** Moodle is very slow or pages time out

**Solution:** Check `php.ini`:
```ini
max_execution_time = 360
memory_limit = 512M
```
Also run the cron manually to clear any stuck tasks:
```bash
C:\xampp\php\php.exe C:\Projects\umat-vle-enhanced\moodle\admin\cli\cron.php
```

---

**Problem:** Moodle shows a blank white page

**Solution:** There is a PHP fatal error. Enable debugging temporarily:
1. Open `C:\Projects\umat-vle-enhanced\moodle\config.php`
2. Add before the closing `?>`:
```php
@error_reporting(E_ALL); @ini_set('display_errors', '1');
```
3. Reload — the error appears
4. Fix the error, then remove those two lines

---

*Last updated by: [add your name and date when you update this file]*