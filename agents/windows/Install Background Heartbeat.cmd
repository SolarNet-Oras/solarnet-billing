@echo off
setlocal
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install-SolarNetHeartbeat.ps1"
if errorlevel 1 (
  echo.
  echo SolarNet background heartbeat installation failed.
  pause
)
endlocal
