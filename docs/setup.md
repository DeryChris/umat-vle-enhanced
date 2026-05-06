# Complete Local Development Setup Guide

This guide walks you through setting up the UMaT VLE Enhanced project on your Windows machine from scratch. Follow every step in order. Do not skip steps.

**Estimated time: 2 to 3 hours for a fresh machine.**

> **API keys, tokens, and passwords** — contact Chrispen ([@derychris](https://github.com/derychris)) via the WhatsApp group.

---

## Required Software

Download all of these before starting. Use the exact versions specified.

| Software | Version | Download Link | Notes |
|----------|---------|---------------|-------|
| XAMPP | 8.2.x | [apachefriends.org/download.html](https://www.apachefriends.org/download.html) | Choose the PHP 8.2 version |
| PostgreSQL | 15 or 16 | [postgresql.org/download/windows](https://www.postgresql.org/download/windows/) | Includes pgAdmin 4 |
| Python | 3.11 (exactly) | [python.org/downloads](https://www.python.org/downloads/) | Not 3.12 or 3.10 — must be 3.11 |
| Git | Latest | [git-scm.com/download/win](https://git-scm.com/download/win) | Use all default options during install |
| Node.js | 20 LTS | [nodejs.org](https://nodejs.org/) | For building Moodle JavaScript |
| ffmpeg | Latest | [ffmpeg.org/download.html](https://ffmpeg.org/download.html) | Windows build — see Step 6 |
| VS Code | Latest | [code.visualstudio.com](https://code.visualstudio.com/) | Recommended code editor |
| Postman | Latest | [postman.com/downloads](https://www.postman.com/downloads/) | For API testing |

---

## Step 1: Install Git and Clone the Repository

1. Run the Git installer, keep all defaults
2. Open Command Prompt and verify:
```bash
git --version
```

3. Configure your identity (use your real name and GitHub email):
```bash
git config --global user.name "Your Full Name"
git config --global user.email "your.email@example.com"
```

4. Clone the repository:
```bash
mkdir C:\Projects
cd C:\Projects
git clone https://github.com/derychris/umat-vle-enhanced.git
cd umat-vle-enhanced
```

You now have the project at `C:\Projects\umat-vle-enhanced\`.

---


## Step 2: Install XAMPP

1. Run the XAMPP installer
2. When asked which components to install, keep the defaults — you only **need Apache and PHP**. You can uncheck MySQL/MariaDB since you are using PostgreSQL
3. Install to `C:\xampp` (the default)
4. When installation finishes, open **XAMPP Control Panel**
5. Click **Start** next to Apache — the row should turn green
6. Open your browser and go to `http://localhost` — you should see the XAMPP welcome page

### Configure Apache to Serve the Project

Open `C:\xampp\apache\conf\httpd.conf` in VS Code **as Administrator** (right-click VS Code in Start → Run as administrator, then open the file).

Find this block (around line 250):
```apache
DocumentRoot "C:/xampp/htdocs"
<Directory "C:/xampp/htdocs">
```

Replace both lines with:
```apache
DocumentRoot "C:/Projects/umat-vle-enhanced/moodle"
<Directory "C:/Projects/umat-vle-enhanced/moodle">
```

A few lines below, inside that same `<Directory>` block, find `AllowOverride None` and change it to:
```apache
AllowOverride All
```

Also find and uncomment this line (remove the `#`):
```apache
#LoadModule rewrite_module modules/mod_rewrite.so
```

Save the file. In XAMPP Control Panel, click **Stop** then **Start** on Apache.

---

## Step 3: Configure PHP for Moodle

**Do this before running the Moodle installer.** Moodle checks every one of these settings during installation and will block you if they are wrong. Fix them all now.

Open `C:\xampp\php\php.ini` in VS Code (as Administrator).

### Enable PostgreSQL Extensions

Find and **uncomment** these lines (remove the `;` at the very start):
```ini
extension=pdo_pgsql
extension=pgsql
```

### Enable Other Required Extensions

Find and uncomment all of these (use Ctrl+F to search each one):
```ini
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=soap
extension=zip
```

### Set Required PHP Values

Find each setting and update the value:
```ini
max_execution_time = 360
max_input_time = 360
max_input_vars = 5000
memory_limit = 512M
post_max_size = 500M
upload_max_filesize = 500M
file_uploads = On
date.timezone = Africa/Accra
```

Save the file and **fully restart Apache** in XAMPP (Stop, then Start).

### Verify Your PHP Settings Loaded

Create `C:\Projects\umat-vle-enhanced\moodle\phpinfo.php`:
```php
<?php phpinfo();
```

Visit `http://localhost/phpinfo.php`. Press Ctrl+F and confirm each of these:

| Search for | Must show |
|------------|-----------|
| `pgsql` | enabled |
| `pdo_pgsql` | enabled |
| `curl` | enabled |
| `gd` | enabled |
| `intl` | enabled |
| `mbstring` | enabled |
| `zip` | enabled |
| `max_execution_time` | 360 |
| `max_input_vars` | 5000 |
| `memory_limit` | 512M |
| `upload_max_filesize` | 500M |
| `date.timezone` | Africa/Accra |

If anything is wrong, fix it in `php.ini` and restart Apache again. **Do not proceed until all are correct.**

**Delete the phpinfo file when done:**
```
Delete: C:\Projects\umat-vle-enhanced\moodle\phpinfo.php
```

---

## Step 4: Install PostgreSQL

1. Run the PostgreSQL installer
2. Keep the default installation directory
3. When asked for a password for the `postgres` superuser — **write this down, you will need it**
4. Keep the default port: `5432`
5. Keep the default locale
6. Finish installation — pgAdmin 4 is installed automatically

### Create the Project Databases

Open **pgAdmin 4** from your Start menu. It opens in the browser. Set a master password when prompted.

In the left panel, right-click on **postgres** → **Query Tool**.

Paste and run (F5) this entire block:

```sql
-- Moodle database
CREATE DATABASE moodle
    OWNER postgres
    ENCODING 'UTF8'
    LC_COLLATE 'en_US.UTF-8'
    LC_CTYPE 'en_US.UTF-8'
    TEMPLATE template0;

GRANT ALL PRIVILEGES ON DATABASE moodle TO postgres;

-- AI service database
CREATE DATABASE umat_ai_db
    OWNER postgres
    ENCODING 'UTF8';

GRANT ALL PRIVILEGES ON DATABASE umat_ai_db TO postgres;
```

Click **Run** (F5). You should see "Query returned successfully."

Switch the database dropdown to `moodle`, then run (F5):
```sql
GRANT ALL ON SCHEMA public TO postgres;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO postgres;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO postgres;
```

Switch to `umat_ai_db`, right-click on `umat_ai_db` → **Query Tool**, then run (F5):
```sql
GRANT ALL ON SCHEMA public TO postgres;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO postgres;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO postgres;
```

**Verify:** Refresh Databases in the left panel — you should see both `moodle` and `umat_ai_db`.

---


## Step 5: Install Moodle

**Download Moodle 5.1.3x:**

Go to [download.moodle.org](https://download.moodle.org/) and download Moodle 5.1.x as a ZIP file.

Extract the ZIP. Inside is a folder called `moodle`. Copy everything **inside** that folder into:
```
C:\Projects\umat-vle-enhanced\moodle\
```

Your moodle folder should now contain files like `index.php`, `admin`, `auth`, `blocks`, etc. at the top level.

**Create the Moodle data directory:**
```bash
mkdir C:\MoodleData
```

Right-click `C:\MoodleData` → **Properties** → **Security** → **Edit** → select **Users** → check **Full control** → Apply.


Open `moodle/config.php` in VS Code and verify the database block matches your setup:
```php
$CFG->dbtype    = 'pgsql';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'postgres';
$CFG->dbpass    = 'your-postgres-password';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array('dbport' => '5432');
```

---

## Step 6: Run the Moodle Web Installer

Visit `http://localhost` in your browser. The Moodle installer starts automatically.

### Screen 1: Choose Language
Select **English (en)**. Click **Next**.

### Screen 2: Confirm Paths

| Field | Value |
|-------|-------|
| Web address | `http://localhost` |
| Moodle directory | `C:\Projects\umat-vle-enhanced\moodle` |
| Data directory | `C:\MoodleData` |

Click **Next**.

### Screen 3: Choose Database Driver
Select **PostgreSQL (native/pgsql)**. Click **Next**.

### Screen 4: Database Settings

| Field | Value |
|-------|-------|
| Database host | `localhost` |
| Database name | `moodle` |
| Database user | `postgres` |
| Database password | your postgres superuser password |
| Tables prefix | `mdl_` |
| Database port | `5432` |

Click **Next**. If you see "connection refused", see the troubleshooting guide.

### Screen 5: Copyright Notice
Read and accept the GPL licence. Click **Continue**.

---

## Step 7: The Server Environment Checks Page

This is the most important screen. Moodle scans your PHP environment and displays every requirement as:

- ✅ **OK** — requirement met
- ⚠️ **Check** (yellow) — recommended, Moodle will still install
- ❌ **Error** (red) — must be fixed before installation can continue

**If you completed Step 2 correctly, you should have no red errors.** The table below explains what each check means so you know what to do if something is wrong.

### PHP Extensions Moodle Checks

| Extension | Required? | What It Is Used For |
|-----------|-----------|---------------------|
| `iconv` | Required | Character encoding conversion |
| `mbstring` | Required | Multi-byte string handling |
| `curl` | Required | HTTP requests to BBB and AI service |
| `openssl` | Required | HTTPS/SSL connections |
| `tokenizer` | Required | PHP code analysis |
| `ctype` | Required | Character type checking |
| `zip` | Required | Handling ZIP files |
| `gd` | Required | Image processing |
| `simplexml` | Required | XML parsing |
| `spl` | Required | Standard PHP Library |
| `pcre` | Required | Regular expressions |
| `dom` | Required | DOM manipulation |
| `xml` | Required | XML support |
| `json` | Required | JSON encoding/decoding |
| `pgsql` | Required | PostgreSQL driver |
| `pdo_pgsql` | Required | PDO PostgreSQL driver |
| `intl` | Recommended | Internationalisation |
| `soap` | Recommended | Web services |
| `xmlrpc` | Recommended | Remote procedure calls |

If any **Required** extension shows ❌ — go back to `php.ini`, uncomment the extension, restart Apache, and return to this page.

### PHP Settings Moodle Checks

| Setting | Moodle Requires | What We Set |
|---------|----------------|-------------|
| `memory_limit` | min 96M | 512M ✅ |
| `max_execution_time` | min 60 | 360 ✅ |
| `max_input_vars` | min 5000 | 5000 ✅ |
| `file_uploads` | On | On ✅ |
| `post_max_size` | ≥ upload_max_filesize | 500M ✅ |
| `upload_max_filesize` | no minimum | 500M ✅ |
| `session.save_handler` | files | XAMPP default ✅ |

### Database Checks

| Check | Requirement |
|-------|------------|
| PostgreSQL version | 12+ required (15/16 will pass) |
| Database encoding | Must be UTF8 (we set this in Step 3) |
| User permissions | `postgres` must have full access to `moodle` db (granted in Step 3) |

### Directory Checks

| Check | What It Verifies |
|-------|-----------------|
| Data directory exists | `C:\MoodleData` must exist and be writable |
| Data directory not in web root | Must not be inside the `moodle/` folder |
| `.htaccess` support | mod_rewrite enabled and AllowOverride All set |

**If data directory shows as not writable:** Right-click `C:\MoodleData` → Properties → Security → Edit → select Users → check Full control → Apply.

### When All Checks Pass

Click **Continue**. Moodle creates all its database tables — this takes 3–8 minutes. Do not close the browser. When done you will see: **"Your installation was successful."**

---

## Step 8: Complete Site Setup

Fill in the admin account details and site settings:

| Field | Value |
|-------|-------|
| Full site name | `University of Mines and Technology VLE` |
| Short name | `UMaT VLE` |
| Country | `Ghana` |
| Timezone | `Africa/Accra` |

After saving you are logged in as admin.

### Enable Developer Mode

Go to **Site Administration → Development → Debugging**:
- Debug messages: **DEVELOPER**
- Display debug messages: **Yes**
- Save changes

### Enable Web Services

Go to **Site Administration → Advanced Features** → check **Enable web services** → Save.

### Set Up the Cron Job

**Double-click** `umat-vle-enhanced\cron.bat` to run it. Press `Ctrl+C` to stop it.

This file runs `moodle/admin/cli/cron.php` continuously and is required for scheduled tasks (recording processing, material indexing, etc.).

---

## Step 9: Install Moodle Plugins

### BigBlueButton Plugin

1. Go to [moodle.org/plugins/mod_bigbluebuttonbn](https://moodle.org/plugins/mod_bigbluebuttonbn)
2. Download the version for Moodle 5.x
3. Extract the zip — you get a folder called `bigbluebuttonbn`
4. Copy it to `C:\Projects\umat-vle-enhanced\moodle\mod\bigbluebuttonbn\`
5. Visit `http://localhost/admin` — Moodle detects the plugin, click **Upgrade Moodle database now**
6. Go to Site Admin → Plugins → Activity modules → BigBlueButton
7. Get the BBB server URL and secret from **Chrispen**

### UMaT AI Plugin

The plugin already lives in the repository at:
```
C:\Projects\umat-vle-enhanced\moodle\public\local\umat_ai\
```

Moodle 5.x scans `moodle/public/local/` automatically. Visit `http://localhost/admin` — Moodle detects the plugin and asks to install it. Click **Upgrade Moodle database now**.

Go to Site Admin → Plugins → Local plugins → UMaT AI Academic Support. Enter:
- AI Service URL: `http://localhost:8000`
- AI Service Token: (get from **Chrispen**)
- Gemini API Key: (get from **Chrispen**)

### UMaT Theme

The theme lives at:
```
C:\Projects\umat-vle-enhanced\moodle\public\theme\umat\
```

Moodle 5.x scans `moodle/public/theme/` automatically. Go to Site Admin → Appearance → Themes → Theme selector. Find `umat` and click **Use theme**.

---

## Step 10: Install ffmpeg

1. Go to [ffmpeg.org/download.html](https://ffmpeg.org/download.html) → Windows builds by BtbN or gyan.dev
2. Download `ffmpeg-release-essentials.zip`
3. Extract and rename the folder to `ffmpeg`
4. Move to `C:\ffmpeg` — binary should be at `C:\ffmpeg\bin\ffmpeg.exe`

**Add to PATH:**
1. Start → search `Environment Variables` → **Edit the system environment variables**
2. **Environment Variables** → under System variables, find **Path** → **Edit**
3. **New** → type `C:\ffmpeg\bin` → OK on all dialogs

Open a **new** terminal and verify:
```bash
ffmpeg -version
```

---

## Step 11: Install Node.js

1. Download and run the Node.js 20 LTS installer from [nodejs.org](https://nodejs.org/)
2. Keep all defaults
3. Verify:
```bash
node --version
npm --version
```

Install grunt globally:
```bash
npm install -g grunt-cli
```

---

## Step 12: Set Up the Python AI Service

```bash
cd C:\Projects\umat-vle-enhanced\ai_service
python -m virtualenv venv
venv\Scripts\activate
pip install -r requirements.txt
pip install openai-whisper
```

**Create your .env file:**
```bash
copy .env.example .env
```

Open `.env` in VS Code and fill in — get all values from **Chrispen** via WhatsApp:
```
AI_SERVICE_TOKEN=get-from-chrispen
GEMINI_API_KEY=get-from-chrispen
AI_DB_PASSWORD=your-postgres-password
```

**Pre-download the Whisper model** (~140MB, only needed once):
```bash
python -c "import whisper; whisper.load_model('base')"
```

**Test the service starts:**
```bash
python main.py
```

You should see:
```
INFO: Loading Whisper model: base
INFO: Whisper model loaded.
INFO: UMaT AI Service ready on port 8000
INFO: Uvicorn running on http://0.0.0.0:8000
```

Visit `http://localhost:8000/docs` — you should see the Swagger UI. Press `Ctrl+C` to stop.

---

## Step 13: Final Verification Checklist

```
[ ] http://localhost loads the Moodle login page with UMaT navy/gold colours
[ ] You can log in to Moodle with the admin account
[ ] http://localhost:8000/docs loads the AI service Swagger UI
[ ] pgAdmin shows both moodle and umat_ai_db with tables inside
[ ] Moodle admin shows UMaT AI plugin installed (Site Admin > Plugins > Local plugins)
[ ] Moodle admin shows BigBlueButton plugin installed
[ ] UMaT theme is active
[ ] cron.bat runs without fatal errors
[ ] ffmpeg -version works in a new terminal
[ ] Moodle developer debug mode is on
```

If anything fails, check [troubleshooting.md](troubleshooting.md) or message the group chat.

---

## Daily Development Startup

**1. XAMPP:** Open XAMPP Control Panel → Start Apache

**2. PostgreSQL:** Runs as a Windows service automatically. Verify: Windows + R → `services.msc` → `postgresql-x64-15` → Status: Running

**3. AI Service:**
```bash
cd C:\Projects\umat-vle-enhanced\ai_service
venv\Scripts\activate
python main.py
```

**4. Cron:** Double-click `umat-vle-enhanced\cron.bat`

**5. Pull latest code:**
```bash
cd C:\Projects\umat-vle-enhanced
git pull origin main
```

**6. Open your editor:**
```bash
code C:\Projects\umat-vle-enhanced
```

---

## Updating from GitHub

```bash
git pull origin main
```

If `moodle/public/local/umat_ai/db/install.xml` or `db/upgrade.php` changed, visit `http://localhost/admin` — Moodle detects the change and runs the upgrade automatically.

If `ai_service/requirements.txt` changed:
```bash
cd ai_service
venv\Scripts\activate
pip install -r requirements.txt
```