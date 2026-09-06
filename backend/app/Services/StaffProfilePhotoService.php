<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StaffProfilePhotoService
{
    public function replace(User $user, UploadedFile $photo): User
    {
        $extension = match ($photo->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new \InvalidArgumentException('Unsupported profile photo format.'),
        };

        $path = $photo->storeAs('staff-profile-photos', Str::uuid().'.'.$extension, 'public');
        if (! $path) {
            throw new \RuntimeException('The profile picture could not be stored.');
        }
        $previousPath = $user->profile_photo_path;

        $user->forceFill(['profile_photo_path' => $path])->save();
        if ($previousPath && $previousPath !== $path) {
            Storage::disk('public')->delete($previousPath);
        }

        return $user->refresh();
    }

    public function remove(User $user): User
    {
        $previousPath = $user->profile_photo_path;
        $user->forceFill(['profile_photo_path' => null])->save();
        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }

        return $user->refresh();
    }
}
