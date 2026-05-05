# Troubleshooting

Common problems and their solutions. **Add to this file as you discover new issues** — paste the error message and what fixed it so the whole team benefits.

---

## XAMPP / Apache

**Problem:** Apache won't start in XAMPP

**Solution:** Port 80 is already in use by another application (often Skype, IIS, or another web server).

Option 1 — Find and close the application using port 80:
- Open Command Prompt as administrator and run: `netstat -ano | findstr :80`
- This shows which process ID (PID) is using port 80
- Open Task Manager, find that PID, and end the task

Option 2 — Change Apache to use a different port:
- Open `C:\xampp\apache\conf\httpd.conf`
- Change `Listen 80` to `Listen 8080`
- Change `ServerName localhost:80` to `ServerName localhost:8080`
- Access Moodle at `http://localhost:8080` instead of `http://localhost`

---

**Problem:** Changes to `httpd.conf` or `php.ini` are not taking effect

**Solution:** You must fully stop and start Apache — not just restart. In XAMPP Control Panel, click **Stop**, wait for the row to turn grey, then click **Start**.

---

**Problem:** `http://localhost` still shows the XAMPP default page instead of Moodle

**Solution:** The DocumentRoot in `httpd.conf` was not changed correctly. Open the file and verify the line reads exactly:
```
DocumentRoot "C:/Projects/umat-vle-enhanced/moodle"
```
Use forward slashes, not backslashes. Save the file and restart Apache.

---

## PostgreSQL

**Problem:** pgAdmin asks for a master password and you forgot it

**Solution:** In pgAdmin, go to **File** → **Reset Master Password**. Note: this resets the master password but does not affect your actual database user passwords.

---

**Problem:** Moodle installer shows "connection refused" when connecting to PostgreSQL

**Solution:** PostgreSQL service may not be running. Open Windows Services (Windows + R → type `services.msc` → Enter). Find `postgresql-x64-15`, right-click → **Start**.

---

**Problem:** "FATAL: password authentication failed for user moodleuser" during Moodle installation

**Solution:** The password you typed in the Moodle installer does not match what was set in PostgreSQL. Open pgAdmin, right-click **moodleuser** under Login/Group Roles → **Properties** → **Definition** tab → change the password to match exactly what you are entering in the Moodle installer.

---

**Problem:** pgAdmin shows "moodledb" but no tables inside it after trying to install Moodle

**Solution:** The Moodle installation was not completed. Moodle only creates its tables during the web installer process. Visit `http://localhost` and complete the installer from the beginning.

---

## Python AI Service

**Problem:** `ModuleNotFoundError` when running `python main.py`

**Solution:** Your virtual environment is not activated. Run this first:
```bash
venv\Scripts\activate
```
Your terminal prompt should show `(venv)` at the start. Then try again.

---

**Problem:** `error: Microsoft Visual C++ 14.0 or greater is required` when running `pip install`

**Solution:** Some Python packages need C++ build tools to compile. Download and install **Microsoft C++ Build Tools** from:
`https://visualstudio.microsoft.com/visual-cpp-build-tools/`

Click "Download Build Tools", run the installer, and select **"Desktop development with C++"**. This installs the required compiler. Then try `pip install` again.

---

**Problem:** Whisper model download fails or times out

**Solution:** Check your internet connection. The base model is ~140MB. Wait and try again. If it keeps failing, you can manually trigger the download:
```bash
python -c "import whisper; whisper.load_model('base')"
```
The model is cached at `C:\Users\YourName\.cache\whisper\` after the first download.

---

**Problem:** `ffmpeg not found` or `ffmpeg is not recognized` error during audio processing

**Solution:** ffmpeg is not in your Windows PATH. To verify, open a **new** terminal (must be new — PATH changes only apply to new terminals) and type:
```bash
ffmpeg -version
```
If it says "not recognized", you need to add `C:\ffmpeg\bin` to your PATH. Redo Step 6 of the setup guide. Make sure you open a brand new terminal after making the PATH change.

---

**Problem:** `pydantic_settings` import error when starting the AI service

**Solution:** `pydantic-settings` is a separate package from `pydantic` in newer versions. Install it:
```bash
pip install pydantic-settings
```

---

**Problem:** ChromaDB version error or import failure

**Solution:** The ChromaDB API changed in versions after 0.5.0. If you upgraded ChromaDB, the import path for Settings may be different. Check the installed version:
```bash
pip show chromadb
```
If it is a version higher than 0.5.0, open `ai_service/core/vector_store.py` and update the import according to the ChromaDB changelog for your version.

---

**Problem:** `declarative_base` deprecation warning from SQLAlchemy

**Solution:** This is a warning, not an error — the code still works. In SQLAlchemy 2.0+, `declarative_base` was moved. The warning can be silenced by updating the import in `ai_service/models/database.py`:
```python
# Change this:
from sqlalchemy.ext.declarative import declarative_base

