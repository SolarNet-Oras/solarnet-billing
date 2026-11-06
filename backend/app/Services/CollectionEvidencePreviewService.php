<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class CollectionEvidencePreviewService
{
    public function previewPath(string $visitId): string
    {
        return 'collection-visit-previews/'.$visitId.'.jpg';
    }

    public function create(string $visitId, string $sourcePath): string
    {
        $disk=Storage::disk('local');
        $previewPath=$this->previewPath($visitId);
        if ($disk->exists($previewPath)) return $previewPath;
        if (!function_exists('imagecreatefromstring')) return $sourcePath;

        try {
            $sourceBytes=$disk->get($sourcePath);
            $size=@getimagesizefromstring($sourceBytes);
            if (!$size || $size[0] < 1 || $size[1] < 1) return $sourcePath;

            $source=@imagecreatefromstring($sourceBytes);
            if (!$source) return $sourcePath;

            $maxDimension=1280;
            $scale=min(1,$maxDimension/max($size[0],$size[1]));
            $width=max(1,(int)round($size[0]*$scale));
            $height=max(1,(int)round($size[1]*$scale));
            $preview=imagecreatetruecolor($width,$height);
            $background=imagecolorallocate($preview,255,255,255);
            imagefill($preview,0,0,$background);
            imagecopyresampled($preview,$source,0,0,0,0,$width,$height,$size[0],$size[1]);

            $absolutePath=$disk->path($previewPath);
            $directory=dirname($absolutePath);
            if (!is_dir($directory)) mkdir($directory,0750,true);
            $temporaryPath=$absolutePath.'.tmp-'.bin2hex(random_bytes(4));
            $created=imagejpeg($preview,$temporaryPath,76);
            imagedestroy($preview);
            imagedestroy($source);
            if (!$created) return $sourcePath;
            rename($temporaryPath,$absolutePath);

            return $previewPath;
        } catch (\Throwable $exception) {
            report($exception);
            return $sourcePath;
        }
    }

    public function delete(string $visitId): void
    {
        Storage::disk('local')->delete($this->previewPath($visitId));
    }
}
