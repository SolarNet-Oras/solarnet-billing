$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Security
$ApiBase='https://billing.solarnetportal.com/api/v1'
$DataDir=Join-Path $env:ProgramData 'SolarNetDeviceAgent'
$TokenFile=Join-Path $DataDir 'machine-token.dat'
$HealthFile=Join-Path $DataDir 'heartbeat-health.log'
function Write-Health([string]$state,[string]$detail){$safe=($detail -replace '[\r\n]+',' ');if($safe.Length -gt 300){$safe=$safe.Substring(0,300)};Set-Content -LiteralPath $HealthFile -Value ("{0} | {1} | {2}" -f (Get-Date).ToString('o'),$state,$safe) -Encoding UTF8}
function Send-Result($headers,$commandId,$status,$message){
    $body=@{command_id=$commandId;status=$status;result_message=$message}|ConvertTo-Json
    Invoke-RestMethod -Method Post -Uri "$ApiBase/employee-device-agent/command-result" -Headers $headers -ContentType 'application/json' -Body $body -TimeoutSec 20|Out-Null
}
function Invoke-SystemCommand($headers,$command){
    try {
        if($command.command -eq 'restart'){
            & "$env:SystemRoot\System32\shutdown.exe" /r /t 60 /c "SolarNet administrator restart: $($command.reason)" /d p:4:1
            if($LASTEXITCODE -ne 0){throw "Windows rejected the restart command (exit $LASTEXITCODE)."}
            Send-Result $headers $command.id 'completed' 'Restart scheduled in 60 seconds. The local user may save work; shutdown /a can cancel during that window.'
            return
        }
        if($command.command -eq 'lock'){
            $interactive=Get-Process -Name explorer -ErrorAction SilentlyContinue|Where-Object{$_.SessionId -gt 0}|Sort-Object StartTime -Descending|Select-Object -First 1
            if(-not $interactive){throw 'No active interactive Windows session was found.'}
            $sessionId=$interactive.SessionId
            & "$env:SystemRoot\System32\tsdiscon.exe" $sessionId
            if($LASTEXITCODE -ne 0){throw "Windows rejected the lock command (exit $LASTEXITCODE)."}
            Send-Result $headers $command.id 'completed' 'Active Windows session locked.'
            return
        }
        throw 'The privileged service received an unsupported command.'
    } catch {
        try { Send-Result $headers $command.id 'failed' $_.Exception.Message } catch {}
    }
}

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
        $body=@{agent_version='1.5.0-service';agent_component='machine_service';command_capabilities=@('lock','restart');os_version=[Environment]::OSVersion.VersionString;security_posture=(Get-Posture)}|ConvertTo-Json -Depth 4
        $response=Invoke-RestMethod -Method Post -Uri "$ApiBase/employee-device-agent/heartbeat" -Headers $headers -ContentType 'application/json' -Body $body -TimeoutSec 20
        foreach($command in @($response.data.commands)){Invoke-SystemCommand $headers $command}
        Write-Health 'SUCCESS' 'Machine heartbeat and privileged posture accepted by SolarNet.'
        Start-Sleep -Seconds 60
    }catch{Write-Health 'FAILED' $_.Exception.Message;Start-Sleep -Seconds 15}
}
