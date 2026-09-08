@echo off
setlocal
title SolarNet Employee Device Agent Launcher
set "AGENT_SCRIPT=%~dp0SolarNetDeviceAgent.ps1"

if not exist "%AGENT_SCRIPT%" (
  for /r "%~dp0" %%F in (SolarNetDeviceAgent.ps1) do set "AGENT_SCRIPT=%%~fF"
)

if not exist "%AGENT_SCRIPT%" (
  echo ERROR: SolarNetDeviceAgent.ps1 was not found anywhere in this folder.
  echo.
  echo The ZIP contains three files:
  echo   README.txt
  echo   SolarNetDeviceAgent.ps1
  echo   Start SolarNet Agent.cmd
  echo.
  echo Right-click the ZIP, choose Extract All, and run this launcher from the
  echo extracted folder. Do not copy or run only the CMD file.
  echo.
  echo If SolarNetDeviceAgent.ps1 disappears after extraction, open Windows
  echo Security, Protection history, and check whether it was quarantined.
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
