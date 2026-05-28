<?php

namespace App\Services;

use App\Models\User;
use App\Services\Graph\GraphClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AvatarService
{
    public const ENTRA_AVATAR_TTL_DAYS = 7;

    private const AVATAR_SIZE = 200;
    private const JPEG_QUALITY = 85;

    public function __construct(
        private readonly GraphClient $graph,
    ) {}

    public function uploadAvatar(User $user, UploadedFile $file): string
    {
        $image = $this->resizeToSquare($file->getPathname());

        $path = "avatars/{$user->id}.jpg";
        Storage::disk('public')->put($path, $image);

        // Remove Entra cache if it exists
        $entraPath = "avatars/entra_{$user->id}.jpg";
        if (Storage::disk('public')->exists($entraPath)) {
            Storage::disk('public')->delete($entraPath);
        }

        $user->update([
            'avatar_path' => $path,
            'entra_avatar_fetched_at' => null,
        ]);

        return $path;
    }

    public function deleteAvatar(User $user): void
    {
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->update([
            'avatar_path' => null,
            'entra_avatar_fetched_at' => null,
        ]);
    }

    public function fetchEntraPhoto(User $user): ?string
    {
        if (!$user->microsoft_id) {
            return null;
        }

        // Skip if user has an uploaded avatar
        if ($user->avatar_path) {
            return null;
        }

        // Check TTL
        if ($user->entra_avatar_fetched_at && $user->entra_avatar_fetched_at->diffInDays(now()) < self::ENTRA_AVATAR_TTL_DAYS) {
            return null;
        }

        try {
            $photoData = $this->graph->getRaw("users/{$user->microsoft_id}/photo/\$value");

            if (!$photoData) {
                // User has no photo in Entra — update TTL so we don't retry immediately
                $user->update(['entra_avatar_fetched_at' => now()]);
                return null;
            }

            $image = $this->resizeToSquareFromString($photoData);

            $path = "avatars/entra_{$user->id}.jpg";
            Storage::disk('public')->put($path, $image);

            $user->update(['entra_avatar_fetched_at' => now()]);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('[Avatar] Failed to fetch Entra photo', [
                'user_id' => $user->id,
                'microsoft_id' => $user->microsoft_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resizeToSquare(string $filePath): string
    {
        $source = imagecreatefromstring(file_get_contents($filePath));

        return $this->cropAndEncode($source);
    }

    private function resizeToSquareFromString(string $data): string
    {
        $source = imagecreatefromstring($data);

        return $this->cropAndEncode($source);
    }

    private function cropAndEncode(\GdImage $source): string
    {
        $srcW = imagesx($source);
        $srcH = imagesy($source);

        // Center-crop to square
        $cropSize = min($srcW, $srcH);
        $srcX = (int) (($srcW - $cropSize) / 2);
        $srcY = (int) (($srcH - $cropSize) / 2);

        $dest = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        imagecopyresampled($dest, $source, 0, 0, $srcX, $srcY, self::AVATAR_SIZE, self::AVATAR_SIZE, $cropSize, $cropSize);
        imagedestroy($source);

        ob_start();
        imagejpeg($dest, null, self::JPEG_QUALITY);
        imagedestroy($dest);

        return ob_get_clean();
    }
}
