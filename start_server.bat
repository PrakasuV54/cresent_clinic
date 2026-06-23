@echo off
echo ===================================================
echo Starting Hospital Portal Local Server
echo ===================================================
echo.
echo The server will start on: http://localhost:8005
echo.
echo Please leave this window open while using the portal.
echo To stop the server, press Ctrl + C.
echo.
"C:\xampp\php\php.exe" -S 0.0.0.0:8005 router.php
pause
