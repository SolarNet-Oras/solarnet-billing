@echo off
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList '-NoLogo -NoProfile -ExecutionPolicy Bypass -File ""%~dp0Install-SolarNetHeartbeat.ps1""'"
if errorlevel 1 pause
