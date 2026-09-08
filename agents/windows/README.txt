SolarNet Employee Device Agent 1.4.2

Before extracting, right-click the downloaded ZIP, choose Properties, select
Unblock if that option appears, and click Apply. Then extract every file into
one folder. Do not run the launcher from inside the ZIP preview.

Create a one-time code in Device Controller, open Start SolarNet Agent.cmd on
the selected company laptop, review the disclosure, give consent, and enter the
code. After enrollment it starts automatically with Windows. Closing the window
keeps the agent in the Windows notification area and sends one heartbeat per
minute. Double-click its shield icon to reopen it, or use Exit from its tray menu.
The enrolled agent copies itself into the current employee's local application
data so moving or deleting the downloaded ZIP does not break Windows startup.

This portable release does not capture the screen, inspect files, record input,
or execute arbitrary commands. Its notification-area icon remains available.
Administrator messages are shown by the visible agent. Source is included.

For heartbeat whenever Windows is powered on, first enroll the visible agent,
then run "Install Background Heartbeat.cmd" once and approve Administrator access.
It installs a startup task under Windows SYSTEM. This component reports heartbeat
and posture and accepts only the fixed lock and restart commands authorized by a
Super Administrator with password re-verification, exact device confirmation,
reason, and server audit. It cannot run arbitrary commands.
Its latest secret-free health result is stored at
C:\ProgramData\SolarNetDeviceAgent\heartbeat-health.log for troubleshooting.

The agent reports Windows Defender, real-time protection, firewall, BitLocker,
Secure Boot, pending-restart, and antivirus-signature posture when Windows makes
those values available. It does not send browsing history or document contents.
