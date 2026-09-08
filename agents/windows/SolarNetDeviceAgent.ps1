param([switch]$Background)
$createdNew=$false;$agentMutex=New-Object Threading.Mutex($true,'Local\SolarNetDeviceAgent',[ref]$createdNew);if(-not $createdNew){exit 0}
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$ErrorActionPreference='Stop'; $AgentVersion='1.4.4'; $ApiBase='https://billing.solarnetportal.com/api/v1'
$DataDir=Join-Path $env:LOCALAPPDATA 'SolarNetDeviceAgent'; $IdentityFile=Join-Path $DataDir 'installation-id.txt'; $TokenFile=Join-Path $DataDir 'device-token.dat'; $ScreenLockFile=Join-Path $DataDir 'screen-lock.json'
New-Item -ItemType Directory -Path $DataDir -Force|Out-Null
if(-not(Test-Path $IdentityFile)){[guid]::NewGuid().ToString()|Set-Content $IdentityFile -Encoding ASCII}
function Save-Token([string]$value){ConvertTo-SecureString $value -AsPlainText -Force|ConvertFrom-SecureString|Set-Content $TokenFile -Encoding ASCII}
function Read-Token{if(-not(Test-Path $TokenFile)){return $null};$s=Get-Content $TokenFile|ConvertTo-SecureString;$p=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s);try{[Runtime.InteropServices.Marshal]::PtrToStringBSTR($p)}finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($p)}}
function Install-AgentCopy{$installed=Join-Path $DataDir 'SolarNetDeviceAgent.ps1';if([IO.Path]::GetFullPath($PSCommandPath) -ne [IO.Path]::GetFullPath($installed)){Copy-Item -LiteralPath $PSCommandPath -Destination $installed -Force};return $installed}
function Enable-Autostart{
 $installed=Install-AgentCopy;$arguments='-NoLogo -NoProfile -WindowStyle Hidden -STA -ExecutionPolicy Bypass -File "'+$installed+'" -Background';$command='powershell.exe '+$arguments
 New-ItemProperty -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'SolarNetDeviceAgent' -Value $command -PropertyType String -Force|Out-Null
 try{
  $taskName='SolarNet Device Tray Agent';$account=[Security.Principal.WindowsIdentity]::GetCurrent().Name
  $action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments
  $logon=New-ScheduledTaskTrigger -AtLogOn -User $account
  $watchdog=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration (New-TimeSpan -Days 3650)
  $settings=New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew
  $principal=New-ScheduledTaskPrincipal -UserId $account -LogonType Interactive -RunLevel Limited
  if(-not(Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue)){Register-ScheduledTask -TaskName $taskName -Action $action -Trigger @($logon,$watchdog) -Settings $settings -Principal $principal -Force|Out-Null}
 }catch{New-ItemProperty -Path 'HKCU:\Software\SolarNetDeviceAgent' -Name 'ScheduledTaskError' -Value $_.Exception.Message -PropertyType String -Force|Out-Null}
}
function Get-SecurityPosture{
 $result=[ordered]@{firewall_enabled=$null;defender_enabled=$null;realtime_protection_enabled=$null;bitlocker_enabled=$null;secure_boot_enabled=$null;pending_reboot=$false;antivirus_signature_updated_at=$null}
 try{$profiles=@(Get-NetFirewallProfile -ErrorAction Stop);$result.firewall_enabled=($profiles.Count -gt 0 -and @($profiles|Where-Object{-not $_.Enabled}).Count -eq 0)}catch{}
 try{$mp=Get-MpComputerStatus -ErrorAction Stop;$result.defender_enabled=[bool]$mp.AntivirusEnabled;$result.realtime_protection_enabled=[bool]$mp.RealTimeProtectionEnabled;if($mp.AntivirusSignatureLastUpdated){$result.antivirus_signature_updated_at=$mp.AntivirusSignatureLastUpdated.ToUniversalTime().ToString('o')}}catch{}
 try{$volume=Get-BitLockerVolume -MountPoint $env:SystemDrive -ErrorAction Stop;$protection=[string]$volume.ProtectionStatus;if($protection -in @('On','1')){$result.bitlocker_enabled=$true}elseif($protection -in @('Off','0')){$result.bitlocker_enabled=$false}}catch{}
 if($null -eq $result.bitlocker_enabled){try{$manageBde=(& "$env:SystemRoot\System32\manage-bde.exe" -status $env:SystemDrive 2>$null|Out-String);if($manageBde -match '(?im)^\s*Protection Status:\s*Protection On\s*$'){$result.bitlocker_enabled=$true}elseif($manageBde -match '(?im)^\s*Protection Status:\s*Protection Off\s*$'){$result.bitlocker_enabled=$false}}catch{}}
 try{$result.secure_boot_enabled=[bool](Confirm-SecureBootUEFI -ErrorAction Stop)}catch{
  try{$secureBootState=Get-ItemPropertyValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\SecureBoot\State' -Name 'UEFISecureBootEnabled' -ErrorAction Stop;$result.secure_boot_enabled=([int]$secureBootState -eq 1)}catch{}
 }
 try{$result.pending_reboot=(Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired')}catch{}
 return $result
}
function Invoke-Api($path,$body,$token=''){$headers=@{Accept='application/json'};if($token){$headers.Authorization="Bearer $token"};Invoke-RestMethod -Method Post -Uri "$ApiBase/$path" -Headers $headers -ContentType 'application/json' -Body($body|ConvertTo-Json -Depth 4)-TimeoutSec 15}
function Send-CommandResult($token,$commandId,$result,$message){Invoke-Api 'employee-device-agent/command-result' @{command_id=$commandId;status=$result;result_message=$message} $token|Out-Null}
function Get-Sha256([string]$value){$sha=[Security.Cryptography.SHA256]::Create();try{([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($value)))).Replace('-','').ToLowerInvariant()}finally{$sha.Dispose()}}
function Show-SolarNetLock($token,$command){
 $command|ConvertTo-Json -Depth 4|Set-Content -LiteralPath $ScreenLockFile -Encoding UTF8
 $proof=$command.message|ConvertFrom-Json;$lock=New-Object Windows.Forms.Form;$lock.FormBorderStyle='None';$lock.WindowState='Maximized';$lock.TopMost=$true;$lock.BackColor=[Drawing.Color]::FromArgb(2,8,23);$lock.ForeColor=[Drawing.Color]::White;$lock.KeyPreview=$true
 $title=New-Object Windows.Forms.Label;$title.Text='YOUR DEVICE IS LOCKED';$title.Font=New-Object Drawing.Font('Segoe UI',30,[Drawing.FontStyle]::Bold);$title.AutoSize=$true;$title.Location=New-Object Drawing.Point(70,90);$lock.Controls.Add($title)
 $notice=New-Object Windows.Forms.Label;$notice.Text="SolarNet company device control`r`nReason: $($command.reason)`r`nContact your Super Administrator for the unlock code.";$notice.Font=New-Object Drawing.Font('Segoe UI',16);$notice.Size=New-Object Drawing.Size(950,130);$notice.Location=New-Object Drawing.Point(75,170);$lock.Controls.Add($notice)
 $box=New-Object Windows.Forms.TextBox;$box.UseSystemPasswordChar=$true;$box.Font=New-Object Drawing.Font('Segoe UI',16);$box.Size=New-Object Drawing.Size(430,40);$box.Location=New-Object Drawing.Point(75,320);$lock.Controls.Add($box)
 $button=New-Object Windows.Forms.Button;$button.Text='Unlock';$button.Size=New-Object Drawing.Size(160,42);$button.Location=New-Object Drawing.Point(520,320);$lock.Controls.Add($button)
 $errorLabel=New-Object Windows.Forms.Label;$errorLabel.ForeColor=[Drawing.Color]::OrangeRed;$errorLabel.Size=New-Object Drawing.Size(650,35);$errorLabel.Location=New-Object Drawing.Point(75,380);$lock.Controls.Add($errorLabel)
 $script:screenUnlocked=$false;$lock.Add_FormClosing({param($s,$e);if(-not $script:screenUnlocked){$e.Cancel=$true}});$lock.Add_KeyDown({param($s,$e);if($e.Alt -and $e.KeyCode -eq 'F4'){$e.SuppressKeyPress=$true}})
 $unlock={if((Get-Sha256 ($proof.salt+$box.Text)) -eq $proof.digest){$script:screenUnlocked=$true;$lock.Close()}else{$box.Clear();$errorLabel.Text='Incorrect unlock code.'}};$button.Add_Click($unlock);$box.Add_KeyDown({param($s,$e);if($e.KeyCode -eq 'Enter'){&$unlock}})
 [void]$lock.ShowDialog();Remove-Item -LiteralPath $ScreenLockFile -Force -ErrorAction SilentlyContinue;Send-CommandResult $token $command.id 'completed' 'SolarNet screen lock was unlocked.'
}
function Invoke-DeviceCommand($token,$command){
 try{$reason=if([string]::IsNullOrWhiteSpace([string]$command.reason)){'No reason supplied'}else{[string]$command.reason}
  if($command.command -eq 'message'){[Windows.Forms.MessageBox]::Show("$($command.message)`r`n`r`nReason: $reason",'SolarNet administrator message',[Windows.Forms.MessageBoxButtons]::OK,[Windows.Forms.MessageBoxIcon]::Information)|Out-Null;Send-CommandResult $token $command.id 'completed' 'Message displayed and acknowledged.';return}
  if($command.command -eq 'screen_lock'){Show-SolarNetLock $token $command;return}
  if($command.command -eq 'lock'){$answer=[Windows.Forms.MessageBox]::Show("A SolarNet administrator requests that this computer be locked.`r`n`r`nReason: $reason`r`n`r`nLock now?",'SolarNet device action request',[Windows.Forms.MessageBoxButtons]::YesNo,[Windows.Forms.MessageBoxIcon]::Question);if($answer -ne [Windows.Forms.DialogResult]::Yes){Send-CommandResult $token $command.id 'declined' 'Employee declined the lock request.';return};Start-Process -FilePath 'rundll32.exe' -ArgumentList 'user32.dll,LockWorkStation' -WindowStyle Hidden;Send-CommandResult $token $command.id 'completed' 'Employee approved; workstation lock was requested.';return}
  if($command.command -eq 'restart'){$answer=[Windows.Forms.MessageBox]::Show("A SolarNet administrator requests a Windows restart in 60 seconds.`r`n`r`nReason: $reason`r`n`r`nApprove restart?",'SolarNet device action request',[Windows.Forms.MessageBoxButtons]::YesNo,[Windows.Forms.MessageBoxIcon]::Warning);if($answer -ne [Windows.Forms.DialogResult]::Yes){Send-CommandResult $token $command.id 'declined' 'Employee declined the restart request.';return};Start-Process -FilePath 'shutdown.exe' -ArgumentList '/r','/t','60','/c','SolarNet approved support restart' -WindowStyle Hidden;Send-CommandResult $token $command.id 'completed' 'Employee approved; restart scheduled in 60 seconds.';return}
  Send-CommandResult $token $command.id 'failed' 'Unsupported command received.'
 }catch{try{Send-CommandResult $token $command.id 'failed' $_.Exception.Message}catch{}}
}
$form=New-Object Windows.Forms.Form;$form.Text='SolarNet Employee Device Agent';$form.Size=New-Object Drawing.Size(520,510);$form.StartPosition='CenterScreen';$form.BackColor=[Drawing.Color]::FromArgb(5,15,32);$form.ForeColor=[Drawing.Color]::White;$form.Font=New-Object Drawing.Font('Segoe UI',10);$form.FormBorderStyle='FixedDialog';$form.MaximizeBox=$false
$allowExit=$false;$tray=New-Object Windows.Forms.NotifyIcon;$tray.Icon=[Drawing.SystemIcons]::Shield;$tray.Text='SolarNet Employee Device Agent';$tray.Visible=$true
$trayMenu=New-Object Windows.Forms.ContextMenuStrip;$openItem=$trayMenu.Items.Add('Open SolarNet Agent');$exitItem=$trayMenu.Items.Add('Exit SolarNet Agent');$tray.ContextMenuStrip=$trayMenu
$openAgent={$form.Show();$form.WindowState='Normal';$form.Activate()};$openItem.Add_Click($openAgent);$tray.Add_DoubleClick($openAgent)
$exitItem.Add_Click({$script:allowExit=$true;$timer.Stop();$tray.Visible=$false;$form.Close()})
$form.Add_FormClosing({param($sender,$eventArgs);if(-not $script:allowExit){$eventArgs.Cancel=$true;$form.Hide();$tray.ShowBalloonTip(2500,'SolarNet Agent','Still running in the notification area and sending secure heartbeats.',[Windows.Forms.ToolTipIcon]::Info)}})
function Label($text,$x,$y,$w=450,$h=24,$bold=$false){$v=New-Object Windows.Forms.Label;$v.Text=$text;$v.Location=New-Object Drawing.Point($x,$y);$v.Size=New-Object Drawing.Size($w,$h);if($bold){$v.Font=New-Object Drawing.Font('Segoe UI',11,[Drawing.FontStyle]::Bold)};$form.Controls.Add($v);$v}
Label 'SOLARNET EMPLOYEE DEVICE AGENT' 28 24 450 30 $true|Out-Null;Label 'Optional heartbeat agent for selected company devices' 28 55|Out-Null;Label 'Employee name' 28 98|Out-Null
$employee=New-Object Windows.Forms.TextBox;$employee.Location=New-Object Drawing.Point(28,124);$employee.Size=New-Object Drawing.Size(445,28);$form.Controls.Add($employee)
Label 'One-time enrollment code' 28 165|Out-Null;$code=New-Object Windows.Forms.TextBox;$code.Location=New-Object Drawing.Point(28,191);$code.Size=New-Object Drawing.Size(445,28);$code.CharacterCasing='Upper';$form.Controls.Add($code)
$consent=New-Object Windows.Forms.CheckBox;$consent.Location=New-Object Drawing.Point(28,237);$consent.Size=New-Object Drawing.Size(445,55);$consent.Text='I understand this app sends device name, Windows version, agent version, online heartbeat, and a random installation ID to SolarNet.';$form.Controls.Add($consent)
$enroll=New-Object Windows.Forms.Button;$enroll.Text='Enroll this device';$enroll.Location=New-Object Drawing.Point(28,307);$enroll.Size=New-Object Drawing.Size(445,40);$enroll.BackColor=[Drawing.Color]::FromArgb(24,169,153);$enroll.FlatStyle='Flat';$form.Controls.Add($enroll)
$status=Label 'Status: Not enrolled' 28 369 445 28 $true;Label 'The application stays visible. No screen, file, keyboard, camera, microphone, or location data is collected.' 28 404 445 50|Out-Null
$timer=New-Object Windows.Forms.Timer;$timer.Interval=60000
$postureCache=$null;$lastPostureAt=[datetime]::MinValue
$heartbeat={ $token=Read-Token;if(-not $token){$status.Text='Status: Not enrolled';return};try{if(-not $script:postureCache -or ((Get-Date)-$script:lastPostureAt).TotalMinutes -ge 5){$script:postureCache=Get-SecurityPosture;$script:lastPostureAt=Get-Date};$response=Invoke-Api 'employee-device-agent/heartbeat' @{agent_version=$AgentVersion;agent_component='tray';command_capabilities=@('message','screen_lock');os_version=[Environment]::OSVersion.VersionString;security_posture=$script:postureCache} $token;foreach($command in @($response.data.commands)){Invoke-DeviceCommand $token $command};$timer.Interval=60000;$status.Text="Status: Online - heartbeat $(Get-Date -Format 'h:mm:ss tt')";$status.ForeColor=[Drawing.Color]::LightGreen}catch{$timer.Interval=15000;$status.Text='Status: Reconnecting to server...';$status.ForeColor=[Drawing.Color]::Orange}}
$timer.Add_Tick($heartbeat)
$enroll.Add_Click({if(-not $consent.Checked){[Windows.Forms.MessageBox]::Show('Review and accept the disclosed heartbeat data first.','Consent required')|Out-Null;return};if([string]::IsNullOrWhiteSpace($employee.Text)-or[string]::IsNullOrWhiteSpace($code.Text)){[Windows.Forms.MessageBox]::Show('Enter the employee name and one-time code.','Missing information')|Out-Null;return};$enroll.Enabled=$false;$status.Text='Status: Enrolling...';try{$r=Invoke-Api 'employee-device-agent/enroll' @{code=$code.Text.Trim();name=$env:COMPUTERNAME;employee_name=$employee.Text.Trim();platform='windows';os_version=[Environment]::OSVersion.VersionString;agent_version=$AgentVersion;device_fingerprint=(Get-Content $IdentityFile -Raw).Trim();consent_accepted=$true;consent_accepted_at=(Get-Date).ToUniversalTime().ToString('o')};Save-Token $r.data.device_token;Enable-Autostart;$code.Clear();$employee.Enabled=$false;$consent.Enabled=$false;$enroll.Text='Enrolled';&$heartbeat;$timer.Start()}catch{$status.Text='Status: Enrollment failed';[Windows.Forms.MessageBox]::Show($_.Exception.Message,'Enrollment failed')|Out-Null;$enroll.Enabled=$true}})
if(Read-Token){Enable-Autostart;$employee.Enabled=$false;$code.Enabled=$false;$consent.Checked=$true;$consent.Enabled=$false;$enroll.Text='Enrolled';$enroll.Enabled=$false;if(Test-Path $ScreenLockFile){Show-SolarNetLock (Read-Token) ((Get-Content -LiteralPath $ScreenLockFile -Raw)|ConvertFrom-Json)};&$heartbeat;$timer.Start()}
if($Background){$form.Add_Shown({$form.Hide();$tray.ShowBalloonTip(2000,'SolarNet Agent','Background heartbeat is active.',[Windows.Forms.ToolTipIcon]::Info)})}
[void]$form.ShowDialog();$tray.Dispose();$agentMutex.ReleaseMutex();$agentMutex.Dispose()
