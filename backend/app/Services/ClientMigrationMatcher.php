<?php
namespace App\Services;
use App\Models\DhcpLease;
class ClientMigrationMatcher {
 public function normalizeMac(?string $mac): ?string { $hex=strtoupper(preg_replace('/[^A-Fa-f0-9]/','',(string)$mac)); return strlen($hex)===12 ? implode(':',str_split($hex,2)) : null; }
 public function find(string $excelMac): array { $mac=$this->normalizeMac($excelMac); if (!$mac) return ['status'=>'INVALID MAC ADDRESS','lease'=>null,'candidates'=>[]]; $leases=DhcpLease::query()->where('is_current',true)->get(); $exact=$leases->first(fn($l)=>$this->normalizeMac($l->mac_address)===$mac); if($exact) return ['status'=>'EXACT MAC MATCH','lease'=>$exact,'candidates'=>[],'requires_confirmation'=>false]; $prefix=substr(str_replace(':','',$mac),0,-1); $candidates=$leases->filter(fn($l)=>str_starts_with(str_replace(':','',$this->normalizeMac($l->mac_address)??''),$prefix))->values(); return match($candidates->count()){0=>['status'=>'LEASE NOT FOUND','lease'=>null,'candidates'=>[],'requires_confirmation'=>false],1=>['status'=>'PARTIAL MAC MATCH','lease'=>$candidates->first(),'candidates'=>$candidates,'requires_confirmation'=>true],default=>['status'=>'AMBIGUOUS PARTIAL MAC MATCH','lease'=>null,'candidates'=>$candidates,'requires_confirmation'=>true]}; }
}
