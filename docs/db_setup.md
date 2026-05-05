
moodle db creation
```sql
-- Create dedicated database user for Moodle
CREATE USER moodleuser WITH PASSWORD 'StrongPasswordHere2024';

-- Create the Moodle database
CREATE DATABASE moodledb 
    OWNER moodleuser 
    ENCODING 'UTF8' 
    LC_COLLATE 'en_US.UTF-8' 
    LC_CTYPE 'en_US.UTF-8' 
    TEMPLATE template0;

-- Grant all privileges
GRANT ALL PRIVILEGES ON DATABASE moodledb TO moodleuser;

-- Connect to moodledb and grant schema privileges
\c moodledb
GRANT ALL ON SCHEMA public TO moodleuser;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO moodleuser;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO moodleuser;
```

ai_service db creation
```sql
CREATE USER aiserviceuser WITH PASSWORD 'AnotherStrongPassword2024';
CREATE DATABASE umat_ai_db OWNER aiserviceuser ENCODING 'UTF8';
GRANT ALL PRIVILEGES ON DATABASE umat_ai_db TO aiserviceuser;
\c umat_ai_db
GRANT ALL ON SCHEMA public TO aiserviceuser;
```