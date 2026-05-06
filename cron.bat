@echo off
:loop
C:\xampp\php\php.exe "C:\Users\amkch\Documents\Projects\umat-vle-enhanced\moodle\admin\cli\cron.php"
@REM timeout /t 60 /nobreak >nul
goto loop