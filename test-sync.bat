@echo off
REM ============================================
REM Quick Test Script
REM ============================================

echo ============================================
echo Testing Two-Way Synchronization
echo ============================================
echo.

echo [Test 1] Legacy -^> Revamp Sync
echo ----------------------------------------
echo Inserting record in PostgreSQL...

docker exec -it sync-postgres psql -U postgres -d legacy_db -c "INSERT INTO legacy_users (username, email, full_name, phone_number, source) VALUES ('testuser_bat', 'testbat@example.com', 'Test User BAT', '+1234567890', 'legacy');"

echo.
echo Waiting 5 seconds for sync...
timeout /t 5 /nobreak

echo.
echo Checking MySQL for synced record...
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "SELECT id, user_name, email_address, display_name, source FROM revamp_users WHERE user_name = 'testuser_bat';"

echo.
echo.
echo [Test 2] Revamp -^> Legacy Sync
echo ----------------------------------------
echo Inserting record in MySQL...

docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "INSERT INTO revamp_users (user_name, email_address, display_name, mobile, source) VALUES ('testuser_bat2', 'testbat2@example.com', 'Test User BAT 2', '+9876543210', 'revamp');"

echo.
echo Waiting 5 seconds for sync...
timeout /t 5 /nobreak

echo.
echo Checking PostgreSQL for synced record...
docker exec -it sync-postgres psql -U postgres -d legacy_db -c "SELECT id, username, email, full_name, source FROM legacy_users WHERE username = 'testuser_bat2';"

echo.
echo.
echo [Test 3] Loop Prevention Test
echo ----------------------------------------
echo Inserting record with source='sync_service'...

docker exec -it sync-postgres psql -U postgres -d legacy_db -c "INSERT INTO legacy_users (username, email, full_name, source) VALUES ('looptest_bat', 'loopbat@test.com', 'Loop Test BAT', 'sync_service');"

echo.
echo Waiting 5 seconds...
timeout /t 5 /nobreak

echo.
echo Checking if it appeared in MySQL (should be 0)...
docker exec -it sync-mysql mysql -uroot -proot revamp_db -e "SELECT COUNT(*) as count FROM revamp_users WHERE user_name = 'looptest_bat';"

echo.
echo ============================================
echo Test Complete!
echo ============================================
echo.
echo Expected Results:
echo - Test 1: Record should appear in MySQL with source='sync_service'
echo - Test 2: Record should appear in PostgreSQL with source='sync_service'
echo - Test 3: Count should be 0 (loop prevented)
echo.
pause

