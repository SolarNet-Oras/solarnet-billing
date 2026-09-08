$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Security
$principal=New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){
    $arguments=@('-NoLogo','-NoProfile','-ExecutionPolicy','Bypass','-File',('"'+$PSCommandPath+'"'))
    $process=Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $arguments -Wait -PassThru
    exit $process.ExitCode
}
$userDir=Join-Path $env:LOCALAPPDATA 'SolarNetDeviceAgent'
$userToken=Join-Path $userDir 'device-token.dat'
if(-not(Test-Path $userToken)){throw 'Enroll this Windows account in the SolarNet agent before installing the heartbeat service.'}
$taskName='SolarNet Device Heartbeat'
# Register-ScheduledTask -Force does not reliably replace a script that is
# already running. Stop the old instance first so upgrades take effect now.
if(Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue){
    Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    $deadline=(Get-Date).AddSeconds(15)
    do {
        Start-Sleep -Milliseconds 250
        $state=(Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue).State
    } while($state -eq 'Running' -and (Get-Date) -lt $deadline)
    if($state -eq 'Running'){throw 'The previous SolarNet heartbeat did not stop. Restart Windows, then run this installer again.'}
}
$secure=Get-Content -LiteralPath $userToken|ConvertTo-SecureString
$pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try{$token=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)}finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)}
$target=Join-Path $env:ProgramData 'SolarNetDeviceAgent';New-Item -ItemType Directory -Path $target -Force|Out-Null
$plain=[Text.Encoding]::UTF8.GetBytes($token)
try{$protected=[Security.Cryptography.ProtectedData]::Protect($plain,$null,[Security.Cryptography.DataProtectionScope]::LocalMachine);[Convert]::ToBase64String($protected)|Set-Content -LiteralPath (Join-Path $target 'machine-token.dat') -Encoding ASCII}finally{[Array]::Clear($plain,0,$plain.Length);$token=$null}
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'SolarNetHeartbeatService.ps1') -Destination (Join-Path $target 'SolarNetHeartbeatService.ps1') -Force
$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoLogo -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "C:\ProgramData\SolarNetDeviceAgent\SolarNetHeartbeatService.ps1"'
$trigger=New-ScheduledTaskTrigger -AtStartup
$settings=New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$task=New-ScheduledTask -Action $action -Trigger $trigger -Settings $settings -Principal (New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest)
Register-ScheduledTask -TaskName $taskName -InputObject $task -Force|Out-Null
Start-ScheduledTask -TaskName $taskName
Write-Host 'SolarNet machine heartbeat installed and started.' -ForegroundColor Green
Write-Host 'You may close this window. Press Enter to finish.'
[void](Read-Host)
