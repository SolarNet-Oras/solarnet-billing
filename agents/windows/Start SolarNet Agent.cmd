@echo off
setlocal
title SolarNet Employee Device Agent Launcher
set "AGENT_SCRIPT=%~dp0SolarNetDeviceAgent.ps1"

if not exist "%AGENT_SCRIPT%" (
  echo ERROR: SolarNetDeviceAgent.ps1 was not found.
  echo Extract every file from the ZIP into one folder before opening this launcher.
  pause
  exit /b 1
)

where powershell.exe >nul 2>&1
if errorlevel 1 (
  echo ERROR: Windows PowerShell is unavailable on this computer.
  pause
  exit /b 1
)

powershell.exe -NoLogo -NoProfile -STA -ExecutionPolicy Bypass -File "%AGENT_SCRIPT%"
if errorlevel 1 (
  echo.
  echo SolarNet Agent could not start. Please photograph or copy the error shown above.
  echo You may also right-click the downloaded ZIP, choose Properties, select Unblock,
  echo click Apply, and then extract the ZIP again.
  pause
  exit /b 1
)
endlocal
