# Complete Local Development Setup Guide

This guide walks you through setting up the UMaT VLE Enhanced project on your Windows machine from scratch. Follow every step in order. Do not skip steps.

**Estimated time: 2 to 3 hours for a fresh machine.**

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

## Step 1: Install XAMPP

1. Run the XAMPP installer you downloaded
2. When asked which components to install, keep the defaults — you only **need Apache and PHP**. You can uncheck MySQL/MariaDB since you are using PostgreSQL
3. Install to `C:\xampp` (the default)
4. When installation finishes, open **XAMPP Control Panel**
5. Click **Start** next to Apache — the row should turn green
6. Open your browser and go to `http://localhost` — you should see the XAMPP welcome page

**Configure Apache to serve your project directory:**

Open `C:\xampp\apache\conf\httpd.conf` in VS Code (right-click the file → Open with VS Code, or open VS Code as administrator and open the file manually).

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

A few lines below that, find `AllowOverride None` inside that same `<Directory>` block and change it to:
```apache
AllowOverride All
```

Save the file. In XAMPP Control Panel, click **Stop** then **Start** on Apache to restart it.

**Configure PHP for Moodle:**

Open `C:\xampp\php\php.ini` in VS Code.

Find and uncomment these lines (remove the `;` at the start of each):
```ini
extension=pdo_pgsql
extension=pgsql
```

Find and change these values:
```ini
max_execution_time = 360
max_input_vars = 5000
memory_limit = 512M
post_max_size = 500M
upload_max_filesize = 500M
date.timezone = Africa/Accra
```

Save the file and restart Apache in XAMPP Control Panel.

---

## Step 2: Install PostgreSQL

1. Run the PostgreSQL installer
2. Keep the default installation directory
3. When asked for a password for the `postgres` superuser — **write this password down, you will need it**
4. Keep the default port: `5432`
5. Keep the default locale
6. Finish installation — pgAdmin 4 is installed automatically

**Create the project databases:**

Open **pgAdmin 4** from your Start menu. It opens in your browser. Set a master password when prompted.

In the left panel, right-click on **PostgreSQL 15** → **Query Tool**. Paste and run this:

```sql
-- Create Moodle database user
CREATE USER moodleuser WITH PASSWORD 'MoodlePass2024!';

-- Create Moodle database
CREATE DATABASE moodledb
    OWNER moodleuser
    ENCODING 'UTF8'
    LC_COLLATE 'en_US.UTF-8'
    LC_CTYPE 'en_US.UTF-8'
    TEMPLATE template0;

GRANT ALL PRIVILEGES ON DATABASE moodledb TO moodleuser;
```

Click the **Run** button (the play icon). You should see "Query returned successfully."

Now switch the database dropdown at the top from `postgres` to `moodledb`, then run:
```sql
GRANT ALL ON SCHEMA public TO moodleuser;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO moodleuser;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO moodleuser;
```

Switch back to the `postgres` database and run:
```sql
-- Create AI service database user
CREATE USER aiserviceuser WITH PASSWORD 'AIServicePass2024!';

-- Create AI service database
CREATE DATABASE umat_ai_db
    OWNER aiserviceuser
    ENCODING 'UTF8';

GRANT ALL PRIVILEGES ON DATABASE umat_ai_db TO aiserviceuser;
```

Switch to `umat_ai_db` and run:
```sql
GRANT ALL ON SCHEMA public TO aiserviceuser;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO aiserviceuser;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO aiserviceuser;
```

**Verify:** In pgAdmin left panel, refresh Databases. You should see both `moodledb` and `umat_ai_db`.

---

## Step 3: Install Python 3.11

1. Run the Python 3.11 installer
2. On the first screen, **check the box "Add Python 3.11 to PATH"** — this is critical
3. Click "Install Now"
4. When done, open a new Command Prompt and verify:

```bash
python --version
# Should print: Python 3.11.x

pip --version
# Should print: pip 24.x.x from ... (python 3.11)
```

Install virtualenv:
```bash
pip install virtualenv
```

---

## Step 4: Install Git and Clone the Repository

1. Run the Git installer, keep all defaults
2. Open Command Prompt and verify:
```bash
git --version
```

3. Configure your identity (use your real name and your GitHub email):
```bash
git config --global user.name "Your Full Name"
git config --global user.email "your.email@example.com"
```