# To this:
from sqlalchemy.orm import DeclarativeBase
class Base(DeclarativeBase):
    pass
```

---

## Moodle Plugin

**Problem:** Plugin not detected when visiting `http://localhost/admin`

**Solution:** Check the following:
1. The folder is in the right place: `moodle/local/umat_ai/` (not nested as `moodle/local/umat_ai/umat_ai/`)
2. `version.php` exists in that folder with correct content
3. Enable Moodle developer debugging (Site Admin → Development → Debugging → set to DEVELOPER) and refresh — error messages will appear on screen

---

**Problem:** Plugin installs but scheduled tasks don't appear in Site Admin → Server → Scheduled tasks

**Solution:** There is likely a PHP syntax error in `db/tasks.php`. Enable Moodle debugging and visit `/admin` again — the error will be displayed. Check for missing semicolons, incorrect class names, or wrong namespace format.

---

**Problem:** AJAX call from chat panel returns 403 Forbidden

**Solution:** The Moodle capability is not assigned to the role. Go to: Site Admin → Users → Permissions → Define roles → click the **Student** role → find `local/umat_ai:chatwithai` → set to **Allow** → Save.

---

**Problem:** AJAX call returns "Web service is not available" error

**Solution:** Web services may not be enabled globally. Go to: Site Admin → Advanced Features → check **Enable web services** → Save. Then go to Site Admin → Plugins → Web services → External services and verify the UMaT AI Service is enabled.

---

## Git

**Problem:** `git push` asks for username and password every time

**Solution:** Set up a GitHub Personal Access Token (PAT). 

Go to GitHub → click your profile picture → **Settings** → **Developer settings** → **Personal access tokens** → **Tokens (classic)** → **Generate new token**.

Check the `repo` scope. Copy the token — you will only see it once.

Use the token as your password when Git prompts you. Then set up credential storage so Git remembers it:
```bash
git config --global credential.helper store
```

The next time you push, enter your GitHub username and the token as the password. Git will remember it from then on.

---

**Problem:** "Please tell me who you are" error on first commit

**Solution:**
```bash
git config --global user.name "Your Full Name"
git config --global user.email "your.github.email@example.com"
```

---

**Problem:** `git push` is rejected with "Updates were rejected because the remote contains work that you do not have locally"

**Solution:** A teammate pushed changes after you last pulled. Pull first, then push:
```bash
git pull origin main
git push origin main
```

If `git pull` shows conflicts, see the conflict resolution section in [CONTRIBUTING.md](../CONTRIBUTING.md).

---

**Problem:** I accidentally committed my `.env` file with real API keys

**Solution:** Act immediately:
1. Delete the `.env` file from the repository and add a `.gitignore` entry for it
2. Commit and push the removal
3. Immediately go to your OpenAI account and **regenerate your API key** — the old one is compromised and should be treated as public
4. Update your local `.env` with the new key
5. Tell Seidu (project lead) so other team members can update their `.env` files too

Note: Even after removing the file from Git, it remains in the commit history. Anyone with access to the repository history can still see it. This is why rotating the key immediately is critical.

---

## Moodle General

**Problem:** Moodle is very slow or pages time out

**Solution:** Check these settings in `php.ini`:
```ini
max_execution_time = 360
memory_limit = 512M
```
Also run the Moodle cron manually to clear any stuck tasks:
```bash
C:\xampp\php\php.exe C:\Projects\umat-vle-enhanced\moodle\admin\cli\cron.php
```

---

**Problem:** Moodle shows a blank white page

**Solution:** There is a PHP fatal error. Enable debugging:
1. Open `C:\Projects\umat-vle-enhanced\moodle\config.php` in VS Code
2. Add this line near the bottom, before the `?>`: `@error_reporting(E_ALL); @ini_set('display_errors', '1');`
3. Reload the page — the error will now appear
4. Fix the error, then remove those lines from config.php

---

*Last updated by: [add your name and date when you update this file]*