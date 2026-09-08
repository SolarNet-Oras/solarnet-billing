$ErrorActionPreference='Stop'
$ApiBase='https://billing.solarnetportal.com/api/v1'
$DataDir=Join-Path $env:ProgramData 'SolarNetDeviceAgent'
$TokenFile=Join-Path $DataDir 'machine-token.dat'
$HealthFile=Join-Path $DataDir 'heartbeat-health.log'
function Write-Health([string]$state,[string]$detail){$safe=($detail -replace '[\r\n]+',' ');if($safe.Length -gt 300){$safe=$safe.Substring(0,300)};Set-Content -LiteralPath $HealthFile -Value ("{0} | {1} | {2}" -f (Get-Date).ToString('o'),$state,$safe) -Encoding UTF8}

function Read-MachineToken {
    $encrypted=[Convert]::FromBase64String((Get-Content -LiteralPath $TokenFile -Raw).Trim())
    $plain=[Security.Cryptography.ProtectedData]::Unprotect($encrypted,$null,[Security.Cryptography.DataProtectionScope]::LocalMachine)
    try { [Text.Encoding]::UTF8.GetString($plain) } finally { [Array]::Clear($plain,0,$plain.Length) }
}
function Get-Posture {
    $result=[ordered]@{firewall_enabled=$null;defender_enabled=$null;realtime_protection_enabled=$null;bitlocker_enabled=$null;secure_boot_enabled=$null;pending_reboot=$false;antivirus_signature_updated_at=$null}
    try{$profiles=@(Get-NetFirewallProfile -ErrorAction Stop);$result.firewall_enabled=($profiles.Count -gt 0 -and @($profiles|Where-Object{-not $_.Enabled}).Count -eq 0)}catch{}
    try{$mp=Get-MpComputerStatus -ErrorAction Stop;$result.defender_enabled=[bool]$mp.AntivirusEnabled;$result.realtime_protection_enabled=[bool]$mp.RealTimeProtectionEnabled;if($mp.AntivirusSignatureLastUpdated){$result.antivirus_signature_updated_at=$mp.AntivirusSignatureLastUpdated.ToUniversalTime().ToString('o')}}catch{}
    try{$volume=Get-BitLockerVolume -MountPoint $env:SystemDrive -ErrorAction Stop;$state=[string]$volume.ProtectionStatus;if($state -in @('On','1')){$result.bitlocker_enabled=$true}elseif($state -in @('Off','0')){$result.bitlocker_enabled=$false}}catch{}
    try{$result.secure_boot_enabled=[bool](Confirm-SecureBootUEFI -ErrorAction Stop)}catch{try{$result.secure_boot_enabled=([int](Get-ItemPropertyValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\SecureBoot\State' -Name UEFISecureBootEnabled -ErrorAction Stop)-eq 1)}catch{}}
    $result.pending_reboot=Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
    return $result
}

while($true){
    try{
        $token=Read-MachineToken
        $headers=@{Accept='application/json';Authorization="Bearer $token"}
        $body=@{agent_version='1.5.0-service';os_version=[Environment]::OSVersion.VersionString;accept_commands=$false;security_posture=(Get-Posture)}|ConvertTo-Json -Depth 4
        Invoke-RestMethod -Method Post -Uri "$ApiBase/employee-device-agent/heartbeat" -Headers $headers -ContentType 'application/json' -Body $body -TimeoutSec 20|Out-Null
        Write-Health 'SUCCESS' 'Machine heartbeat and privileged posture accepted by SolarNet.'
        Start-Sleep -Seconds 60
    }catch{Write-Health 'FAILED' $_.Exception.Message;Start-Sleep -Seconds 15}
}