4. Clone the repository:
```bash
mkdir C:\Projects
cd C:\Projects
git clone https://github.com/seidugit/umat-vle-enhanced.git
cd umat-vle-enhanced
```

You now have the project at `C:\Projects\umat-vle-enhanced\`.

---

## Step 5: Install Moodle

**Download Moodle 4.3 LTS:**

Go to [download.moodle.org](https://download.moodle.org/) and download Moodle 4.3.x as a ZIP file.

Extract the ZIP. Inside you will find a folder called `moodle`. Copy everything **inside** that folder into:
```
C:\Projects\umat-vle-enhanced\moodle\
```

Your moodle folder should now contain files like `index.php`, `admin`, `auth`, `blocks`, etc. at the top level.

**Create the Moodle data directory:**
```bash
mkdir C:\MoodleData
```

**Verify PHP can connect to PostgreSQL:**

Create `C:\Projects\umat-vle-enhanced\moodle\phptest.php` with this content:
```php
<?php phpinfo(); ?>
```

Visit `http://localhost/phptest.php` in your browser. Search for `pgsql` on that page — it must show as enabled. Delete the file when done.

**Run the Moodle installer:**

Visit `http://localhost` in your browser. The Moodle installer starts automatically.

Fill in each screen:

| Screen | Field | Value |
|--------|-------|-------|
| Web address | URL | `http://localhost` |
| Directories | Moodle directory | `C:\Projects\umat-vle-enhanced\moodle` |
| Directories | Data directory | `C:\MoodleData` |
| Database | Type | **PostgreSQL** |
| Database | Host | `localhost` |
| Database | Database name | `moodledb` |
| Database | User | `moodleuser` |
| Database | Password | `MoodlePass2024!` |
| Database | Port | `5432` |
| Database | Table prefix | `mdl_` |

Click through all confirmation screens. Moodle creates all its tables in PostgreSQL automatically — this takes 2–5 minutes.

When it asks for a site name, use: `UMaT Virtual Learning Environment`

Create the admin account using a real email you can access.

**Set up the Moodle cron job:**

Open Windows Task Scheduler (search for it in Start menu). Create a new Basic Task:
- Name: `Moodle Cron`
- Trigger: Daily, repeat every 1 minute, for a duration of 1 day (indefinitely)
- Action: Start a program
- Program: `C:\xampp\php\php.exe`
- Arguments: `C:\Projects\umat-vle-enhanced\moodle\admin\cli\cron.php`

Or run it manually in a terminal whenever you need background tasks to run:
```bash
C:\xampp\php\php.exe C:\Projects\umat-vle-enhanced\moodle\admin\cli\cron.php
```

**Enable developer mode:**

Log in to Moodle as admin. Go to Site Administration → Development → Debugging. Set `Debug messages` to `DEVELOPER`. Enable `Display debug messages`. Save.

---

## Step 6: Install ffmpeg

ffmpeg is required for extracting audio from video recordings.

1. Go to [ffmpeg.org/download.html](https://ffmpeg.org/download.html)
2. Under Windows, click the link for **Windows builds by BtbN** or **gyan.dev**
3. Download the `ffmpeg-release-essentials.zip` file
4. Extract it — you will get a folder like `ffmpeg-6.x-essentials_build`
5. Rename that folder to just `ffmpeg`
6. Move it to `C:\ffmpeg`
7. Your ffmpeg binary should now be at `C:\ffmpeg\bin\ffmpeg.exe`

**Add ffmpeg to PATH:**
1. Open Start menu, search for `Environment Variables`
2. Click `Edit the system environment variables`
3. Click `Environment Variables` button
4. Under `System variables`, find `Path`, click `Edit`
5. Click `New` and type `C:\ffmpeg\bin`
6. Click OK on all dialogs

Open a **new** Command Prompt and verify:
```bash
ffmpeg -version
```
Should print ffmpeg version information, not an error.

---

## Step 7: Install Node.js

1. Download and run the Node.js 20 LTS installer from [nodejs.org](https://nodejs.org/)
2. Keep all defaults
3. Verify in a new Command Prompt:
```bash
node --version
npm --version
```

Install grunt globally (needed for building Moodle JavaScript):
```bash
npm install -g grunt-cli
```

---

## Step 8: Set Up the Python AI Service

```bash
cd C:\Projects\umat-vle-enhanced\ai_service
python -m virtualenv venv
venv\Scripts\activate
```

Your terminal prompt should now show `(venv)` at the start.

```bash
pip install -r requirements.txt
pip install openai-whisper
```

This takes several minutes as it downloads all dependencies including PyTorch (required by Whisper).

**Create your .env file:**
```bash
copy .env.example .env
```

Open `.env` in VS Code and fill in the values. Get the actual secret values from Seidu via the WhatsApp group:
```
AI_SERVICE_TOKEN=get-this-from-seidu
OPENAI_API_KEY=get-this-from-seidu
AI_DB_PASSWORD=AIServicePass2024!
```

**Pre-download the Whisper model** (saves time on first run):
```bash
python -c "import whisper; whisper.load_model('base')"
```

This downloads about 140MB. Wait for it to complete.

**Test the AI service starts:**
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

Open your browser and go to `http://localhost:8000/docs` — you should see the Swagger UI.

Press `Ctrl+C` to stop the service.

---

## Step 9: Install Moodle Plugins

**Install BigBlueButton plugin:**

1. Go to [moodle.org/plugins/mod_bigbluebuttonbn](https://moodle.org/plugins/mod_bigbluebuttonbn)
2. Download the version for Moodle 4.3
3. Extract the zip — you get a folder called `bigbluebuttonbn`
4. Copy that folder to `C:\Projects\umat-vle-enhanced\moodle\mod\bigbluebuttonbn\`
5. Visit `http://localhost/admin` in your browser
6. Moodle detects the new plugin — click `Upgrade Moodle database now`
7. Configure the plugin: Site Admin → Plugins → Activity modules → BigBlueButton
8. Get the BBB server URL and secret from Seidu

**Install the UMaT AI plugin:**

The plugin files come from the repository. They should already be at:
```
C:\Projects\umat-vle-enhanced\moodle\local\umat_ai\
```

Visit `http://localhost/admin`. Moodle detects the plugin and asks to install it. Click `Upgrade Moodle database now`.

Go to Site Admin → Plugins → Local plugins → UMaT AI Academic Support. Enter:
- AI Service URL: `http://localhost:8000`
- AI Service Token: (get from Seidu)
- OpenAI API Key: (get from Seidu)

**Apply the UMaT theme:**

The theme files are at:
```
C:\Projects\umat-vle-enhanced\moodle\theme\umat\
```

Go to Site Admin → Appearance → Themes → Theme selector. Find `umat` and click `Use theme`.

---

## Step 10: Verify Everything Works

Go through this checklist:

```
[ ] http://localhost loads the Moodle login page
[ ] You can log in to Moodle with the admin account
[ ] http://localhost:8000/docs loads the AI service Swagger UI
[ ] pgAdmin shows both moodledb and umat_ai_db with tables
[ ] Moodle admin shows UMaT AI plugin installed (Site Admin > Plugins > Local plugins)
[ ] Moodle admin shows BigBlueButton plugin installed
[ ] UMaT theme is active (UMaT navy blue colours visible)
[ ] Running cron manually produces no fatal errors
```

If anything fails, check [troubleshooting.md](troubleshooting.md) or message the group chat.

---

## Daily Development Startup

Every time you sit down to work:

**Terminal 1 — Start AI Service:**
```bash
cd C:\Projects\umat-vle-enhanced\ai_service
venv\Scripts\activate
python main.py
```
Leave this terminal running.

**XAMPP:**
Open XAMPP Control Panel and Start Apache. Leave it running.

**PostgreSQL:**
Runs as a Windows service automatically. Verify in Services (Windows + R → `services.msc` → look for `postgresql-x64-15` → Status: Running).

**Start coding:**
```bash
cd C:\Projects\umat-vle-enhanced
code .
```

---

## Updating from GitHub

When a teammate pushes something new and you want their changes:

```bash
git pull origin main
```

If database tables were added (check if `moodle/local/umat_ai/db/install.xml` or `db/upgrade.php` changed), visit `http://localhost/admin` — Moodle detects the change and runs the upgrade automatically.

If `ai_service/requirements.txt` changed:
```bash
cd ai_service
venv\Scripts\activate
pip install -r requirements.txt
```